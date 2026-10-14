<?php

namespace App\Http\Requests\Api\Admin\Services;

use App\Http\Requests\Api\Admin\AdminApiRequest;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Product;

class CreateServiceRequest extends AdminApiRequest
{
    protected $permission = 'services.create';

    public function rules(): array
    {
        return [
            'product_id' => 'required|exists:products,id',
            'plan_id' => [
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
            'client_id' => 'required|exists:clients,id',
            /**
             * @default 1
             */
            'quantity' => 'required|integer|min:1',
            /**
             * @default pending
             */
            'status' => 'required|in:pending,active,suspended,terminated,cancelled',
            'expires_at' => 'nullable|date|after_or_equal:today',
            'suspend_hold_until' => 'nullable|date|after_or_equal:today',
            /**
             * @example USD
             */
            'currency_code' => 'required|string|exists:currencies,code',
            'price' => 'required|numeric|min:0',
            'coupon_id' => 'nullable|exists:coupons,id',
            'subscription_id' => 'nullable|string|max:255',
            // A contact of the service's account (information only, docs/accounts.md 3b).
            'contact_id' => ['sometimes', 'nullable', 'integer', function ($attribute, $value, $fail) {
                $clientId = $this->input('client_id') ?? $this->route('service')?->client_id;
                if (!Contact::query()->whereKey($value)->where('client_id', $clientId)->whereNull('archived_at')->exists()) {
                    $fail('The contact must be an active contact of the service\'s account.');
                }
            }],
            'order_id' => 'nullable|exists:orders,id',
        ];
    }

    public function prepareForValidation()
    {
        $this->mergeIfMissing([
            'quantity' => 1, // Default quantity to 1 if not provided
            'status' => 'pending', // Default status to 'pending' if not provided
        ]);
    }
}
