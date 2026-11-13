<?php

namespace App\Models;

use App\Observers\TicketMessageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use MyCS\TicketMail\InboundEmail;
use MyCS\TicketMail\MailHeaders;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int|null $staff_id
 */
#[ObservedBy([TicketMessageObserver::class])]
class TicketMessage extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    /**
     * The message itself is the ticket's, kept with it: an audit row copying every body (a mail's original HTML too,
     * past the audit's 64 KB column) would only grow the audit log. Who did what still shows.
     *
     * @var list<string>
     */
    protected $auditExclude = ['message', 'body_original', 'mail'];

    protected $fillable = [
        'ticket_id',
        'user_id',
        'staff_id',
        'message',
        'email_message_id',
        'via',
        'is_internal',
        'sender_email',
        'body_original',
        'mail',
    ];

    /** Came in by email (docs/tickets.md A2): its email_message_id is the sender's, never a thread key. */
    public const VIA_EMAIL = 'email';

    /**
     * The most a message typed in a form may hold, in characters: 15,000 even of 4-byte characters stays under the
     * 60,000 bytes the mail paths keep, inside the TEXT column (65,535 bytes) MariaDB refuses to overflow.
     */
    public const MAX_LENGTH = 15_000;

    protected $casts = [
        'is_internal' => 'boolean',
        'mail' => 'array',
    ];

    /**
     * What staff read of the mail a message came by (owner 2026-11-13): From, the server our server took it from (the
     * topmost Received, our own line), whether our server vouched for the From and why, and the header block. Never
     * shown to the customer.
     *
     * Never blocks the mail: details that cannot be stored are left out (adversarial review of #846). $vouched null: our
     * server's check never ran on it (a mail quarantined before it, review 3 of #846).
     *
     * @return array{from: string, server: string|null, vouched: bool|null, reason: string, headers: string}|null
     */
    public static function mailDetails(InboundEmail $email, string $raw, ?bool $vouched, string $reason): ?array
    {
        $from = $email->fromName !== null && $email->fromName !== '' ? $email->fromName . ' <' . $email->fromEmail . '>' : $email->fromEmail;
        $details = [
            'from' => mb_substr(mb_scrub($from, 'UTF-8'), 0, 300),
            'server' => MailHeaders::sendingServer($raw),
            'vouched' => $vouched,
            'reason' => mb_substr(mb_scrub($reason, 'UTF-8'), 0, 80),
            'headers' => MailHeaders::block($raw),
        ];

        return json_encode($details) === false ? null : $details;
    }

    /**
     * The messages a reply may join by (§3 Α): those we mailed, whose Message-ID of ours is in TicketMailId. An id on
     * the message itself is one that came in (the sender's choice), and a note is never mailed: neither is a key.
     *
     * @param  Builder<TicketMessage>  $query
     */
    public function scopeThreadKeys(Builder $query): void
    {
        $query->where('is_internal', false)->whereIn('id', TicketMailId::query()->whereNotNull('ticket_message_id')->select('ticket_message_id'));
    }

    protected static function booted(): void
    {
        // Imported from WHMCS and deleted here: a re-run never brings it back (docs/tickets.md §6ζ).
        static::deleted(function (TicketMessage $message) {
            if ($message->whmcs_ref !== null) {
                DB::table('ticket_whmcs_tombstones')->insertOrIgnore(['ref' => $message->whmcs_ref, 'created_at' => now()]);
            }
        });
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

    /** Who wrote it, as a name to show: the login or staff, else the address it came from (a CC, §6λ), else none. */
    public function authorName(): ?string
    {
        $author = $this->author();

        return $author !== null ? $author->name : $this->sender_email;
    }

    /** @return HasMany<TicketMessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketMessageAttachment::class);
    }
}
