<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link a guest sees their ticket by (docs/tickets.md §3 Δ): its token hashed, its expiry, revoked or not. */
class TicketGuestLink extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'token_hash', 'expires_at', 'revoked_at', 'last_used_at'];

    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
