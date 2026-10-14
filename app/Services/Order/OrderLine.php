<?php

namespace App\Services\Order;

use App\Models\Plan;
use App\Models\Product;

/** One product to order (Σ7): what the cart holds, or what staff enter in «Create Order». */
final class OrderLine
{
    /**
     * @param  array<int, array<string, mixed>>  $configOptions  [['option_id' => …, 'value' => …, …], …]
     * @param  array<string, mixed>  $checkoutConfig  the server extension's checkout fields
     * @param  string|null  $price  staff only: the recurring price the service keeps instead of the catalog's (as
     *                              stored on the service); the first invoice charges it plus the setup fee
     * @param  array<string, mixed>  $customFields  the product's custom fields from the order form, for listeners of
     *                                              `Service\Ordered` (Σ1); not stored here
     */
    public function __construct(
        public readonly Product $product,
        public readonly Plan $plan,
        public readonly int $quantity = 1,
        public readonly array $configOptions = [],
        public readonly array $checkoutConfig = [],
        public readonly ?string $price = null,
        public readonly array $customFields = [],
    ) {}
}
