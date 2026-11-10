<?php

namespace App\Http\Requests\Api\Admin\Payments;

use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Validation\Validator;

/** The panel's «Refund» on a payment: the same amount rule and the same ways back (TransactionsRelationManager). */
class RefundPaymentRequest extends AdminApiRequest
{
    protected $permission = 'invoice_transactions.update';

    public function rules(): array
    {
        return [
            /** @example 5.00 */
            'amount' => ['required', 'string', self::AMOUNT],
            /**
             * `gateway`: back through the payment's gateway, when it refunds. `manual`: given back by hand (recorded
             * only). `credit`: to the customer's account credit. A payment made with credit goes back to `credit` only.
             */
            'method' => ['required', 'string', 'in:gateway,manual,credit'],
            // The customer's «Invoice Refund Confirmation» mail (default: sent).
            'notify' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $payment = $this->route('transaction');
            if (!$payment instanceof InvoiceTransaction || $validator->errors()->isNotEmpty()) {
                return;
            }
            if (RecordPaymentService::toCents($this->input('amount')) <= 0) {
                $validator->errors()->add('amount', __('invoices.refund_amount_positive'));
            } elseif (RecordPaymentService::toCents($this->input('amount')) > RecordPaymentService::toCents($payment->refundable_amount)) {
                $validator->errors()->add('amount', __('invoices.refund_amount_exceeds_refundable', ['max' => number_format((float) $payment->refundable_amount, 2, '.', '')]));
            }
            if (!array_key_exists((string) $this->input('method'), TransactionsRelationManager::refundMethods($payment))) {
                $validator->errors()->add('method', __('This payment does not go back that way.'));
            }
        }];
    }
}
