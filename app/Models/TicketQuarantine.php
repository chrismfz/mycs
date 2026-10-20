<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A mail that did not pass the security model (docs/tickets.md §3): kept for staff with its reason, never put into a
 * ticket on its own. Staff attach it to a ticket, open a new one from it, or dismiss it. Its raw copy is on the private
 * disk (its files are taken from it on «attach» and «open») and goes with the row.
 */
class TicketQuarantine extends Model
{
    protected $table = 'ticket_quarantine';

    public const PENDING = 'pending';

    public const ATTACHED = 'attached';

    public const OPENED = 'opened';

    public const DISMISSED = 'dismissed';

    public const DISK = 'local';

    /** The reasons, for staff (codes are what the router writes). */
    public const REASONS = [
        'automated' => 'Automated mail (auto-reply, bounce, list)',
        'not_owner' => 'A reply to a ticket from someone who is not its owner',
        'not_authenticated' => 'A reply our server did not vouch for (DMARC)',
        'no_thread_key' => 'Mentions a ticket, but is no reply to a mail of ours',
        'own_address' => 'From our own address (a loop)',
        'multiple_from' => 'More than one sender',
        'no_sender' => 'No sender',
        'clients_only' => 'Clients only department, from someone who is not a client',
        'rate_limited' => 'Too many new tickets from this sender',
        'too_large' => 'Too large to fetch',
        'unparseable' => 'Could not be read',
    ];

    protected $fillable = ['department_id', 'reason', 'message_id', 'from_email', 'from_name', 'subject', 'sent_at', 'authenticated',
        'auth_detail', 'preview', 'refused_files', 'raw_path', 'raw_size', 'suggested_ticket_id', 'status', 'ticket_id', 'handled_by', 'handled_at'];

    protected $casts = [
        'sent_at' => 'datetime',
        'handled_at' => 'datetime',
        'authenticated' => 'boolean',
        'refused_files' => 'array',
        'raw_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleted(function (TicketQuarantine $quarantine) {
            if ($quarantine->raw_path !== null) {
                Storage::disk(self::DISK)->delete($quarantine->raw_path);
            }
        });
    }

    public function reasonLabel(): string
    {
        return __(self::REASONS[$this->reason] ?? $this->reason);
    }

    /** The raw mail, or null when it is gone. */
    public function raw(): ?string
    {
        return $this->raw_path !== null && Storage::disk(self::DISK)->exists($this->raw_path) ? Storage::disk(self::DISK)->get($this->raw_path) : null;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** @return BelongsTo<TicketDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function suggestedTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'suggested_ticket_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'handled_by');
    }
}
