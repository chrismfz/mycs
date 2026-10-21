<?php

namespace App\Services\Order;

use App\Classes\Cart;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Support\Once;
use Throwable;

/**
 * An offer into the cart (ROADMAP 6α), as the product page would put each product in: its plan (or its first), each
 * option at its first choice, each checkout field at its default. A product that needs the customer's input (a domain, a
 * hostname, a text option) is not guessed: the customer is sent to it. Then its coupon, by the cart's own rules.
 */
final class OfferCart
{
    /**
     * @return array{added: list<string>, to_configure: list<array{name: string, url: string}>, coupon: string|null, error: string|null}
     */
    public static function apply(Offer $offer): array
    {
        // One cart for the whole offer: made now, and its cookie seen by the rest of this request (Cart reads the request's
        // cookie; the one it queues is the browser's next request's), so each product is not put in a new cart.
        $cart = Cart::createCart();
        request()->cookies->set('cart', $cart->ulid);
        Once::flush();
        $added = [];
        $toConfigure = [];
        foreach ($offer->items()->with(['product.category', 'product.plans.prices', 'product.configOptions.children', 'plan'])->get() as $item) {
            $product = $item->product;
            if ($product === null || !Product::query()->whereKey($product->id)->reachable()->exists()
                || $product->stock === 0 || !$product->price()->available) {
                continue; // retired or out of stock: left out
            }
            $plan = $item->plan && $product->plans->contains('id', $item->plan->id) ? $item->plan : $product->plans->first();
            $checkoutConfig = [];
            $needsInput = $product->configOptions->contains(fn ($option) => in_array($option->type, ['text', 'number'], true));
            foreach (ExtensionHelper::getCheckoutConfig($product, []) as $config) {
                $default = in_array($config['type'], ['select', 'radio'], true)
                    ? ($config['default'] ?? array_key_first($config['options'] ?? []))
                    : ($config['default'] ?? null);
                if ($default === null && ($config['required'] ?? false)) {
                    $needsInput = true;
                }
                $checkoutConfig[$config['name']] = $default;
            }
            if ($plan === null || $needsInput) {
                $toConfigure[] = ['name' => $product->name, 'url' => route('products.checkout', ['category' => $product->category, 'product' => $product->slug])];

                continue;
            }
            $configOptions = $product->configOptions->map(fn ($option) => (object) [
                'option_id' => $option->id,
                'option_name' => $option->name,
                'option_type' => $option->type,
                'option_env_variable' => $option->env_variable,
                'value' => $option->type === 'checkbox' ? null : $option->children->first()?->id,
                'value_name' => $option->type === 'checkbox' ? 'No' : $option->children->first()?->name,
            ]);
            Cart::add($product, $plan, $configOptions, $checkoutConfig, max(1, (int) $item->quantity));
            $added[] = $product->name;
        }

        Once::flush(); // Cart::get() as it is now, with the products
        $error = null;
        $coupon = null;
        if ($offer->coupon !== null && $added !== []) {
            try {
                Cart::applyCoupon($offer->coupon->code);
                $coupon = $offer->coupon->code;
            } catch (DisplayException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $error = __('The offer\'s discount could not be applied.');
            }
        }

        return ['added' => $added, 'to_configure' => $toConfigure, 'coupon' => $coupon, 'error' => $error];
    }
}
