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
 * null when it could not be told. Only for the site's server, with its key (owner 2026-11-05: never a free whois for
 * anyone who finds it); anyone else is told there is nothing here. Only TLDs we sell, under the search's own limits
 * (DomainSearch's registrar budget, PublicLookups for everyone together) and the site's bucket (the «domain-check»
 * limiter). The site keeps its own limit per visitor in front.
 */
class DomainCheckController extends Controller
{
    /** A minute's checks for the site's server with its key: every visitor of the site comes from its one address. */
    public const SITE_PER_MINUTE = 600;

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
        if (!PublicLookups::take()) {
            return response()->json(['domain' => $fqdn, 'available' => null, 'error' => 'busy'], 429)->header('Retry-After', '60');
        }

        $status = $search->statuses([$fqdn => $tld])[$fqdn] ?? DomainSearch::UNKNOWN;
        $available = match ($status) {
            DomainSearch::FREE => true,
            DomainSearch::TAKEN, DomainSearch::PREMIUM => false,
            default => null,
        };

        return response()->json(['domain' => $fqdn, 'available' => $available, 'premium' => $status === DomainSearch::PREMIUM, 'status' => $status])
            ->header('Cache-Control', $available === null ? 'no-store' : 'public, max-age=300');
    }

    /** Whether the request carries the site's key (domain_check_site_key). */
    public static function fromSite(Request $request): bool
    {
        $key = config('settings.domain_check_site_key');
        $given = $request->header('X-Site-Key');

        return is_string($key) && strlen($key) >= 32 && is_string($given) && hash_equals($key, $given);
    }
}
