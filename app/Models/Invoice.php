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
use Illuminate\Support\Facades\Lang;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $total
 * @property-read float $taxTotal
 * @property-read float $subtotal
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
 * @property int|null $client_id
 */
#[ObservedBy([InvoiceObserver::class])]
class Invoice extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    public const STATUS_DRAFT = 'draft';

    /** Waiting for its payment: WHMCS's «Unpaid», the same word in the database and on every screen. */
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /** All its money went back, to the customer or to their credit (PR 2c, as WHMCS). Final: it holds nothing. */
    public const STATUS_REFUNDED = 'refunded';

    /** Settled: they keep their lines and staff notes (MT-1c; refunded too, PR 2c). */
    public const FROZEN_STATUSES = [self::STATUS_PAID, self::STATUS_REFUNDED];

    /** Every status, in the order screens list them. */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_UNPAID, self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_REFUNDED];

    /** What staff and customers read for a status: WHMCS's words (Unpaid, Paid…), lang/*\/invoices.php. */
    public static function statusLabel(?string $status): string
    {
        $key = 'invoices.statuses.' . $status;

        return $status !== null && Lang::has($key) ? __($key) : ucfirst((string) $status);
    }

    /** @return array<string, string> status => label, for selects and filters */
    public static function statusOptions(): array
    {
        return array_combine(self::STATUSES, array_map(self::statusLabel(...), self::STATUSES));
    }

    /** The badge color of a status in the admin. */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_PAID => 'success',
            self::STATUS_UNPAID => 'warning',
            self::STATUS_DRAFT => 'gray',
            self::STATUS_REFUNDED => 'info',
            default => 'danger',
        };
    }

    /**
     * It bills a service that has ended (cancelled or terminated): its open lines buy nothing any more. Not one kept as a
     * debt when its service ended (Δ3a): that is still owed, and paid.
     */
    public function billsEndedService(): bool
    {
        return $this->kept_as_debt_at === null && $this->items()->where('reference_type', Service::class)
            ->whereIn('reference_id', Service::query()->whereIn('status', Service::ENDED_STATUSES)->select('id'))->exists();
    }

    /** It records a service period given up on (Δ3a): kept, else the cron would bill that period again. */
    public function recordsForgonePeriod(): bool
    {
        return $this->items()->where('reference_type', Service::class)->whereNotNull('forgone_at')->exists();
    }

    /** Not settled yet: what a cancellation gives up on (a draft too: finalised later, it would bill it). */
    public const OPEN_STATUSES = [self::STATUS_UNPAID, self::STATUS_DRAFT];

    protected $fillable = ['number', 'client_id', 'currency_code', 'due_at', 'status', 'cancellation_reason'];

    protected $casts = [
        'due_at' => 'date',
        'apply_pending_since' => 'immutable_datetime',
        'kept_as_debt_at' => 'immutable_datetime',
        'finish_pending_at' => 'immutable_datetime',
    ];

    public bool $send_create_email = true;

    /** Finalized later, once all its lines are on it (a grouped renewal invoice, PR C): not when it is created. */
    public bool $finalize_later = false;

    /**
     * Cancelled as a consequence, not as such (CancelOpenInvoices: its service ends or changes cycle): its renewal lines
     * are not marked given up on (Δ3), and a service that goes on is billed again. Not stored.
     */
    public bool $keep_periods_billable = false;

    /**
     * Status and its local money effects commit together under customer -> invoice locks. External
     * provisioning still runs after commit. This also makes a cancelled invoice's reopening atomic.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && (($this->isDirty('status') && (in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_REFUNDED], true)
            || ($this->getOriginal('status') === self::STATUS_CANCELLED
                && in_array($this->status, [self::STATUS_UNPAID, self::STATUS_DRAFT], true))))
            // Moving the invoice (customer, currency) is checked against its payments under the same locks.
            || $this->isDirty(['client_id', 'currency_code']))) {
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
                $this->items->sum(fn (InvoiceItem $item) => $item->totalCents())
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
     * The tax of its lines (Δ1: each line keeps its own).
     *
     * @return Attribute<mixed, mixed>
     */
    public function taxTotal(): Attribute
    {
        return Attribute::make(get: fn () => $this->items->sum(fn (InvoiceItem $item) => (int) round((float) $item->tax_amount * 100)) / 100.0);
    }

    /**
     * Its lines without tax.
     *
     * @return Attribute<mixed, mixed>
     */
    public function subtotal(): Attribute
    {
        return Attribute::make(get: fn () => $this->items->sum(fn (InvoiceItem $item) => (int) round($item->net() * 100)) / 100.0);
    }

    /**
     * The tax by rate, for the totals: [['rate' => '24.0000', 'amount' => 4.80], …], only rates with tax.
     *
     * @return list<array{rate: string, amount: float}>
     */
    public function taxLines(): array
    {
        return $this->items->filter(fn (InvoiceItem $item) => (float) $item->tax_amount != 0)
            ->groupBy(fn (InvoiceItem $item) => (string) $item->tax_rate)
            ->map(fn ($items, $rate) => ['rate' => (string) $rate, 'amount' => $items->sum(fn (InvoiceItem $item) => (int) round((float) $item->tax_amount * 100)) / 100.0])
            ->sortKeys()->values()->all();
    }

    /** The tax's name for the totals: the issued one (snapshot) or the account's rate's, else «VAT». */
    public function taxName(): string
    {
        $tax = $this->tax;

        return ($tax instanceof TaxRate ? $tax->name : null) ?: __('invoices.tax');
    }

    /**
     * Total of the invoice, with the tax of its lines (for the subtotal/tax lines of the page and PDF).
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => Price::ofTotal($this->total, $this->taxTotal, $this->currency)
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
            get: fn () => new Price(['price' => $this->currentBalance, 'currency' => $this->currency], tax: 0)
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
            get: fn () => new Price(['price' => $this->remaining, 'currency' => $this->currency], tax: 0)
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
        // Issued without VAT (no rate for the country then): it stays so, whatever the account's country becomes.
        if (config('settings.invoice_snapshot', true) && $this->snapshot !== null) {
            return Attribute::make(get: fn () => 0);
        }

        return Attribute::make(
            get: fn () => Settings::tax($this->client)
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function userProperties(): Attribute
    {
        // An issued invoice keeps the recipient it was issued to, also when it had no details then (PR 3a review).
        if (config('settings.invoice_snapshot', true) && $this->snapshot !== null) {
            return Attribute::make(
                get: fn () => (array) ($this->snapshot->properties ?? [])
            );
        }

        // The recipient's details: the account's primary contact (PR 3a; the bill-to contact per service in 3b), then
        // the custom fields shown on invoices.
        return Attribute::make(
            get: function () {
                $contact = $this->client?->primaryContact;
                $lines = $contact === null ? [] : array_filter([
                    // The company once: for a business it is already the invoice's name (Contact::displayName).
                    'company_name' => $contact->company_name && $contact->company_name !== $contact->displayName() ? $contact->company_name : null,
                    'tax_id' => $contact->tax_id ? __('invoices.tax_id_line', ['tax_id' => $contact->tax_id]) : null,
                    'vat_number' => $contact->vat_number ? __('invoices.vat_number_line', ['vat_number' => $contact->vat_number]) : null,
                    'tax_office' => $contact->tax_office ? __('invoices.tax_office_line', ['tax_office' => $contact->tax_office]) : null,
                ]);
                foreach ($contact?->addressLines() ?? [] as $i => $line) {
                    $lines['address_' . $i] = $line;
                }

                return $lines + ($this->client?->properties()->with('parent_property')->whereHas('parent_property', function ($query) {
                    $query->where('show_on_invoice', true);
                })->pluck('value', 'key')->toArray() ?? []);
            }
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
            get: fn () => $this->client->name ?? __('invoices.deleted_user')
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

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
