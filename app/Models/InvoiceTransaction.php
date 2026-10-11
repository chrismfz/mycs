<?php

namespace App\Models;

use App\Classes\Price;
use App\Enums\InvoiceTransactionStatus;
use App\Observers\InvoiceTransactionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $refundable_amount
 * @property-read mixed $refundableAmount
 * @property-read mixed $formatted_fee
 * @property-read mixed $formattedFee
 * @property-read mixed $formatted_amount
 * @property-read mixed $formattedAmount
 * @property-read mixed $formatted_refunded_amount
 * @property-read mixed $formattedRefundedAmount
 * @property-read mixed $formatted_refundable_amount
 * @property-read mixed $formattedRefundableAmount
 */
#[ObservedBy([InvoiceTransactionObserver::class])]
class InvoiceTransaction extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'invoice_id',
        'gateway_id',
        'amount',
        'refunded_amount',
        'credited_amount',
        'fee',
        'transaction_id',
        'status',
        'is_credit_transaction',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'credited_amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'status' => InvoiceTransactionStatus::class,
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Gateway, $this> */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    /**
     * Amount that is still refundable for this transaction: not what became account credit (docs/payments.md).
     *
     * @return Attribute<mixed, mixed>
     */
    public function refundableAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->amount - $this->refunded_amount - $this->credited_amount
        );
    }

    /**
     * Formatted remaining amount of the invoice.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedFee(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->fee, 'currency' => $this->invoice->currency])
        );
    }

    /**
     * Formatted remaining amount of the invoice.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->amount, 'currency' => $this->invoice->currency])
        );
    }

    /**
     * Formatted refunded amount of the invoice.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedRefundedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->refunded_amount, 'currency' => $this->invoice->currency])
        );
    }

    /**
     * Formatted refundable amount of the invoice.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedRefundableAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->refundable_amount, 'currency' => $this->invoice->currency])
        );
    }
}
