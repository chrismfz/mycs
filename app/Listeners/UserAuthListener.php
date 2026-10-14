<?php

namespace App\Listeners;

use App\Classes\Cart;
use App\Events\Auth\Login;
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
            if (Cookie::has('cart')) {
                // Merge cart with user
                $cart = Cart::getOnce();
                if ($cart->exists) {
                    $cart->client_id = CurrentClient::defaultFor($event->user)?->id;
                    $cart->save();
                }
            } elseif ($cart = CurrentClient::defaultFor($event->user)?->cart) {
                // The account's cart
                Cookie::queue('cart', $cart->ulid, 60 * 24 * 30); // 30 days
            }
        } elseif ($event instanceof Logout) {
            // Does request have cart?
            if (Cookie::has('cart')) {
                // Remove cart from user
                Cookie::queue(Cookie::forget('cart'));
            }
        }
    }
}
