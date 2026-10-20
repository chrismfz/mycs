<?php

namespace App\Support\Tickets;

use App\Models\Ticket;
use App\Models\TicketGuestLink;

/**
 * A guest's way to their ticket (docs/tickets.md §3 Δ, PR Γ): a link with a random token of its own (256 bits, kept only
 * hashed), mailed to the ticket's address only, good for DAYS days; a new one in every mail. Never an «enter your email»:
 * whoever has the link is whoever reads that inbox. All of a ticket's links go when staff link it to an account, change
 * its address, or revoke them; a link of a ticket no longer a guest's leads nowhere.
 */
final class GuestLinks
{
    public const DAYS = 30;

    /** A new link (its URL, with the token): only for a guest's ticket. */
    public static function issue(Ticket $ticket): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        TicketGuestLink::query()->create(['ticket_id' => $ticket->id, 'token_hash' => self::hash($token), 'expires_at' => now()->addDays(self::DAYS)]);

        return route('tickets.guest', ['token' => $token]);
    }

    /** The link of a token, when it is good: not expired, not revoked, its ticket still a guest's. */
    public static function find(string $token): ?TicketGuestLink
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $link = TicketGuestLink::query()->where('token_hash', self::hash($token))->with('ticket')->first();

        return $link !== null && $link->revoked_at === null && $link->expires_at->isFuture() && $link->ticket?->isGuest() ? $link : null;
    }

    /** The link of a token as it is, good or not (to offer a new one when it has expired). */
    public static function any(string $token): ?TicketGuestLink
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1
            ? TicketGuestLink::query()->where('token_hash', self::hash($token))->with('ticket')->first() : null;
    }

    public static function revoke(Ticket $ticket): void
    {
        TicketGuestLink::query()->where('ticket_id', $ticket->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
