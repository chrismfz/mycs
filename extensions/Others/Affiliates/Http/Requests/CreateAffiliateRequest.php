<?php

namespace MyCS\Extensions\Others\Affiliates\Http\Requests;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class CreateAffiliateRequest extends AdminApiRequest
{
    protected $permission = 'affiliates.create';

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'code' => 'required|string|max:255|unique:MyCS\Extensions\Others\Affiliates\Models\Affiliate,code',
            'enabled' => 'nullable|boolean',
            'reward' => 'nullable|numeric|min:0|max:100',
        ];
    }
}
