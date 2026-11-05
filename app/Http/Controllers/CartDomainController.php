<?php

namespace App\Http\Controllers;

use App\Classes\Cart;
use App\Exceptions\DisplayException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /cart/domain?domain=x.gr: a free name from the company site's search straight into the cart to register, then the cart
 * (WG-20, as WHMCS's cart.php?a=add&domain=register&query=). Checked there as the shop's «Add» is (DomainOffer); one
 * that cannot be added goes to the shop's search for it, which says why.
 */
class CartDomainController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $domain = $request->query('domain');
        if (!is_string($domain) || trim($domain) === '') {
            return redirect()->route('domain.search');
        }
        $domain = mb_substr(trim($domain), 0, 255);
        try {
            Cart::addDomain($domain, 'register');
        } catch (DisplayException) {
            return redirect()->route('domain.search', ['domain' => $domain]);
        }

        return redirect()->route('cart');
    }
}
