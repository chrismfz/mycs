<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Services\Order\OfferCart;
use Illuminate\Http\RedirectResponse;

/** /offer/{slug}: an open offer's products into the cart with its coupon, then the cart (ROADMAP 6α). */
class OfferController extends Controller
{
    public function __invoke(string $offer): RedirectResponse
    {
        $offer = Offer::query()->where('slug', $offer)->firstOrFail();
        abort_unless($offer->isOpen(), 404);

        return redirect()->route('cart')->with('offer', ['name' => $offer->name] + OfferCart::apply($offer));
    }
}
