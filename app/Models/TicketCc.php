<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address staff copied on a ticket (docs/tickets.md §6λ): it gets staff's replies (its own mail, no link to the
 * ticket) and its replies join the ticket. Never one a mail's To or Cc named (§3 Γ).
 */
class TicketCc extends Model
{
    /** At most this many a ticket: each is a mail of ours with every staff reply (review of #267). */
    public const MAX = 20;

    protected $fillable = ['ticket_id', 'email', 'added_by'];

    protected static function booted(): void
    {
        static::saving(fn (TicketCc $cc) => $cc->email = self::normalise((string) $cc->email));
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
