<?php

namespace App\Http\Requests\Api\Admin\InvoiceItems;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
            'taxed' => 'sometimes|boolean',
            'reference_type' => 'nullable',
            'reference_id' => 'nullable',
            // A domain line that is not its renewal (an owner change, a fee): never taken for one (review R6 of #172).
            'kind' => 'sometimes|nullable|in:' . InvoiceItem::KIND_CHARGE,
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
