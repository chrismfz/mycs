<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

/**
 * A customer's 2FA recovery codes (docs/accounts.md): for a login whose authenticator app is lost. Eight, each «xxxxx-xxxxx»
 * of [a-z0-9] (about 51 bits), shown once when made and kept only as their sha256 (encrypted at rest); each works once.
 * A wrong one counts towards the authenticator codes' own limit (Totp: 5, then 15 minutes), wherever it is typed.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** New codes for this login, replacing any it had: returned once, to be shown. @return list<string> */
    public static function regenerate(User $user): array
    {
        $codes = [];
        while (count($codes) < self::COUNT) {
            $code = self::random(5) . '-' . self::random(5);
            $codes[$code] = true;
        }
        $codes = array_keys($codes);
        $user->forceFill(['tfa_recovery_codes' => array_map(fn (string $code) => self::hash($code), $codes)])->save();

        return $codes;
    }

    public static function remaining(User $user): int
    {
        return count((array) ($user->tfa_recovery_codes ?? []));
    }

    /** Whether $code is one of its unused codes; if so it is used up (once, under the login's row lock). A wrong one counts. */
    public static function consume(User $user, #[SensitiveParameter] string $code): bool
    {
        RateLimiter::hit(Totp::limitKey($user), Totp::LOCKOUT_SECONDS);
        $hash = self::hash($code);

        $used = DB::transaction(function () use ($user, $hash) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($locked === null) {
                return false;
            }
            $hashes = array_values(array_filter((array) $locked->getAttribute('tfa_recovery_codes'), 'is_string'));
            foreach ($hashes as $i => $known) {
                if (hash_equals($known, $hash)) {
                    array_splice($hashes, $i, 1);
                    $locked->forceFill(['tfa_recovery_codes' => $hashes])->save();

                    return true;
                }
            }

            return false;
        });
        if ($used) {
            RateLimiter::clear(Totp::limitKey($user));
            $user->refresh();
        }

        return $used;
    }

    /** Whether what was typed looks like a recovery code rather than the app's six digits. */
    public static function looksLikeOne(string $input): bool
    {
        return (bool) preg_match('/[a-z]|-/i', $input);
    }

    private static function hash(string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code)));
    }

    private static function random(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
