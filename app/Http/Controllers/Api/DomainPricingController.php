<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Services\Domains\DomainSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The domain price list as JSON (D8a), for the company's site: every sold TLD with register, renew and transfer per years,
 * as the catalog shows them (VAT as set), in ?currency= (default the company's). Cached 10 minutes; nothing else exposed.
 */
class DomainPricingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $currency = $request->query('currency', config('settings.default_currency'));
        $code = is_string($currency) ? strtoupper($currency) : ''; // ?currency[]= is a 422, not a 500 (ADV-9)
        if (!preg_match('/^[A-Z]{3}$/', $code) || !Currency::query()->whereKey($code)->exists()) {
            return response()->json(['message' => __('domains.shop.unknown_currency')], 422);
        }

        return response()->json(['currency' => $code, 'tlds' => Cache::remember("domain-price-list:{$code}", 600, fn () => app(DomainSearch::class)->priceList($code))])
            ->header('Cache-Control', 'public, max-age=600');
    }
}
