<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Message-ID we gave a mail about a ticket (docs/tickets.md §3 Α): `tk.` and 128 random bits, kept before it is sent
 * and the same on every retry. The only thread keys: a reply joins a ticket by one of these, never by a number, a
 * subject or an id that came in by email.
 */
class TicketMailId extends Model
{
    public const UPDATED_AT = null;

    public const REPLY = 'reply';

    public const RECEIVED = 'received';

    protected $fillable = ['ticket_id', 'ticket_message_id', 'message_id', 'kind', 'recipient'];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
