<?php

namespace App\Models;

use App\Observers\TicketObserver;
use App\Support\Tickets\GuestLinks;
use App\Support\Tickets\TicketReference;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A support ticket (docs/tickets.md). It belongs to an account, or to a guest (no account: a name and an email,
 * §3 Δ), which staff may link to an account; never by its email on its own.
 *
 * @property int|null $client_id
 */
#[ObservedBy([TicketObserver::class])]
class Ticket extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    /** New, no reply from us yet. */
    public const STATUS_OPEN = 'open';

    /** We replied: it waits on the customer. */
    public const STATUS_ANSWERED = 'answered';

    /** The customer replied: back with us. */
    public const STATUS_CUSTOMER_REPLY = 'customer_reply';

    /** Paused (waiting on a third party; WHMCS «On Hold» and «In Progress»). */
    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_CLOSED = 'closed';

    /** Waiting on us: what the staff's queue shows. */
    public const AWAITING_STAFF = [self::STATUS_OPEN, self::STATUS_CUSTOMER_REPLY];

    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'subject',
        'status',
        'priority',
        'department_id',
        'client_id',
        'guest_name',
        'guest_email',
        'reference',
        'opened_via',
        'sender_vouched',
        'assigned_to',
        'service_id',
    ];

    /** The number was drawn here, not given: another may be drawn when two tickets get the same one at once. */
    private bool $referenceDrawn = false;

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket) {
            if ($ticket->reference === null) {
                $ticket->reference = TicketReference::generate();
                $ticket->referenceDrawn = true;
            }
        });
        // Imported from WHMCS and deleted here: a re-run of the import never brings it back (docs/tickets.md §6ζ).
        static::deleted(function (Ticket $ticket) {
            if ($ticket->whmcs_id !== null) {
                DB::table('ticket_whmcs_tombstones')->insertOrIgnore(['ref' => 'ticket:' . $ticket->whmcs_id, 'created_at' => now()]);
            }
        });
        // Linked to an account, or its address changed: the guest's links lead nowhere (docs/tickets.md §3 Δ).
        static::updated(function (Ticket $ticket) {
            if (($ticket->wasChanged('client_id') && $ticket->client_id !== null) || $ticket->wasChanged('guest_email')) {
                GuestLinks::revoke($ticket);
            }
        });
    }

    /** Two tickets opened at once may draw the same number between the check and the insert: draw again. */
    protected function performInsert(Builder $query)
    {
        for ($try = 1; ; $try++) {
            try {
                return parent::performInsert($query);
            } catch (UniqueConstraintViolationException $e) {
                if (!$this->referenceDrawn || $try >= 5 || !str_contains($e->getMessage(), 'reference')) {
                    throw $e;
                }
                $this->reference = TicketReference::generate();
            }
        }
    }

    /** Opened by a mail: whether our server vouched for its From (false: it may be forged); null otherwise. */
    protected $casts = ['sender_vouched' => 'boolean'];

    /** @return array<string, string> status => label, for staff */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_OPEN => __('Open'),
            self::STATUS_ANSWERED => __('Answered'),
            self::STATUS_CUSTOMER_REPLY => __('Customer reply'),
            self::STATUS_ON_HOLD => __('On hold'),
            self::STATUS_CLOSED => __('Closed'),
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return self::statusOptions()[(string) $status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    /** The Filament colour of a status. */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_OPEN, self::STATUS_CUSTOMER_REPLY => 'warning',
            self::STATUS_ANSWERED => 'info',
            self::STATUS_ON_HOLD => 'gray',
            self::STATUS_CLOSED => 'danger',
            default => 'gray',
        };
    }

    /** The Filament colour of a priority. */
    public static function priorityColor(?string $priority): string
    {
        return match ($priority) {
            'low' => 'success',
            'high' => 'danger',
            default => 'gray',
        };
    }

    /** No account: a guest's (§3 Δ). */
    public function isGuest(): bool
    {
        return $this->client_id === null;
    }

    /** Who opened it, for display: the account's name, or the guest's as they wrote it. */
    public function requesterName(): string
    {
        return $this->isGuest() ? (string) $this->guest_name : (string) $this->client?->name;
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<TicketDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'department_id');
    }

    /** The member of staff it is assigned to. @return BelongsTo<Staff, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_to');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /** What the customer may see: every message but staff's internal notes. @return HasMany<TicketMessage, $this> */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('is_internal', false);
    }
}
