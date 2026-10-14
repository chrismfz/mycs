<?php

namespace App\Services\Order;

use App\Events\Service\Ordered;
use App\Exceptions\CouponUnusableException;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Services\Service\RenewServiceService;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use MyCS\Pricing\LinePrice;

/**
 * Places an order (Σ7, docs/orders.md): the client's checkout and the staff's «Create Order» go through here, so both
 * price the same way (`OrderPricing`), take stock and coupon uses under a lock, and make the first invoice only when
 * something is due. Services are created here only (`createService`) and start pending; a free one is started at once
 * through `RenewServiceService`, as a paid one is when its invoice is paid: provisioning (Σ1) has one way in.
 */
final class PlaceOrderService
{
    /**
     * @param  list<OrderLine>  $lines
     * @param  string  $invoiceStatus  unpaid, or draft for staff when invoices are immutable once issued
     *
     * @throws DisplayException when a product cannot be ordered (no price in the currency, out of stock, over its
     *                          per-account limit) or the coupon cannot be used ({@see CouponUnusableException})
     */
    public function place(Client $client, string $currency, array $lines, ?Coupon $coupon = null, ?CarbonInterface $dueAt = null, string $invoiceStatus = Invoice::STATUS_UNPAID): PlacedOrder
    {
        if ($lines === []) {
            throw new DisplayException(__('Your cart is empty'));
        }
        foreach ($lines as $line) {
            if ($line->quantity < 1) {
                throw new DisplayException(__('The quantity must be at least 1.'));
            }
        }

        return DB::transaction(function () use ($client, $currency, $lines, $coupon, $dueAt, $invoiceStatus) {
            // The account's row first, as every payment path locks it (no lock-order deadlock).
            Client::lock($client->id);
            $couponGiven = $coupon !== null;
            $coupon = $couponGiven ? Coupon::query()->whereKey($coupon->id)->lockForUpdate()->first() : null;
            if ($couponGiven && $coupon === null) {
                throw new CouponUnusableException(__('This coupon can no longer be used'));
            }

            $priced = [];
            foreach ($lines as $line) {
                $catalog = OrderPricing::catalog($line->product, $line->plan, $line->configOptions, $currency);
                // A line without a price row in the currency would otherwise check out free.
                if ($catalog === null) {
                    throw new DisplayException(__('product.not_available', ['product' => $line->product->name]));
                }
                $discount = OrderPricing::discount($coupon, $line->product->id);
                $withCoupon = OrderPricing::line($catalog, $discount, $client);
                $priced[] = [$line, $withCoupon, $discount !== null && $withCoupon->hasDiscount() ? OrderPricing::line($catalog, null, $client) : null];
            }

            $this->takeStock($client, $lines);
            if ($coupon !== null) {
                $this->checkCoupon($client, $coupon, count(array_filter($priced, fn ($p) => $p[2] !== null)));
            }

            $order = Order::query()->create(['client_id' => $client->id, 'currency_code' => $currency]);
            $invoice = null;

            foreach ($priced as [$line, $price, $fullPrice]) {
                $service = $this->createService($order, $client, $currency, $line, $price, $fullPrice, $coupon);
                // The hook for provisioning on order and for the product's custom fields (Σ1).
                Ordered::dispatch($service, $line);
                $firstPayment = $this->firstPayment($line, $price);

                if (BigDecimal::of($firstPayment)->isPositive()) {
                    // Made only when something is due (CART-7), with the lines priced in this transaction.
                    $invoice ??= Invoice::query()->create([
                        'client_id' => $client->id,
                        'due_at' => $dueAt ?? now()->addDays(7),
                        'currency_code' => $currency,
                        'status' => $invoiceStatus,
                    ]);
                    $invoice->items()->create([
                        'reference_id' => $service->id,
                        'reference_type' => Service::class,
                        'price' => $firstPayment,
                        'quantity' => $line->quantity,
                        'description' => $service->description,
                    ]);

                    continue;
                }

                // Nothing to pay: started now, the way a payment starts it (one activation path for Σ1: the period
                // from the billing cursor, the server job after commit).
                (new RenewServiceService)->handle($service);
            }

            return new PlacedOrder($order, $invoice);
        });
    }

    /** @param  list<OrderLine>  $lines */
    private function takeStock(Client $client, array $lines): void
    {
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[$line->product->id] = ($quantities[$line->product->id] ?? 0) + $line->quantity;
        }
        ksort($quantities);

        foreach ($quantities as $productId => $quantity) {
            // The latest product row, locked: two orders cannot both take the last unit.
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

            if ($product->per_user_limit > 0) {
                // Cancelled services do not count (CART-18): an order that was never paid does not use the limit up.
                $existing = $client->services()->where('product_id', $product->id)
                    ->whereNotIn('status', [Service::STATUS_CANCELLED])->count();
                if ($existing + $quantity > $product->per_user_limit) {
                    throw new DisplayException(__('product.user_limit', ['product' => $product->name]));
                }
            }
            if ($product->stock !== null) {
                if ($product->stock < $quantity) {
                    throw new DisplayException(__('product.out_of_stock', ['product' => $product->name]));
                }
                $product->stock -= $quantity;
                $product->save();
            }
        }
    }

    /**
     * Under the coupon's lock, counted with locking reads so a concurrent order's uses are seen (CART-5): every
     * discounted line is a use, not the checkout once.
     */
    private function checkCoupon(Client $client, Coupon $coupon, int $uses): void
    {
        if (($coupon->starts_at && $coupon->starts_at->isFuture())
            || ($coupon->expires_at && $coupon->expires_at->isPast())) {
            throw new CouponUnusableException(__('This coupon can no longer be used'));
        }
        if ($uses === 0) {
            return;
        }
        if ($coupon->max_uses && $coupon->services()->lockForUpdate()->count() + $uses > $coupon->max_uses) {
            throw new CouponUnusableException(__('This coupon can no longer be used'));
        }
        if ($coupon->max_uses_per_user
            && $coupon->services()->where('client_id', $client->id)->lockForUpdate()->count() + $uses > $coupon->max_uses_per_user) {
            throw new CouponUnusableException(__('This coupon can no longer be used'));
        }
    }

    private function createService(Order $order, Client $client, string $currency, OrderLine $line, LinePrice $price, ?LinePrice $fullPrice, ?Coupon $coupon): Service
    {
        // The price the service renews at: staff's, or the catalog's. A coupon for the first cycle only discounts
        // the first invoice; one for more cycles (or all) is kept in the price until the cron ends it.
        $renewal = $line->price ?? ($fullPrice !== null && $coupon?->cycles() === 1 ? $fullPrice->grossPrice : $price->grossPrice);

        $service = $order->services()->create([
            'client_id' => $client->id,
            'currency_code' => $currency,
            'product_id' => $line->product->id,
            'plan_id' => $line->plan->id,
            'price' => $renewal,
            'quantity' => $line->quantity,
            // Only where it discounted something (CART-2): otherwise it counts as a use and a recalculation applies it.
            'coupon_id' => $fullPrice !== null ? $coupon?->id : null,
        ]);

        foreach ($line->checkoutConfig as $key => $value) {
            $service->properties()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $options = $line->product->configOptions->keyBy('id');
        foreach ($line->configOptions as $chosen) {
            $chosen = (object) $chosen;
            /** @var ConfigOption|null $option */
            $option = $options->get($chosen->option_id ?? null);
            // Only the product's current options (CART-10): a stale cart must not give the service a config it did
            // not pay for.
            if ($option === null || !isset($chosen->value) || $chosen->value === '') {
                continue;
            }
            if (in_array($option->type, ['text', 'number'], true)) {
                $service->properties()->updateOrCreate(
                    ['key' => $option->env_variable ?: $option->name],
                    ['name' => $option->name, 'value' => $chosen->value],
                );

                continue;
            }
            $valueId = $option->type === 'checkbox'
                ? (OrderPricing::ticked($chosen->value) ? $option->children->first()?->id : null)
                : $option->children->firstWhere('id', $chosen->value)?->id;
            if ($valueId === null) {
                continue;
            }
            $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $valueId]);
        }

        return $service;
    }

    /** One unit's first invoice: the (discounted) price and setup fee with tax, or staff's price plus the setup fee. */
    private function firstPayment(OrderLine $line, LinePrice $price): string
    {
        if ($line->price !== null) {
            return (string) BigDecimal::of($line->price)->plus($price->grossSetupFee)->toScale(2);
        }

        return $price->firstPayment();
    }
}
