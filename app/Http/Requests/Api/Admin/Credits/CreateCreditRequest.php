<?php

namespace App\Http\Requests\Api\Admin\Credits;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class CreateCreditRequest extends AdminApiRequest
{
    protected $permission = 'credits.create';

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            /**
             * @example USD
             */
            'currency_code' => [
                'required',
                'string',
                'exists:currencies,code',
                'unique:credits,currency_code,NULL,id,client_id,' . $this->input('client_id'),
            ],
            'amount' => 'required|numeric|min:0|max:9999999999.99',
            /**
             * Why the credit is given (kept with the credit history, staff only). Required when amount > 0.
             */
            'reason' => 'required_unless:amount,0|nullable|string|max:1000',
        ];
    }
}
