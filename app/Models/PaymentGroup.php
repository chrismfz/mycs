<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Invoices a customer pays at once through a gateway (PR F2, Mass Pay): one amount, paid on the invoice due first
 * (invoice_id); what that one cannot take pays the others in their position (RecordPaymentService). A new group of the
 * account replaces its open one; a payment for a replaced one is still spread over its invoices.
 */
class PaymentGroup extends Model
{
    use HasUlids;

    public const STATUS_OPEN = 'open';

    /** A newer group of the account took its place: the customer started again. */
    public const STATUS_REPLACED = 'replaced';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'ulid',
        'client_id',
        'user_id',
        'currency_code',
        'invoice_id',
        'total',
        'status',
        'invoice_transaction_id',
    ];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    public function uniqueIds()
    {
        return ['ulid'];
    }

    public function getRouteKeyName()
    {
        return 'ulid';
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Invoice, $this> the invoice the gateway is paid on */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsToMany<Invoice, $this> in the order they are paid */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'payment_group_invoice')->withPivot(['position', 'amount'])->orderByPivot('position');
    }

    /** @return BelongsTo<InvoiceTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InvoiceTransaction::class, 'invoice_transaction_id');
    }
}
