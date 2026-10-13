<?php

namespace App\Http\Requests\Api\Admin\InvoiceItems;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceItemRequest extends AdminApiRequest
{
    protected $permission = 'invoice_items.update';

    public function rules(): array
    {
        return [
            'description' => 'sometimes|string|max:255',
            'quantity' => 'sometimes|integer|min:1',
            'price' => 'sometimes|numeric|min:0',
            'reference_type' => ['sometimes', 'nullable', 'string', Rule::in(InvoiceItemReference::TYPES)],
            'reference_id' => [
                'sometimes',
                'nullable',
                'integer',
                InvoiceItemReference::rule(fn () => $this->route('invoice_item')?->invoice, $this->input('reference_type', $this->route('invoice_item')?->reference_type)),
            ],
        ];
    }
}
