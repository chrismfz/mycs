<?php

namespace App\Models;

use App\Observers\TicketMessageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([TicketMessageObserver::class])]
/**
 * @property int|null $staff_id
 */
class TicketMessage extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'staff_id',
        'message',
        'ticket_mail_log_id',
    ];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** The customer who wrote it; null when staff did. @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The member of staff who wrote it (docs/accounts.md, PR 1). @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** A reply by staff: told by its author, not by comparing ids (staff and customers have their own). */
    public function isFromStaff(): bool
    {
        return $this->staff_id !== null;
    }

    /** Who wrote it, for display: the staff member or the customer. */
    public function author(): Staff|User|null
    {
        /** @var Staff|User|null */
        $author = $this->isFromStaff() ? $this->staff : $this->user;

        return $author;
    }

    /** @return HasMany<TicketMessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketMessageAttachment::class);
    }

    /** @return BelongsTo<TicketMailLog, $this> */
    public function ticketMailLog(): BelongsTo
    {
        return $this->belongsTo(TicketMailLog::class);
    }
}
