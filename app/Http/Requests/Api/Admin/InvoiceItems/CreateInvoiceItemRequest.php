<?php

namespace App\Http\Requests\Api\Admin\InvoiceItems;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Invoice;
use App\Services\Invoice\InvoiceItemReference;
use Illuminate\Validation\Validator;

class CreateInvoiceItemRequest extends AdminApiRequest
{
    protected $permission = 'invoice_items.create';

    public function rules(): array
    {
        return [
            'invoice_id' => 'required|exists:invoices,id',
            'description' => 'required|string|max:65535',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:1',
            'reference_type' => 'nullable',
            'reference_id' => 'nullable',
        ];
    }

    /** The reference is checked as a pair (InvoiceItemReference). */
    public function after(): array
    {
        return [function (Validator $validator) {
            $problem = InvoiceItemReference::problem(Invoice::query()->find($this->input('invoice_id')), $this->input('reference_type'), $this->input('reference_id'));
            if ($problem !== null) {
                $validator->errors()->add(...$problem);
            }
        }];
    }
}
