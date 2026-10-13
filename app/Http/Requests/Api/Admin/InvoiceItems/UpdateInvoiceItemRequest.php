<?php

namespace App\Http\Requests\Api\Admin\InvoiceItems;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Services\Invoice\InvoiceItemReference;
use Illuminate\Validation\Validator;

class UpdateInvoiceItemRequest extends AdminApiRequest
{
    protected $permission = 'invoice_items.update';

    public function rules(): array
    {
        return [
            'description' => 'sometimes|string|max:255',
            'quantity' => 'sometimes|integer|min:1',
            'price' => 'sometimes|numeric|min:0',
            'reference_type' => 'sometimes|nullable',
            'reference_id' => 'sometimes|nullable',
        ];
    }

    /**
     * The pair the line will have: what is sent, the stored value for what is not (a type sent alone must not
     * reinterpret the stored id).
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (!$this->exists('reference_type') && !$this->exists('reference_id')) {
                return;
            }
            $item = $this->route('invoice_item');
            $type = $this->exists('reference_type') ? $this->input('reference_type') : $item?->reference_type;
            $id = $this->exists('reference_id') ? $this->input('reference_id') : $item?->reference_id;
            $problem = InvoiceItemReference::problem($item?->invoice, $type, $id);
            if ($problem !== null) {
                $validator->errors()->add(...$problem);
            }
        }];
    }
}
