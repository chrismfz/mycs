<?php

namespace App\Http\Requests\Api\Admin\Payments;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class DeletePaymentRequest extends AdminApiRequest
{
    protected $permission = 'invoice_transactions.delete';

    public function rules(): array
    {
        return [
            // When the credit this payment gave is already spent: delete it anyway, the credit goes negative.
            'allow_negative_credit' => ['sometimes', 'boolean'],
        ];
    }
}
