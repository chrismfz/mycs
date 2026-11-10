<?php

namespace App\Http\Requests\Api\Admin\Payments;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The panel's «Add Payment» and «Mark Paid» (RecordPaymentService::recordByStaff). */
class CreatePaymentRequest extends AdminApiRequest
{
    protected $permission = 'invoice_transactions.create';

    /** «Reopen» is Mark Paid's, which also takes the invoice's update permission (MarkInvoicePaid). */
    public function authorize(): bool
    {
        return parent::authorize() && (!$this->boolean('reopen') || $this->hasApiPermission('admin.invoices.update'));
    }

    public function rules(): array
    {
        return [
            /**
             * A decimal string. More than what is left becomes account credit, unless `up_to_remaining`.
             *
             * @example 10.00
             */
            'amount' => ['required', 'string', self::AMOUNT],
            /**
             * The day the money arrived (today when left out); never a later day.
             *
             * @example 2026-11-10
             */
            'paid_at' => ['sometimes', 'nullable', ...self::PAID_AT],
            // The payment method; none is «Manual».
            'gateway_id' => ['sometimes', 'nullable', 'integer', Rule::exists('extensions', 'id')->where('type', 'gateway')],
            // Recorded once: the same id again is refused (422), nothing is added.
            'transaction_id' => ['sometimes', 'nullable', ...self::TRANSACTION_ID],
            'fee' => ['sometimes', 'nullable', 'string', self::AMOUNT],
            // «Mark Paid»: more than what is left to pay is refused instead of becoming account credit.
            'up_to_remaining' => ['sometimes', 'boolean'],
            /**
             * On a cancelled invoice: reopen it and record the payment (its services are renewed). As «Mark Paid»: the
             * key also needs `invoices.update`, and more than what is left is refused.
             */
            'reopen' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && self::feeAbove($this->input('fee'), $this->input('amount'))) {
                $validator->errors()->add('fee', __('The fee cannot be above the amount.'));
            }
        }];
    }
}
