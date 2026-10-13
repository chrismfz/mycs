<?php

namespace App\Http\Requests\Api\Admin\Invoices;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class CreateInvoiceRequest extends AdminApiRequest
{
    protected $permission = 'invoices.create';

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            /**
             * @example USD
             */
            'currency_code' => 'required|string|exists:currencies,code',
            'due_at' => 'nullable|date',
            /**
             * An invoice is created unpaid: it becomes paid when its payment is recorded (docs/money-trail.md, MT-1c).
             *
             * @default unpaid
             */
            'status' => 'required|string|in:draft,unpaid,cancelled',
        ];
    }

    public function prepareForValidation()
    {
        $this->mergeIfMissing([
            'status' => 'unpaid', // Default status to 'unpaid' if not provided
        ]);
    }
}
