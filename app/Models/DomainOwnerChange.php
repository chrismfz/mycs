<?php

namespace App\Models;

use App\Contracts\ChangesOwner;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * A .gr domain's change of holder (G8-5b, docs/domains/grepp.md): staff ask it, its invoice is paid, it is sent to the
 * registry once, under the domain's lock. Never recalled there, so never sent twice: an answer lost leaves it «unknown»
 * for staff, who look at the registry.
 *
 * @property int $id
 * @property int $domain_id
 * @property string $kind
 * @property int $contact_id
 * @property int|null $move_to_client_id
 * @property string|null $declaration_path
 * @property string|null $declaration_name
 * @property string $status
 * @property bool $free
 * @property int|null $staff_id
 * @property string|null $result
 * @property Carbon|null $sent_at
 * @property Carbon|null $done_at
 * @property-read Domain $domain
 * @property-read Contact $contact
 * @property-read InvoiceItem|null $line
 * @property-read Staff|null $staff
 * @property-read Client|null $moveToClient
 */
class DomainOwnerChange extends Model
{
    /** Its invoice not paid yet. */
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    /** Paid (or free): to be sent. */
    public const STATUS_DUE = 'due';

    /** Asked of the registry now (or its answer lost: see sent_at). */
    public const STATUS_SENDING = 'sending';

    /** Done at the registry, but the EETT must approve it (1001: geographic terms, .gov.gr). */
    public const STATUS_AWAITING_EETT = 'awaiting_eett';

    public const STATUS_DONE = 'done';

    /** The registry refused it: nothing done, nothing charged there; staff may send it again. */
    public const STATUS_REFUSED = 'refused';

    /** Sent, no answer: maybe done. Never sent again by itself (staff look at the registry). */
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_CANCELLED = 'cancelled';

    /** Not ended: one at a time per domain. */
    public const OPEN = [self::STATUS_AWAITING_PAYMENT, self::STATUS_DUE, self::STATUS_SENDING, self::STATUS_AWAITING_EETT,
        self::STATUS_REFUSED, self::STATUS_UNKNOWN];

    protected $fillable = ['domain_id', 'kind', 'contact_id', 'move_to_client_id', 'declaration_path', 'declaration_name', 'status',
        'free', 'staff_id', 'result', 'sent_at', 'done_at'];

    protected $casts = ['free' => 'boolean', 'sent_at' => 'datetime', 'done_at' => 'datetime'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function moveToClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'move_to_client_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** The invoice line that pays it (none when free). */
    public function line(): MorphOne
    {
        return $this->morphOne(InvoiceItem::class, 'reference');
    }

    public function isTransfer(): bool
    {
        return $this->kind === ChangesOwner::OWNER;
    }
}
