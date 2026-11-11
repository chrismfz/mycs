<?php

namespace App\Http\Requests\Api\Admin\Payments;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The panel's «Edit» on a payment (EditPayment): with the permission to delete it, as there. */
class UpdatePaymentRequest extends AdminApiRequest
{
    protected $permission = 'invoice_transactions.delete';

    /** Another amount or invoice records money: the key needs the permission to record payments too (REV-781-6). */
    public function authorize(): bool
    {
        return parent::authorize() && (!$this->movesMoney() || $this->hasApiPermission('admin.invoice_transactions.create'));
    }

    private function movesMoney(): bool
    {
        $payment = $this->route('transaction');
        if (!$payment instanceof InvoiceTransaction) {
            return true;
        }

        return ($this->exists('amount') && RecordPaymentService::toCents($this->input('amount')) !== RecordPaymentService::toCents($payment->amount))
            || ($this->filled('invoice_id') && (string) $this->input('invoice_id') !== (string) $payment->invoice_id);
    }

    public function rules(): array
    {
        return [
            // Only the fields sent change. Amount and invoice: a payment staff typed, never one its gateway recorded.
            'amount' => ['sometimes', 'required', 'string', self::AMOUNT],
            // Another invoice of the same customer and currency.
            'invoice_id' => ['sometimes', 'required', 'integer', 'exists:invoices,id'],
            'paid_at' => ['sometimes', 'required', ...self::PAID_AT],
            'gateway_id' => ['sometimes', 'nullable', 'integer', Rule::exists('extensions', 'id')->where('type', 'gateway')],
            'transaction_id' => ['sometimes', 'nullable', ...self::TRANSACTION_ID],
            'fee' => ['sometimes', 'nullable', 'string', self::AMOUNT],
            // When the credit this payment gave is already spent: change it anyway, the credit goes negative.
            'allow_negative_credit' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $payment = $this->route('transaction');
            $amount = $this->exists('amount') ? $this->input('amount') : ($payment instanceof InvoiceTransaction ? $payment->amount : null);
            // The fee it has counts too: an amount lowered below it is asked for the fee as well.
            $fee = $this->exists('fee') ? $this->input('fee') : ($payment instanceof InvoiceTransaction && $payment->fee !== null ? (string) $payment->fee : null);
            if ($validator->errors()->isEmpty() && ($this->exists('fee') || $this->exists('amount')) && self::feeAbove($fee, $amount)) {
                $validator->errors()->add('fee', __('The fee cannot be above the amount.'));
            }
        }];
    }
}
