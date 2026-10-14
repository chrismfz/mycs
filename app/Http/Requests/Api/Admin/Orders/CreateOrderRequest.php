<?php

namespace App\Http\Requests\Api\Admin\Orders;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class CreateOrderRequest extends AdminApiRequest
{
    protected $permission = 'orders.create';

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            /**
             * @example USD
             */
            'currency_code' => 'required|string|exists:currencies,code',
            'notes' => 'nullable|string|max:65535',
        ];
    }
}
