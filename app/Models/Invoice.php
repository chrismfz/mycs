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
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    protected $fillable = ['number', 'user_id', 'currency_code', 'due_at', 'status', 'cancellation_reason'];

    protected $casts = [
        'due_at' => 'date',
    ];

    public bool $send_create_email = true;

    public function createCancellationCreditNote($description = null): void
    {
        $this->adjustmentNotes()->create([
            'type' => AdjustmentNoteType::Credit->value,
            'amount' => -1 * abs($this->total),
            'description' => $description ?? 'Automatic credit note generated after overdue invoice cancellation.',
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
                    return $txn->amount - $txn->refunded_amount;
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
     * Remaining amount of the invoice, net of refunded amounts.
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
                    )
            ) / 100.0
        );
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
        if (config('settings.invoice_snapshot', true) && $this?->snapshot?->tax_name) {
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
        if (config('settings.invoice_snapshot', true) && $this?->snapshot?->properties) {
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
        if (config('settings.invoice_snapshot', true) && $this?->snapshot?->name) {
            return Attribute::make(
                get: fn () => $this->snapshot->name
            );
        }

        return Attribute::make(
            get: fn () => $this->user?->name ?? __('invoices.deleted_user')
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function billTo(): Attribute
    {
        if (config('settings.invoice_snapshot', true) && $this?->snapshot?->bill_to) {
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

    public function getRouteKey()
    {
        // Prefer using number if it’s set, otherwise fallback to id
        return $this->number ?: $this->id;
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return $this->where($field, $value)->firstOrFail();
        }

        // Try to find by number first
        $query = $this->where('number', $value);

        // Only try to match by ID if value is numeric
        if (is_numeric($value)) {
            $query->orWhere('id', $value);
        }

        return $query->orderByRaw('(number = ?) DESC', [$value])->firstOrFail();
    }
}
