<?php

namespace App\Livewire\DomainShop;

use App\Livewire\Component;
use App\Models\Currency;
use App\Services\Domains\DomainSearch;
use Illuminate\Support\Facades\Cache;

/** The public price list of domains (D8a): register, renew and transfer per TLD and years, in the visitor's currency. */
class Pricing extends Component
{
    public function render()
    {
        $currency = Search::currency();

        return view('domain-shop.pricing', [
            'rows' => Cache::remember("domain-price-list:{$currency}", 600, fn () => app(DomainSearch::class)->priceList($currency)),
            'currency' => Currency::query()->find($currency),
        ])->layoutData(['title' => __('domains.shop.pricing_title')]);
    }
}
