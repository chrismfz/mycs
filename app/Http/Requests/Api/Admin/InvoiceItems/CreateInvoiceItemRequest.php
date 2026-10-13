<?php

namespace App\Http\Requests\Api\Admin\InvoiceItems;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Invoice;
use Illuminate\Validation\Rule;

class CreateInvoiceItemRequest extends AdminApiRequest
{
    protected $permission = 'invoice_items.create';

    public function rules(): array
    {
        return [
            'invoice_id' => 'required|exists:invoices,id',
            'description' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:1',
            'reference_type' => ['nullable', 'string', Rule::in(InvoiceItemReference::TYPES)],
            'reference_id' => [
                'nullable',
                'integer',
                InvoiceItemReference::rule(fn () => Invoice::query()->find($this->input('invoice_id')), $this->input('reference_type')),
            ],
        ];
    }

    public function prepareForValidation()
    {
        $this->mergeIfMissing([
            'status' => 'pending', // Default status to 'pending' if not provided
        ]);
    }
}
