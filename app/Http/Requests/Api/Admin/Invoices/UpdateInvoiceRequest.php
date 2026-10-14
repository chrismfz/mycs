<?php

namespace App\Http\Requests\Api\Admin\Invoices;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Invoice;

class UpdateInvoiceRequest extends AdminApiRequest
{
    protected $permission = 'invoices.update';

    public function authorize(): bool
    {
        if (!parent::authorize()) {
            return false;
        }

        $invoice = $this->route('invoice');

        if (!$invoice instanceof Invoice || !config('settings.immutable_invoices_enabled', false)) {
            return true;
        }

        return $invoice->status === Invoice::STATUS_DRAFT;
    }

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
             * `paid` only when nothing is left to pay (a zero or fully paid invoice): otherwise 422, the payment is
             * recorded instead (docs/money-trail.md, MT-1c).
             *
             * @default unpaid
             */
            'status' => 'sometimes|required|string|in:unpaid,paid,cancelled',
        ];
    }
}
