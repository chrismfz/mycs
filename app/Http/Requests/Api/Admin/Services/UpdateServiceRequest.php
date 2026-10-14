<?php

namespace App\Http\Requests\Api\Admin\Services;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Product;

class UpdateServiceRequest extends AdminApiRequest
{
    protected $permission = 'services.update';

    public function rules(): array
    {
        return [
            'product_id' => 'sometimes|required|exists:products,id',
            'plan_id' => [
                'sometimes',
                'required',
                'exists:plans,id',
                function ($attribute, $value, $fail) {
                    $productId = $this->input('product_id');
                    if ($productId && !Plan::where('id', $value)->where('priceable_type', Product::class)->where('priceable_id', $productId)->exists()) {
                        // Check if the plan belongs to the specified product
                        $fail('The selected plan does not belong to the specified product.');
                    }
                },
            ],
            'client_id' => 'sometimes|required|exists:clients,id',
            /**
             * @default 1
             */
            'quantity' => 'sometimes|required|integer|min:1',
            /**
             * @default pending
             */
            'status' => 'sometimes|required|in:pending,active,suspended,terminated,cancelled',
            'expires_at' => 'sometimes|nullable|date|after_or_equal:today',
            'suspend_hold_until' => 'sometimes|nullable|date|after_or_equal:today',
            /**
             * @example USD
             */
            'currency_code' => 'sometimes|required|string|exists:currencies,code',
            'price' => 'sometimes|required|numeric|min:0',
            'coupon_id' => 'sometimes|nullable|exists:coupons,id',
            'subscription_id' => 'sometimes|nullable|string|max:255',
            // A contact of the service's account (information only, docs/accounts.md 3b).
            'contact_id' => ['sometimes', 'nullable', 'integer', function ($attribute, $value, $fail) {
                $clientId = $this->input('client_id') ?? $this->route('service')?->client_id;
                if (!Contact::query()->whereKey($value)->where('client_id', $clientId)->whereNull('archived_at')->exists()) {
                    $fail('The contact must be an active contact of the service\'s account.');
                }
            }],
            'order_id' => 'sometimes|nullable|exists:orders,id',
        ];
    }
}
