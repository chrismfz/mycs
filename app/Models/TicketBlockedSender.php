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

        return self::query()->whereIn('value', [$email, '@' . substr($email, strrpos($email, '@') + 1)])->exists();
    }
}
