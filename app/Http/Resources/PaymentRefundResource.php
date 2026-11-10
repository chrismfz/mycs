<?php

namespace App\Http\Resources;

use App\Models\MoneyEvent;
use App\Services\Invoice\UndoRefund;
use TiMacDonald\JsonApi\JsonApiResource;

/**
 * A refund standing on a payment (its journal event): what «Undo refund» lists, by the id it takes.
 *
 * @mixin MoneyEvent
 */
class PaymentRefundResource extends JsonApiResource
{
    public function toType($request): string
    {
        return 'paymentRefunds';
    }

    /** @return array<string, mixed> */
    public function toAttributes($request): array
    {
        return [
            'id' => $this->id,
            'invoice_transaction_id' => $this->invoice_transaction_id,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'currency_code' => $this->currency_code,
            // `hand`, `gateway` or `credit`: how it went back (UndoRefund::kind).
            'kind' => UndoRefund::kind($this->resource),
            'occurred_at' => $this->occurred_at,
        ];
    }
}
