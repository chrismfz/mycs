<?php

namespace App\Http\Requests\Api\Admin\Payments;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Validation\Rule;

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
            'paid_at' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gateway_id' => ['sometimes', 'nullable', 'integer', Rule::exists('extensions', 'id')->where('type', 'gateway')],
            'transaction_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fee' => ['sometimes', 'nullable', 'string', self::AMOUNT],
            // When the credit this payment gave is already spent: change it anyway, the credit goes negative.
            'allow_negative_credit' => ['sometimes', 'boolean'],
        ];
    }
}
