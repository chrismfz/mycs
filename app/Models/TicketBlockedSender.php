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

        return str_contains($value, '@') ? $value : '@' . $value;
    }

    public static function blocks(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }

        return self::query()->whereIn('value', array_unique([$email, self::base($email), '@' . substr($email, strrpos($email, '@') + 1)]))->exists();
    }

    /** The mailbox behind an address: without its «+tag» (spammer+1@example.com is spammer@example.com). */
    public static function base(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $plus = strpos($local, '+');

        return ($plus !== false && $plus > 0 ? substr($local, 0, $plus) : $local) . substr($email, $at);
    }
}
