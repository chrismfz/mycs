<?php

namespace App\Classes;

use App\Exceptions\DisplayException;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\DomainTld;
use App\Models\Plan;
use App\Models\Product;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Domains\Ordering\DomainOrderLine;
use App\Support\CallerIp;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class Cart
{
    /** The request's attribute holding a cart made in this request: its cookie reaches only the next one. */
    private const MADE_NOW = 'cart.made_now';

    public static function getOnce()
    {
        // A cart made earlier in this request is the one, not a second (a hosting and its domain added together).
        $ulid = request()->attributes->get(self::MADE_NOW) ?? Cookie::get('cart');
        if (!is_string($ulid) || !$cart = \App\Models\Cart::where('ulid', $ulid)->first()) {
            return new \App\Models\Cart;
        }

        return $cart->load('items.plan', 'items.product', 'items.product.configOptions.children.plans.prices');
    }

    public static function get()
    {
        return once(fn () => self::getOnce());
    }

    public static function clear()
    {
        // A cart made in this request has no cookie yet (review of #313).
        if (is_string($made = request()->attributes->get(self::MADE_NOW))) {
            \App\Models\Cart::where('ulid', $made)->delete();
            request()->attributes->remove(self::MADE_NOW);
        }
        if (Cookie::has('cart')) {
            \App\Models\Cart::where('ulid', Cookie::get('cart'))->delete();
            Cookie::queue(Cookie::forget('cart'));
        }
    }

    public static function items()
    {
        return self::get()->items;
    }

    public static function createCart()
    {
        $cart = self::getOnce();
        if (!$cart->exists) {
            $cart->client_id = Auth::check() ? CurrentClient::model()?->id : null;
            $cart->currency_code = session('currency', session('currency', config('settings.default_currency')));
            $cart->save();
            Cookie::queue('cart', $cart->ulid, 60 * 24 * 30); // 30 days
            request()->attributes->set(self::MADE_NOW, $cart->ulid);
            $cart = \App\Models\Cart::find($cart->id);
        }

        return $cart;
    }

    protected const DEFAULT_MAX_ITEMS = 15;

    protected const DEFAULT_RATE_LIMIT_MAX_ATTEMPTS = 10;

    protected const DEFAULT_RATE_LIMIT_DECAY_MINUTES = 1;

    /** @param  bool  $limited  false for one of several added at once, which hit the limit once (an offer: addLimit()) */
    public static function add(Product $product, Plan $plan, $configOptions, $checkoutConfig, $quantity = 1, $key = null, bool $limited = true)
    {
        if ($limited) {
            self::checkRateLimit();
        }

        // Match on key
        $cart = self::createCart();
        // Never a domain's item made a product's (adversarial ADV-4).
        if ($key !== null && $cart->items()->whereKey($key)->whereNotNull('domain')->exists()) {
            $key = null;
        }
        self::ensureCartItemLimit($cart, $key);

        $item = $cart->items()->updateOrCreate([
            'id' => $key,
        ], [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'config_options' => $configOptions,
            'checkout_config' => $checkoutConfig,
            'quantity' => $quantity,
        ]);
        $cart->load('items.plan', 'items.product', 'items.product.configOptions.children.plans.prices');

        if ($cart->coupon_id) {
            // Reapply coupon to the cart
            try {
                self::validateCoupon($cart->coupon->code);
                // Check if any of the items have gotten a discount
                if ($cart->items->filter(fn ($item) => $item->price->hasDiscount())->isEmpty()) {
                    $cart->coupon_id = null;
                    $cart->save();
                }
            } catch (DisplayException $e) {
                // Coupon is invalid, remove it
                $cart->coupon_id = null;
                $cart->save();
            }
        }

        // Return index of the newly added item
        return $item->id;
    }

    /**
     * A domain to register or transfer (D8b), checked as the shop sells it (DomainOffer: its TLD, its price for the
     * years, free or taken as the operation needs); once per name in the cart. Returns its item id.
     */
    /** @param  bool  $limited  false when it comes with another item that hit the add limit already (a hosting's domain) */
    public static function addDomain(string $fqdn, string $operation, ?int $years = null, bool $limited = true): int
    {
        if ($limited) {
            self::checkRateLimit();
        }
        // Checked before a cart is made: a name refused leaves no empty cart behind.
        $currency = self::getOnce()->currency_code ?? session('currency', config('settings.default_currency'));
        $offer = app(DomainOffer::class);
        // Its shortest priced term unless chosen (D-5: years per TLD and operation).
        $years ??= ($tld = $offer->tldFor($fqdn, $currency)) === null ? 1 : ($offer->years($tld, $operation, $currency)->first() ?? 1);
        [$fqdn, $tld] = $offer->check($fqdn, $operation, $years, $currency);
        $cart = self::createCart();
        $existing = $cart->items->first(fn (CartItem $item) => $item->isDomain() && $item->domain['fqdn'] === $fqdn);
        self::ensureCartItemLimit($cart, $existing?->id);
        $item = $cart->items()->updateOrCreate(['id' => $existing?->id], [
            'product_id' => null, 'plan_id' => null, 'config_options' => null, 'checkout_config' => null, 'quantity' => 1,
            'domain' => ['fqdn' => $fqdn, 'tld_id' => $tld->id, 'operation' => $operation, 'years' => $years],
        ]);
        $cart->load('items');

        return $item->id;
    }

    /**
     * Several domains at once (the bulk transfer, owner 2026-10-20): one hit of the add limit, their registrar checked
     * together, each added at its shortest term with its EPP code, or refused with why. Never more than the cart holds.
     *
     * @param  array<string, string|null>  $names  fqdn => its EPP code (a transfer's), as typed
     * @return array<string, true|string> fqdn as typed => true (added) or why not
     */
    public static function addDomains(array $names, string $operation): array
    {
        self::checkRateLimit();
        $currency = self::getOnce()->currency_code ?? session('currency', config('settings.default_currency'));
        $offer = app(DomainOffer::class);
        $results = [];
        $valid = [];
        foreach ($names as $typed => $epp) {
            try {
                $tld = $offer->tldFor((string) $typed, $currency);
                $years = $tld === null ? 1 : ($offer->years($tld, $operation, $currency)->first() ?? 1);
                [$fqdn, $tld] = $offer->check((string) $typed, $operation, $years, $currency, availability: false);
                $valid[(string) $typed] = [$fqdn, $tld, $years, $epp];
            } catch (DisplayException $e) {
                $results[(string) $typed] = $e->getMessage();
            }
        }
        $statuses = $valid === [] ? [] : app(DomainSearch::class)->statuses(collect($valid)->mapWithKeys(fn ($row) => [$row[0] => $row[1]])->all());
        // One cart for them all, made once: a new cart's cookie reaches only the next request, so createCart() inside the
        // loop would make a cart a name (review of #271).
        $cart = null;
        foreach ($valid as $typed => [$fqdn, $tld, $years, $epp]) {
            $error = DomainOffer::availabilityError($statuses[$fqdn] ?? DomainSearch::UNKNOWN, $operation, $fqdn);
            if ($error !== null) {
                $results[$typed] = $error;

                continue;
            }
            $cart ??= self::createCart();
            $existing = $cart->items->first(fn (CartItem $item) => $item->isDomain() && $item->domain['fqdn'] === $fqdn);
            try {
                self::ensureCartItemLimit($cart, $existing?->id);
            } catch (DisplayException $e) {
                $results[$typed] = $e->getMessage();

                continue;
            }
            $item = $cart->items()->updateOrCreate(['id' => $existing?->id], [
                'product_id' => null, 'plan_id' => null, 'config_options' => null, 'checkout_config' => null, 'quantity' => 1,
                'domain' => ['fqdn' => $fqdn, 'tld_id' => $tld->id, 'operation' => $operation, 'years' => $years],
            ]);
            if ($operation === DomainOrderLine::TRANSFER && filled($epp)) {
                $item->epp_code = mb_substr(trim((string) $epp), 0, 255);
                $item->save();
            }
            $cart->load('items');
            $results[$typed] = true;
        }

        return array_replace(array_fill_keys(array_map('strval', array_keys($names)), ''), $results);
    }

    /** A domain item's years (priced for its operation) and, for a transfer, its EPP code (D8b). */
    public static function updateDomain(int $id, ?int $years, ?string $eppCode): void
    {
        $cart = self::get();
        $item = $cart->items()->whereKey($id)->first();
        if ($item === null || !$item->isDomain()) {
            return;
        }
        $domain = $item->domain;
        if ($years !== null) {
            $tld = DomainTld::query()->with('prices')->find($domain['tld_id']);
            if ($tld === null || !app(DomainOffer::class)->years($tld, $domain['operation'], $cart->currency_code)->contains($years)) {
                throw new DisplayException(__('domains.order.years_not_offered', ['years' => $years]));
            }
            $domain['years'] = $years;
        }
        $item->domain = $domain;
        if ($eppCode !== null && $domain['operation'] === DomainOrderLine::TRANSFER) {
            $item->epp_code = mb_substr(trim($eppCode), 0, 255);
        }
        $item->save();
    }

    protected static function ensureCartItemLimit($cart, $key = null)
    {
        $existingItem = $key ? $cart->items()->where('id', $key)->exists() : false;

        if (!$existingItem && $cart->items()->count() >= self::DEFAULT_MAX_ITEMS) {
            throw new DisplayException(__('product.cart_full', ['count' => self::DEFAULT_MAX_ITEMS]));
        }
    }

    /** One hit of the add limit for several products added at once (an offer's link). */
    public static function addLimit(): void
    {
        self::checkRateLimit();
    }

    protected static function checkRateLimit()
    {
        $key = self::resolveRateLimiterKey();
        $maxAttempts = self::DEFAULT_RATE_LIMIT_MAX_ATTEMPTS;
        $decayMinutes = self::DEFAULT_RATE_LIMIT_DECAY_MINUTES;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new DisplayException(__('product.too_fast'));
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }

    protected static function resolveRateLimiterKey()
    {
        if (Auth::check()) {
            return sprintf('cart-add:user:%s', Auth::id());
        }

        return sprintf('cart-add:ip:%s', CallerIp::key());
    }

    public static function remove($index)
    {
        $cart = self::get();
        $item = $cart->items()->where('id', $index)->first();
        if ($item) {
            $item->delete(); // We also want to trigger Eloquent events
        }
        $cart->load('items.plan', 'items.product', 'items.product.configOptions.children.plans.prices');
        // Its coupon may need what was removed (a required product; F6 of #274): checked again, dropped if no longer valid.
        if ($cart->coupon_id) {
            try {
                self::validateCoupon($cart->coupon->code);
                if ($cart->items->filter(fn ($item) => $item->price->hasDiscount())->isEmpty()) {
                    $cart->update(['coupon_id' => null]);
                }
            } catch (DisplayException) {
                $cart->update(['coupon_id' => null]);
            }
        }
    }

    public static function updateQuantity($index, $quantity)
    {
        $cart = self::get();
        if ($item = $cart->items()->where('id', $index)->first()) {
            if ($item->isDomain() || $item->product?->allow_quantity !== 'combined') {
                return; // a domain is one (its years are chosen instead)
            }
        } else {
            return;
        }

        if ($quantity < 1) {
            self::remove($index);

            return;
        }
        $item->quantity = $quantity;
        $item->save();

        $cart->load('items');
    }

    /**
     * Validate if a coupon is valid for the current user and cart
     *
     * @param  string  $coupon_code
     * @return Coupon
     *
     * @throws DisplayException
     */
    public static function validateCoupon($coupon_code)
    {
        return DB::transaction(function () use ($coupon_code) {
            $coupon = Coupon::where('code', $coupon_code)->lockForUpdate()->first();

            if (!$coupon) {
                throw new DisplayException(__('Coupon code not found'));
            }

            if ($coupon->expires_at && $coupon->expires_at->isPast()) {
                throw new DisplayException(__('Coupon code has expired'));
            }
            if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
                throw new DisplayException(__('Coupon code is not active yet'));
            }
            if ($coupon->max_uses && $coupon->services()->count() >= $coupon->max_uses) {
                throw new DisplayException(__('Coupon code has reached its maximum uses'));
            }
            if (Auth::check() && $coupon->hasExceededMaxUsesPerUser(CurrentClient::id())) {
                throw new DisplayException(__('You have already used this coupon the maximum number of times allowed'));
            }
            // Its rules for this customer (a guest's are checked again at checkout, once signed up or in).
            // An empty cart keeps it for what comes (F7 of #274): its products are checked once there are some.
            $items = self::get()->items;
            $refusal = $items->isEmpty() ? null : $coupon->refusalFor(Auth::check() ? CurrentClient::model() : null, $items->pluck('product_id')->map(fn ($id) => (int) $id)->all());
            if ($refusal !== null) {
                throw new DisplayException($refusal);
            }
            if ($coupon->products->isNotEmpty()) {
                $cart = self::get();
                $applicable = false;
                foreach ($cart->items as $item) {
                    if ($coupon->products->contains($item->product_id)) {
                        $applicable = true;
                        break;
                    }
                }
                if (!$applicable) {
                    throw new DisplayException(__('Coupon code is not valid for any items in your cart'));
                }
            }

            return $coupon;
        });
    }

    public static function applyCoupon($code)
    {
        if (RateLimiter::tooManyAttempts('apply_coupon_' . CallerIp::key(), 5)) {
            throw new DisplayException(__('Too many attempts. Please try again later.'));
        }

        RateLimiter::hit('apply_coupon_' . CallerIp::key());

        $coupon = self::validateCoupon($code);

        $wasSuccessful = false;
        $cart = self::createCart();
        $cart->coupon_id = $coupon->id;
        $cart->save();

        // Check if any of the items have gotten a discount, if empty also set succesful because it's valid for future use (will get rechecked on checkout
        if ($cart->items->filter(fn ($item) => $item->price->hasDiscount())->isNotEmpty() || $cart->items->isEmpty()) {
            $wasSuccessful = true;
        } else {
            $cart->coupon_id = null;
        }

        if ($wasSuccessful) {
            $cart->save();

            return $cart;
        } else {
            $cart->coupon_id = null;
            $cart->save();
            throw new DisplayException(__('Coupon code is not valid for any items in your cart'));
        }
    }

    public static function removeCoupon()
    {
        self::get()->update(['coupon_id' => null]);
        self::get()->load('coupon');
    }
}
