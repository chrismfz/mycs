<?php

namespace App\Listeners;

use App\Classes\Cart;
use App\Events\Auth\Login;
use App\Support\AuthCookies;
use App\Support\CurrentClient;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;

class UserAuthListener
{
    /**
     * Handle the event.
     */
    public function handle(Login|Logout $event): void
    {
        if ($event instanceof Login) {
            // As WHMCS keeps it per user and account: the login's last login on the account it starts on (PR 2b).
            if (($client = CurrentClient::defaultFor($event->user)) !== null) {
                $client->grants()->where('user_id', $event->user->id)->update(['last_login_at' => now()]);
            }
        }
        if ($event instanceof Login) {
            // Does request have cart?
            if (Cookie::has(AuthCookies::cart())) {
                // Merge cart with user: only a cart of no account yet, or already this one's (never another account's,
                // whose id reached this browser: review of #346).
                $client = CurrentClient::defaultFor($event->user);
                $cart = \App\Models\Cart::query()->where('ulid', (string) Cookie::get(AuthCookies::cart()))->first();
                if ($cart !== null && ($cart->client_id === null || (int) $cart->client_id === (int) $client?->id)) {
                    $cart->client_id = $client?->id;
                    $cart->save();
                } elseif ($cart !== null) {
                    Cookie::queue(Cookie::forget(AuthCookies::cart()));
                }
            } elseif ($cart = CurrentClient::defaultFor($event->user)?->cart) {
                // The account's cart
                Cookie::queue(AuthCookies::cart(), $cart->ulid, 60 * 24 * 30); // 30 days
            }
        } elseif ($event instanceof Logout) {
            // Does request have cart?
            if (Cookie::has(AuthCookies::cart())) {
                // Remove cart from user
                Cookie::queue(Cookie::forget(AuthCookies::cart()));
            }
        }
    }
}
