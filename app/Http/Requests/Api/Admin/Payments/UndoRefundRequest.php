<?php

namespace App\Http\Requests\Api\Admin\Payments;

use App\Http\Requests\Api\Admin\AdminApiRequest;

/** The panel's «Undo refund» (UndoRefund), with its two ticks. */
class UndoRefundRequest extends AdminApiRequest
{
    protected $permission = 'invoice_transactions.update';

    public function rules(): array
    {
        return [
            // A refund to account credit, the credit already spent: undo it anyway, the credit goes negative.
            'allow_negative_credit' => ['sometimes', 'boolean'],
            /**
             * A refund the gateway made: «the money HAS left at the gateway, only MyCS's books are corrected». Without it
             * such a refund is not undone. The gateway is never called.
             */
            'money_left_at_gateway' => ['sometimes', 'boolean'],
        ];
    }
}
