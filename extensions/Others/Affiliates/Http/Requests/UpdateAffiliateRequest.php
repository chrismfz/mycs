<?php

namespace MyCS\Extensions\Others\Affiliates\Http\Requests;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class UpdateAffiliateRequest extends AdminApiRequest
{
    protected $permission = 'affiliates.update';

    public function rules(): array
    {
        return [
            'client_id' => 'nullable|exists:clients,id',
            'code' => 'nullable|string|max:255|unique:MyCS\Extensions\Others\Affiliates\Models\Affiliate,code,' . $this->route()->parameter('affiliate')->id,
            'enabled' => 'nullable|boolean',
            'reward' => 'nullable|numeric|min:0|max:100',
        ];
    }
}
