<?php

namespace App\Support\Tickets;

use App\Models\Ticket;
use RuntimeException;

/**
 * The number a customer sees on a ticket (docs/tickets.md §5), in WHMCS's mask syntax, so each company keeps the form
 * its customers know: %n a digit, %A an upper case letter, %a a lower case one, %y the year, %m the month, %d the day
 * (myip «%n%n%n%n%n%n», nixpal «%y-%m-%d-%n%n%n%n%n%n»). Only for display and search: never a key to a ticket or a
 * thread (§3 Α), so it needs no secrecy, only to be unique.
 */
final class TicketReference
{
    public const DEFAULT_MASK = '%n%n%n%n%n%n';

    public static function generate(?string $mask = null): string
    {
        $mask = self::validMask($mask ?? (string) config('settings.ticket_reference_mask')) ? (string) ($mask ?? config('settings.ticket_reference_mask')) : self::DEFAULT_MASK;
        for ($try = 0; $try < 20; $try++) {
            $reference = self::fill($mask);
            if (!Ticket::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        // A mask with too few random places for the tickets it has: longer, never a duplicate.
        throw new RuntimeException('The ticket number mask has run out of numbers: add %n to it.');
    }

    /** Numbers a mask can give in a day: 100.000 at least (five digits, or four letters), so it never runs out. */
    public const MIN_NUMBERS = 100_000;

    /** A mask of at least MIN_NUMBERS random numbers (%n, %A, %a), at most 64 characters once filled. */
    public static function validMask(string $mask): bool
    {
        $numbers = 10 ** preg_match_all('/%n/', $mask) * 26 ** preg_match_all('/%[Aa]/', $mask);

        return $mask !== '' && $numbers >= self::MIN_NUMBERS && strlen(self::fill($mask)) <= 64 && preg_match('/^(?:%[nAaymd]|[A-Za-z0-9_-])+$/', $mask) === 1;
    }

    private static function fill(string $mask): string
    {
        return (string) preg_replace_callback('/%([nAaymd])/', fn (array $m) => match ($m[1]) {
            'n' => (string) random_int(0, 9),
            'A' => chr(random_int(65, 90)),
            'a' => chr(random_int(97, 122)),
            'y' => now()->format('Y'),
            'm' => now()->format('m'),
            'd' => now()->format('d'),
        }, $mask);
    }
}
