<?php

namespace App\Http\Requests\Api\Admin\Credits;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class UpdateCreditRequest extends AdminApiRequest
{
    protected $permission = 'credits.update';

    public function rules(): array
    {
        return [
            /**
             * @example USD
             */
            'currency_code' => [
                'sometimes',
                'required',
                'string',
                'exists:currencies,code',
                'unique:credits,currency_code,' . $this->route('credit')->id . ',id,user_id,' . $this->route('credit')->user_id,
            ],
            /**
             * The new balance: the difference is journaled as a staff adjustment (docs/money-trail.md §2).
             */
            'amount' => 'sometimes|required|numeric|min:0',
            /**
             * Why the balance changes (kept with the credit history, staff only). Required with amount.
             */
            'reason' => 'required_with:amount|nullable|string|max:1000',
        ];
    }
}
