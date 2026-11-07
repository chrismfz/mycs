<?php

namespace App\Http\Middleware;

use App\Classes\Cart;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckoutParameterMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->handleCurrencySwitch($request);
        $this->handleCouponApplication($request);

        return $next($request);
    }

    /**
     * Handle currency switching if requested
     */
    private function handleCurrencySwitch(Request $request): void
    {
        if (!$request->has('currency')) {
            return;
        }

        $currencyCode = $request->input('currency');
        // ?currency[]=… is no currency (a TypeError further down otherwise, a 500).
        if (!is_string($currencyCode)) {
            return;
        }

        // Don't allow currency changes if cart has items or currency doesn't exist
        if ($this->shouldBlockCurrencyChange($currencyCode)) {
            return;
        }

        session(['currency' => $currencyCode]);
        // An existing (empty) cart moves with it, as CurrencySwitch does: the shop prices in the session's currency and
        // the cart, its items and the invoice in the cart's, so the two must not part.
        $cart = Cart::get();
        if ($cart->exists && $cart->currency_code !== $currencyCode) {
            $cart->currency_code = $currencyCode;
            $cart->save();
        }
    }

    /**
     * Handle coupon application if requested
     */
    private function handleCouponApplication(Request $request): void
    {
        if (!$request->has('coupon')) {
            return;
        }

        try {
            Cart::applyCoupon($request->input('coupon'));
        } catch (DisplayException $e) {
            return;
        }
    }

    /**
     * Determine if currency change should be blocked
     */
    private function shouldBlockCurrencyChange(string $currencyCode): bool
    {
        return Cart::items()->count() > 0 ||
            Currency::where('code', $currencyCode)->doesntExist();
    }
}
