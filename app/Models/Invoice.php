<?php

namespace App\Models;

use App\Classes\PDF;
use App\Classes\Price;
use App\Classes\Settings;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\NotificationHelper;
use App\Models\Traits\HasProperties;
use App\Observers\InvoiceObserver;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Tax\EuVat;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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
 * @property EmailLog|null $latestEmailLog
 */
#[ObservedBy([InvoiceObserver::class])]
class Invoice extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    /**
     * The Mass Pay group (its ulid) a gateway is opened for on this invoice (PR F2): never stored, set by
     * ExtensionHelper::payGroup()/chargeGroup() for the gateway to pass to the provider and get back with the payment.
     */
    public ?string $payingGroup = null;

    /**
     * Set while a saved method is charged (PR H, SavedMethodCharge): the attempt's key, the gateway's idempotency key
     * (Stripe Idempotency-Key, PayPal PayPal-Request-Id). Not a column.
     */
    public ?string $chargeKey = null;

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

    /**
     * Unpaid past its due date and owed (owner 2026-10-21: unpaid ones in time and late ones apart), the day after it:
     * not an «add funds» one (a credit purchase left at the gateway: nothing is owed) nor one whose payment is on its way
     * (a SEPA debit processing), as the reminders leave them out (PaymentReminders::open, review of #286).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return self::whereOverdue($query);
    }

    /**
     * The rule itself, for a query that is not typed as the invoices' (a table filter's).
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UNPAID)->whereNotNull('client_id')->whereNotNull('due_at')
            // A plain comparison, not whereDate: due_at is a date, and DATE(due_at) could use no index.
            ->where('due_at', '<', today()->toDateString())
            ->whereDoesntHave('transactions', fn ($transactions) => $transactions->where('status', InvoiceTransactionStatus::Processing))
            ->whereNot(fn ($addFunds) => self::whereAddFunds($addFunds));
    }

    /**
     * «Add funds»: it has lines and every one is credit (a credit purchase: unpaid, nothing is owed).
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereAddFunds(Builder $query): Builder
    {
        return $query->whereHas('items')
            ->whereDoesntHave('items', fn ($items) => $items->where(fn ($line) => $line->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class)));
    }

    /** How many days past its due date an overdue invoice is (whereOverdue's rule, on its loaded lines); 0 when it is not. */
    public function daysOverdue(): int
    {
        $due = $this->due_at?->copy()->startOfDay();
        if ($this->status !== self::STATUS_UNPAID || $this->client_id === null || $due === null || !$due->lt(today())) {
            return 0;
        }
        if ($this->transactions->contains(fn (InvoiceTransaction $transaction) => $transaction->status === InvoiceTransactionStatus::Processing)) {
            return 0;
        }
        if ($this->items->isNotEmpty() && $this->items->every(fn (InvoiceItem $item) => $item->reference_type === Credit::class)) {
            return 0;
        }

        return (int) $due->diffInDays(today());
    }

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
        return $this->items()->whereIn('reference_type', [Service::class, Domain::class])->whereNotNull('forgone_at')->exists();
    }

    /** Not settled yet: what a cancellation gives up on (a draft too: finalised later, it would bill it). */
    public const OPEN_STATUSES = [self::STATUS_UNPAID, self::STATUS_DRAFT];

    protected $fillable = ['number', 'client_id', 'currency_code', 'due_at', 'status', 'cancellation_reason'];

    protected $casts = [
        'due_at' => 'date',
        'paid_at' => 'immutable_datetime',
        'merged_into_id' => 'integer',
        'split_from_id' => 'integer',
        'apply_pending_since' => 'immutable_datetime',
        'kept_as_debt_at' => 'immutable_datetime',
        'finish_pending_at' => 'immutable_datetime',
        // Staff keep it from the cron's charge of the saved method (PR H2b).
        'no_auto_charge' => 'boolean',
        // Staff asked Ekdosi for its document (E5, «Send to Ekdosi»): the feed's `requested`.
        'ekdosi_requested_at' => 'immutable_datetime',
        'ekdosi_requested_by' => 'integer',
    ];

    public bool $send_create_email = true;

    /** Finalized later, once all its lines are on it (a grouped renewal invoice, PR C): not when it is created. */
    public bool $finalize_later = false;

    /**
     * Whether cancelling it gives up the periods its renewal lines bill (Δ3). Not stored. true: kept billable (cancelled
     * as a consequence, CancelOpenInvoices; staff's «Bill its period again»), false: given up (staff's «not this period»),
     * null (nobody chose): a period the cron would not bill yet (it starts after its days before, billedFrom) is kept
     * billable, the cron bills it at its time; one within them is given up (owner, 2026-10-17, D7a ADV-4: invoiced or paid
     * early then taken back is no reason to lose the service; Δ3a stays for the cron's own renewals). A refund gives up
     * unless true.
     */
    public ?bool $keep_periods_billable = null;

    /**
     * The first day of a period the cron would not bill yet, as a date string: it bills a period starting before now plus
     * its days before (services: Invoice Generation days; domains: their own, RenewalBilling::domainInvoiceDays), so up to
     * that day itself (review A4-1).
     *
     * @param  class-string  $type
     */
    public static function billedFrom(string $type): string
    {
        $days = $type === Domain::class ? RenewalBilling::domainInvoiceDays() : (int) config('settings.cronjob_invoice', 7);

        return today()->addDays($days + 1)->toDateString();
    }

    /** Its renewal lines all bill periods the cron would not bill yet: cancelled, kept billable (Δ3, ADV-4). */
    public function billsOnlyPeriodsToCome(): bool
    {
        $lines = $this->items()->whereIn('reference_type', [Service::class, Domain::class])->whereNull('kind')->get(['reference_type', 'period_start']);

        return $lines->isNotEmpty() && $lines->every(fn (InvoiceItem $line) => $line->period_start !== null
            && $line->period_start->toDateString() >= self::billedFrom($line->reference_type));
    }

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
            // Without a reason: in the customer's language (the invoice's PDF), not English (ROADMAP).
            'description' => $description ?? __('invoices.cancellation_reasons.automatic', [], NotificationHelper::localeFor($this->client)),
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

    /**
     * The VAT notes the proforma prints (PR G): reverse charge (Art. 196 of Directive 2006/112/EC) when a line was
     * reverse charged, «outside the EU» when one was not taxed for that.
     *
     * @return list<string>
     */
    public function taxNotes(): array
    {
        $reasons = $this->items->pluck('tax_reason')->filter()->unique();

        return array_values(array_filter([
            $reasons->contains(EuVat::REASON_REVERSE_CHARGE) ? __('invoices.reverse_charge_note') : null,
            $reasons->contains(EuVat::REASON_OUTSIDE_EU) ? __('invoices.outside_eu_note') : null,
        ]));
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
     * What the PDF shows below its lines, as WHMCS's (owner 2026-10-20), in cents so it adds up: the account credit
     * applied (credit transactions) apart from the payments; «total» what was due after it; «balance» what is left,
     * always this invoice's remaining (total − payments, each net of refunds and of what became credit again).
     *
     * Also (reviews of #264): the active adjustment notes in cents (a sum of floats is never quite 0); the payments still
     * processing listed apart, never counted; a cancelled or refunded invoice owes nothing, whatever is left.
     *
     * @return array{adjustments: float, credit: float, total: float, payments: list<InvoiceTransaction>, processing: list<InvoiceTransaction>, balance: float}
     */
    public function statement(): array
    {
        $adjustments = $this->adjustmentNotes->where('status', AdjustmentNoteStatus::Active->value)->sum(fn ($note) => $this->moneyToCents($note->amount));
        $succeeded = $this->transactions->where('status', InvoiceTransactionStatus::Succeeded);
        $net = fn (InvoiceTransaction $transaction) => $this->moneyToCents($transaction->amount)
            - $this->moneyToCents($transaction->refunded_amount) - $this->moneyToCents($transaction->credited_amount);
        $credit = $succeeded->filter(fn (InvoiceTransaction $transaction) => (bool) $transaction->is_credit_transaction)->sum($net);
        $payments = $succeeded->reject(fn (InvoiceTransaction $transaction) => (bool) $transaction->is_credit_transaction);

        $closed = in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REFUNDED], true);

        return [
            'adjustments' => $adjustments / 100.0,
            'credit' => $credit / 100.0,
            'total' => ($this->moneyToCents($this->total) - $credit) / 100.0,
            'payments' => $payments->sortBy('created_at')->values()->all(),
            'processing' => $this->transactions->where('status', InvoiceTransactionStatus::Processing)->sortBy('created_at')->values()->all(),
            'balance' => $closed ? 0.0 : ($this->moneyToCents($this->total) - $credit - $payments->sum($net)) / 100.0,
        ];
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

    /**
     * The fiscal documents Ekdosi issued from it (docs/ekdosi-bridge.md).
     *
     * @return HasMany<EkdosiDocument, $this>
     */
    public function ekdosiDocuments(): HasMany
    {
        return $this->hasMany(EkdosiDocument::class);
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

    /** @return BelongsTo<Invoice, $this> The invoice its lines were merged into (PR E). */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'merged_into_id');
    }

    /** @return BelongsTo<Invoice, $this> The invoice it was split from (PR E). */
    public function splitFrom(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'split_from_id');
    }

    /** @return HasOne<InvoiceSnapshot, $this> */
    public function snapshot(): HasOne
    {
        return $this->hasOne(InvoiceSnapshot::class);
    }

    /**
     * How it is called on pages, PDFs and lists: «Proforma #id» while a proforma has no number, else «Invoice #number»,
     * its id when it has no number yet (a draft, since Δ2; code review of #238).
     */
    /** Not numbered yet in proforma mode (and its number not its id): its title and dates say so (review F5 of #264). */
    public function isProforma(): bool
    {
        return !$this->number && config('settings.invoice_proforma', false) && !config('settings.invoice_number_is_id', false);
    }

    public function displayTitle(): string
    {
        return $this->isProforma()
            ? __('invoices.proforma_invoice', ['id' => $this->id])
            : __('invoices.invoice', ['id' => $this->number ?: $this->id]);
    }

    /** Its PDF's file name: a number is free text («INV/2026/15»), and a download's name may hold no slash (review of #230). */
    public function pdfFileName(): string
    {
        return 'invoice-' . str_replace(['/', '\\'], '-', (string) ($this->number ?? $this->id)) . '.pdf';
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

    /** The mails about this invoice (the mail audit, docs/mail.md). */
    public function emailLogs(): MorphMany
    {
        return $this->morphMany(EmailLog::class, 'related');
    }

    /** Its latest mail, for the list's «Mail» column. */
    public function latestEmailLog(): MorphOne
    {
        return $this->morphOne(EmailLog::class, 'related')->latestOfMany();
    }
}
