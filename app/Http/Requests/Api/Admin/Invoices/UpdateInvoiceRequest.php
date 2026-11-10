<?php

namespace App\Http\Requests\Api\Admin\Invoices;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Invoice;

class UpdateInvoiceRequest extends AdminApiRequest
{
    protected $permission = 'invoices.update';

    public function rules(): array
    {
        return [
            'client_id' => 'sometimes|required|exists:clients,id',
            /**
             * @example USD
             */
            'currency_code' => 'sometimes|required|string|exists:currencies,code',
            'due_at' => 'sometimes|nullable|date',
            /**
             * `paid` when nothing is left to pay (a zero or fully paid invoice); with something left, 422 (the payment
             * is recorded instead, docs/money-trail.md MT-1c) unless `paid_without_payment` is true.
             *
             * @default unpaid
             */
            'status' => 'sometimes|required|string|in:unpaid,paid,cancelled',
            /**
             * With `status: paid` on an unpaid invoice that still owes: mark it paid with no payment recorded (its
             * services are renewed; no payment row is made). Never a credit purchase (422).
             */
            'paid_without_payment' => 'sometimes|boolean',
        ];
    }
}
