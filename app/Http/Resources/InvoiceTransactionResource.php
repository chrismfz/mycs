<?php

namespace App\Http\Resources;

use App\Models\InvoiceTransaction;
use TiMacDonald\JsonApi\JsonApiResource;

/**
 * A payment of an invoice, as the panel's «Transactions» table shows it.
 *
 * @mixin InvoiceTransaction
 */
class InvoiceTransactionResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'invoice_id',
        'gateway_id',
        'transaction_id',
        'status',
        'amount',
        'fee',
        // What went back to the customer, and what became account credit (an overpayment, a refund to credit).
        'refunded_amount',
        'credited_amount',
        'is_credit_transaction',
        // The day the money arrived: the payment's date.
        'created_at',
        'updated_at',
    ];
}
