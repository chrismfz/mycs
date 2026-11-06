<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Lookup\PublicLookups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nixpal\DomainTools\DomainName;

/**
 * Is one name free (WG-20), for the company site's live search, in the shape its WHMCS proxy gave
 * (`{domain, available, premium}`): true only when free, false when taken or premium (not sold at the catalog's price),
 * null when it could not be told (a registrar that failed, or a name only the regulator can approve: «restricted»). Only for the site's server, with its key (owner 2026-11-05: never a free whois for
 * anyone who finds it); anyone else is told there is nothing here. Only TLDs we sell, under the search's own limits
 * (DomainSearch's registrar budget, PublicLookups for everyone together) and the site's bucket (the «domain-check»
 * limiter). The site keeps its own limit per visitor in front.
 */
class DomainCheckController extends Controller
{
    /**
     * A minute's checks for the site's server with its key: every visitor of the site comes from its one address. Below
     * PublicLookups::GLOBAL's pace (150 a minute), so the site alone never leaves the shop's own search «busy» (review
     * of #667).
     */
    public const SITE_PER_MINUTE = 100;

    /** A minute's requests for any other caller, by its own address (all of them refused: no key). */
    public const PER_MINUTE = 30;

    public function __invoke(Request $request, DomainSearch $search): JsonResponse
    {
        if (!self::fromSite($request)) {
            return response()->json(['message' => 'Not Found'], 404);
        }
        $input = $request->query('domain');
        $currency = (string) config('settings.default_currency');
        $sold = $search->sold($currency)->keyBy('tld');
        $name = is_string($input) && strlen($input) <= 255 ? DomainName::parse($input, $sold->keys()->all()) : null;
        // A whole name only (x.gr): a bare word, or a name with no dot, is not one to check.
        if (!is_string($input) || $name === null || !str_contains($input, '.')) {
            return response()->json(['error' => 'invalid_domain'], 400)->header('Cache-Control', 'no-store');
        }
        $tld = $name->tld !== null ? $sold->get($name->tld) : null;
        // Not one we sell, or a name in another script where its TLD takes none (allow_idn).
        if ($tld === null || (str_starts_with($name->sld, 'xn--') && !$tld->allow_idn)) {
            return response()->json(['domain' => mb_strtolower(trim($input)), 'available' => null, 'error' => 'tld_not_supported']);
        }
        $fqdn = $name->withTld();
        // A name the registry was asked about a moment ago is answered from the cache: it spends nothing (review of #667).
        if (!DomainSearch::cached($fqdn) && !PublicLookups::take()) {
            return response()->json(['domain' => $fqdn, 'available' => null, 'error' => 'busy'], 429)->header('Retry-After', '60');
        }

        $status = $search->statuses([$fqdn => $tld])[$fqdn] ?? DomainSearch::UNKNOWN;
        // One staff price (two-character .gr): not sold at the catalog's price, as the shop's own check says (DomainOffer).
        if ($status === DomainSearch::FREE && $tld->staffPriced($fqdn)) {
            $status = DomainSearch::PREMIUM;
        }
        $available = match ($status) {
            DomainSearch::FREE => true,
            DomainSearch::TAKEN, DomainSearch::PREMIUM => false,
            default => null,
        };

        return response()->json(['domain' => $fqdn, 'available' => $available, 'premium' => $status === DomainSearch::PREMIUM, 'status' => $status])
            // Private: the answer is the key's, never a shared cache's for keyless callers (review of #667).
            ->header('Cache-Control', $available === null ? 'no-store' : 'private, max-age=300');
    }

    /** Whether the request carries the site's key (domain_check_site_key). */
    public static function fromSite(Request $request): bool
    {
        $key = config('settings.domain_check_site_key');
        $given = $request->header('X-Site-Key');

        return is_string($key) && strlen($key) >= 32 && is_string($given) && hash_equals($key, $given);
    }
}
