<?php

namespace App\Models;

/**
 * A sender whose mail is dropped (docs/tickets.md §3 Ε): an address, or a whole domain as `@example.com` (that domain
 * only, not its subdomains). No ticket, no reply joined, no quarantine.
 */
class TicketBlockedSender extends Model
{
    protected $fillable = ['value', 'note', 'created_by'];

    protected static function booted(): void
    {
        static::saving(fn (TicketBlockedSender $sender) => $sender->value = self::normalise((string) $sender->value));
    }

    public static function normalise(string $value): string
    {
        $value = strtolower(trim($value));

        // An address as its mailbox (base), a domain as its ASCII form: a block holds however either is spelled.
        if (str_contains(ltrim($value, '@'), '@')) {
            return self::base($value);
        }
        $domain = ltrim($value, '@');

        return '@' . (idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain);
    }

    public static function blocks(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }

        $base = self::base($email);

        return self::query()->whereIn('value', array_unique([$email, $base, '@' . substr($email, strrpos($email, '@') + 1), '@' . substr($base, strrpos($base, '@') + 1)]))->exists();
    }

    /**
     * The mailbox behind an address, however it is spelled (review of #251): lower case, without quotes around the local
     * part, without its «+tag», the domain in ASCII (an IDN's xn-- form), and for Gmail without the dots it ignores.
     * spammer+1@example.com, "spammer"@example.com and s.pammer@gmail.com are spammer@… .
     */
    public static function base(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = trim(substr($email, 0, $at), '"');
        $domain = rtrim(substr($email, $at + 1), '.');
        $domain = (string) (idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain);
        $plus = strpos($local, '+');
        $local = $plus !== false && $plus > 0 ? substr($local, 0, $plus) : $local;
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            [$local, $domain] = [str_replace('.', '', $local), 'gmail.com'];
        }

        return $local . '@' . $domain;
    }
}
