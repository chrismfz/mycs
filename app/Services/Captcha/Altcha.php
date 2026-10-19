<?php

namespace App\Services\Captcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLibrary;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Our own captcha (docs/captcha.md): ALTCHA, a proof of work the browser solves and we check, with the official library
 * and nothing sent anywhere else (no Sentinel, no API). The keys come from APP_KEY, so there is nothing to configure or
 * keep secret besides it; a new APP_KEY only voids the challenges still open. A solution is accepted once.
 */
class Altcha
{
    /**
     * PBKDF2 iterations per try and the range of the hidden counter: the work is about cost × counter. «normal» is the
     * library's own example; «low» for old phones, «high» under attack.
     *
     * @var array<string, array{cost: int, counter: array{int, int}}>
     */
    public const DIFFICULTIES = [
        'low' => ['cost' => 1000, 'counter' => [2000, 5000]],
        'normal' => ['cost' => 5000, 'counter' => [5000, 10000]],
        'high' => ['cost' => 10000, 'counter' => [10000, 20000]],
    ];

    /** How long a challenge can be solved and posted, in minutes, when the setting is empty or off. */
    public const DEFAULT_MINUTES = 10;

    /** @return array<string, mixed> the challenge, as the widget reads it */
    public function challenge(): array
    {
        $difficulty = $this->difficulty();

        return $this->library()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2,
            cost: $difficulty['cost'],
            counter: random_int(...$difficulty['counter']),
            expiresAt: now()->addMinutes($this->minutes())->getTimestamp(),
        ))->toArray();
    }

    /**
     * The widget's payload (base64) is a solution of a challenge we signed, not expired, and not used before. Anything
     * else (empty, malformed, tampered, replayed) is false, never an exception.
     */
    public function verify(string $payload): bool
    {
        if ($payload === '' || strlen($payload) > 8192) {
            return false;
        }
        try {
            $result = $this->library()->verifySolution(new VerifySolutionOptions(algorithm: new Pbkdf2, payload: $payload));
        } catch (Throwable) {
            return false;
        }
        if (!$result->verified) {
            return false;
        }

        // Single use: the first request to record it wins, also when two arrive together. Kept as long as it could
        // still be posted.
        return Cache::add('altcha:spent:' . hash('sha256', $payload), true, now()->addMinutes($this->minutes() + 1));
    }

    /** @return array{cost: int, counter: array{int, int}} the chosen difficulty («normal» when not set) */
    protected function difficulty(): array
    {
        return self::DIFFICULTIES[(string) config('settings.altcha_difficulty')] ?? self::DIFFICULTIES['normal'];
    }

    private function minutes(): int
    {
        $minutes = (int) config('settings.altcha_minutes');

        return $minutes > 0 ? min($minutes, 60) : self::DEFAULT_MINUTES;
    }

    private function library(): AltchaLibrary
    {
        return new AltchaLibrary(
            hmacSignatureSecret: $this->key('signature'),
            // Lets a solution be checked without doing the work again (cheap to verify, not to solve).
            hmacKeySignatureSecret: $this->key('key-signature'),
        );
    }

    /** A key of its own for each use, derived from APP_KEY, never APP_KEY itself. */
    private function key(string $use): string
    {
        return hash_hmac('sha256', 'altcha:' . $use, (string) config('app.key'));
    }
}
