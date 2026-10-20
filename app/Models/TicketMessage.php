<?php

namespace App\Models;

use App\Observers\TicketMessageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int|null $staff_id
 */
#[ObservedBy([TicketMessageObserver::class])]
class TicketMessage extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'staff_id',
        'message',
        'email_message_id',
        'via',
        'is_internal',
        'body_original',
    ];

    /** Came in by email (docs/tickets.md A2): its email_message_id is the sender's, never a thread key. */
    public const VIA_EMAIL = 'email';

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    /**
     * The messages whose Message-ID is a thread key (§3 Α): one of ours (`tk.` and 128 random bits), on a message we
     * sent. An id that came in by email is the sender's choice, so it never threads, also when it looks like ours.
     *
     * @param  Builder<TicketMessage>  $query
     */
    public function scopeThreadKeys(Builder $query): void
    {
        $query->where('email_message_id', 'like', 'tk.%')
            ->where(fn (Builder $query) => $query->whereNull('via')->orWhere('via', '!=', self::VIA_EMAIL));
    }

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
}
