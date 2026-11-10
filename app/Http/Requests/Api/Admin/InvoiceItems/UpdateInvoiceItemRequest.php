<?php

namespace App\Http\Requests\Api\Admin\InvoiceItems;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use Illuminate\Validation\Validator;

class UpdateInvoiceItemRequest extends AdminApiRequest
{
    protected $permission = 'invoice_items.update';

    public function rules(): array
    {
        return [
            'description' => 'sometimes|string|max:65535',
            'quantity' => 'sometimes|integer|min:1',
            'taxed' => 'sometimes|boolean',
            // Negative on a line that bills nothing (no reference): a discount, as the panel's form takes it.
            'price' => 'sometimes|numeric',
            'reference_type' => 'sometimes|nullable',
            'reference_id' => 'sometimes|nullable',
        ];
    }

    /**
     * A line keeps what it bills (sweep 6, S6-TAX-EKDOSI-4; cut, KISS): its VAT was decided for that product, so pointed
     * at another service it would keep the first one's. Staff delete the line and add a new one. The stored pair sent
     * back as it is (a client that sends the whole line) is no change.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $item = $this->route('invoice_item');
            if (is_numeric($this->input('price')) && (float) $this->input('price') < 0 && filled($item?->reference_type)) {
                $validator->errors()->add('price', __('Only a line that bills nothing (no reference) takes a negative price.'));
            }
            foreach (['reference_type', 'reference_id'] as $field) {
                $sent = $this->input($field);
                if ($this->exists($field) && (is_array($sent) || (string) $sent !== (string) $item?->getAttribute($field))) {
                    $validator->errors()->add($field, __('A line keeps what it bills: delete it and add a new line instead.'));
                }
            }
        }];
    }
}
