<?php

namespace App\Models;

use App\Classes\PDF;
use App\Classes\Price;
use App\Classes\Settings;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Traits\HasProperties;
use App\Observers\InvoiceObserver;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $total
 * @property-read mixed $formatted_total
 * @property-read mixed $formattedTotal
 * @property-read mixed $current_balance
 * @property-read mixed $currentBalance
 * @property-read mixed $formatted_current_balance
 * @property-read mixed $formattedCurrentBalance
 * @property-read mixed $formatted_remaining
 * @property-read mixed $formattedRemaining
 * @property-read mixed $remaining
 * @property-read mixed $tax
 * @property-read mixed $user_properties
 * @property-read mixed $userProperties
 * @property-read mixed $user_name
 * @property-read mixed $userName
 * @property-read mixed $bill_to
 * @property-read mixed $billTo
 * @property-read mixed $pdf
 */
#[ObservedBy([InvoiceObserver::class])]
class Invoice extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /** Not settled yet: what a cancellation gives up on (a draft too: finalised later, it would bill it). */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_DRAFT];

    protected $fillable = ['number', 'user_id', 'currency_code', 'due_at', 'status', 'cancellation_reason'];

    protected $casts = [
        'due_at' => 'date',
    ];

    public bool $send_create_email = true;

    /**
     * Status and its local money effects commit together under customer -> invoice locks. External
     * provisioning still runs after commit. This also makes a cancelled invoice's reopening atomic.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && (($this->isDirty('status') && ($this->status === self::STATUS_PAID
            || ($this->getOriginal('status') === self::STATUS_CANCELLED
                && in_array($this->status, [self::STATUS_PENDING, self::STATUS_DRAFT], true))))
            // Moving the invoice (customer, currency) is checked against its payments under the same locks.
            || $this->isDirty(['user_id', 'currency_code']))) {
            return DB::transaction(function () use ($options) {
                $locked = (new RecordPaymentService)->lockInvoice($this->getKey());
                // The model may have been loaded before another request changed the status. Only when this save
                // changes the status: otherwise a stale form would write its old status back over a new one.
                if ($this->isDirty('status')) {
                    $this->original['status'] = $locked->getRawOriginal('status');
                }

                return parent::save($options);
            });
        }

        return parent::save($options);
    }

    /** Hold the payment locks through both the observer's guard and the actual DELETE. */
    public function delete(): ?bool
    {
        if (!$this->exists) {
            return parent::delete();
        }

        return DB::transaction(function () {
            (new RecordPaymentService)->lockInvoice($this->getKey());

            return parent::delete();
        });
    }

    /** Money arrived or on its way (a processing payment, e.g. a SEPA debit, still lands on this invoice). */
    public function hasPayments(bool $lock = false): bool
    {
        return $this->transactions()->whereIn('status', [
            InvoiceTransactionStatus::Succeeded->value, InvoiceTransactionStatus::Processing->value,
        ])->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']) !== null;
    }

    public function createCancellationCreditNote($description = null): void
    {
        $this->adjustmentNotes()->create([
            'type' => AdjustmentNoteType::Credit->value,
            'amount' => -1 * abs($this->total),
            'description' => $description ?? 'Automatic credit note generated after overdue invoice cancellation.',
            'is_cancellation' => true, // voided when the invoice is reopened (InvoiceObserver)
        ]);
    }

    /**
     * Total of the invoice.
     *
     * @return Attribute<mixed, mixed>
     */
    public function total(): Attribute
    {
        return Attribute::make(
            get: fn () => (
                $this->items->sum(
                    fn ($item) => $this->moneyToCents($item->price) * $item->quantity
                )
                + $this->moneyToCents(
                    $this->adjustmentNotes
                        ->where('status', AdjustmentNoteStatus::Active->value)
                        ->where('type', AdjustmentNoteType::Debit->value)
                        ->sum('amount')
                )
                + $this->moneyToCents(
                    $this->adjustmentNotes
                        ->where('status', AdjustmentNoteStatus::Active->value)
                        ->where('type', AdjustmentNoteType::Credit->value)
                        ->sum('amount')
                )
            ) / 100.0
        );
    }

    /**
     * Total of the invoice.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->total, 'currency' => $this->currency, 'tax' => $this->tax])
        );
    }

    /**
     * Current balance of the invoice, accounting for total + debit notes - credit notes - succeeded transactions (net of refunds).
     *
     * @return Attribute<mixed, mixed>
     */
    public function currentBalance(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->total
                - $this->transactions->where('status', InvoiceTransactionStatus::Succeeded)->sum(function ($txn) {
                    return $txn->amount - $txn->refunded_amount - $txn->credited_amount;
                })
        );
    }

    /**
     * Formatted current balance of the invoice.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedCurrentBalance(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->currentBalance, 'currency' => $this->currency, 'tax' => $this->tax])
        );
    }

    /**
     * Formatted remaining amount of the invoice.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedRemaining(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->remaining, 'currency' => $this->currency, 'tax' => $this->tax])
        );
    }

    /**
     * Remaining amount of the invoice, net of refunded amounts and of what became account credit.
     *
     * @return Attribute<mixed, mixed>
     */
    public function remaining(): Attribute
    {
        return Attribute::make(
            get: fn () => (
                $this->moneyToCents($this->total)
                - $this->transactions
                    ->where('status', InvoiceTransactionStatus::Succeeded)
                    ->sum(fn ($transaction) => $this->moneyToCents($transaction->amount)
                        - $this->moneyToCents($transaction->refunded_amount)
                        - $this->moneyToCents($transaction->credited_amount)
                    )
            ) / 100.0
        );
    }

    /**
     * Reload what the balance is computed from (lines, transactions, adjustment notes) with locking reads,
     * inside a transaction. Under REPEATABLE READ a plain read returns the snapshot of the transaction's
     * first read, even after waiting for a lock, and would miss a payment committed meanwhile
     * (docs/payments.md). Returns $this, so `$invoice->withLockedBalance()->remaining` is current.
     */
    public function withLockedBalance(): static
    {
        $this->setRelation('items', $this->items()->lockForUpdate()->get());
        $this->setRelation('transactions', $this->transactions()->lockForUpdate()->get());
        $this->setRelation('adjustmentNotes', $this->adjustmentNotes()->lockForUpdate()->get());

        return $this;
    }

    private function moneyToCents($amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Get tax model of the invoice.
     * Either via locked invoice or via users country.
     *
     * @return Attribute<mixed, mixed>
     */
    public function tax(): Attribute
    {
        if (config('settings.invoice_snapshot', true) && $this->snapshot?->tax_name) {
            return Attribute::make(
                get: fn () => new TaxRate([
                    'name' => $this->snapshot->tax_name,
                    'rate' => $this->snapshot->tax_rate,
                    'country' => $this->snapshot->tax_country,
                ])
            );
        }

        return Attribute::make(
            get: fn () => Settings::tax($this->user)
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function userProperties(): Attribute
    {
        if (config('settings.invoice_snapshot', true) && $this->snapshot?->properties) {
            return Attribute::make(
                get: fn () => $this->snapshot->properties
            );
        }

        return Attribute::make(
            get: fn () => $this->user?->properties()->with('parent_property')->whereHas('parent_property', function ($query) {
                $query->where('show_on_invoice', true);
            })->pluck('value', 'key')->toArray() ?? []
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function userName(): Attribute
    {
        if (config('settings.invoice_snapshot', true) && $this->snapshot?->name) {
            return Attribute::make(
                get: fn () => $this->snapshot->name
            );
        }

        return Attribute::make(
            get: fn () => $this->user->name ?? __('invoices.deleted_user')
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function billTo(): Attribute
    {
        if (config('settings.invoice_snapshot', true) && $this->snapshot?->bill_to) {
            return Attribute::make(
                get: fn () => $this->snapshot->bill_to
            );
        }

        return Attribute::make(
            get: fn () => config('settings.bill_to_text', config('settings.company_name'))
        );
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<InvoiceTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(InvoiceTransaction::class);
    }

    /** @return HasMany<AdjustmentNote, $this> */
    public function adjustmentNotes(): HasMany
    {
        return $this->hasMany(AdjustmentNote::class);
    }

    /** @return HasMany<MoneyEvent, $this> The invoice's money journal (docs/money-trail.md §1): read only. */
    public function moneyEvents(): HasMany
    {
        return $this->hasMany(MoneyEvent::class);
    }

    /** @return HasOne<InvoiceSnapshot, $this> */
    public function snapshot(): HasOne
    {
        return $this->hasOne(InvoiceSnapshot::class);
    }

    /** @return Attribute<mixed, mixed> */
    public function pdf(): Attribute
    {
        return Attribute::make(
            get: fn () => PDF::generateInvoice($this)
        );
    }

    /**
     * The invoice's URL: its number when that cannot be taken for an id (nixpal's «2026-15»), else its id. A purely
     * numeric number would be ambiguous: «/invoices/3» could be invoice #3 or the invoice numbered 3.
     */
    public function getRouteKey()
    {
        return self::numberIsKey($this->number) ? $this->number : $this->id;
    }

    /** A purely numeric value is always an id; anything else is an invoice number. Never both (one lookup, one answer). */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return $this->where($field, $value)->firstOrFail();
        }
        if (is_string($value) && self::numberIsKey($value)) {
            return $this->where('number', $value)->firstOrFail();
        }

        return $this->whereKey(ctype_digit((string) $value) ? (int) $value : 0)->firstOrFail();
    }

    /**
     * A number is the URL key only when it is not purely numeric (that is an id) and is safe in a URL path as it is:
     * Laravel does not encode «/», «?» or «#» in route parameters, so «INV/2026/15» would match no route.
     */
    private static function numberIsKey(mixed $number): bool
    {
        return is_string($number) && preg_match('/^[A-Za-z0-9._~-]+$/', $number) === 1 && !ctype_digit($number);
    }
}
