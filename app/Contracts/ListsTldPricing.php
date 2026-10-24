<?php

namespace App\Contracts;

use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\TldPricing;

/**
 * A registrar that reads the costs of many TLDs at once (docs/domains.md D-6, «TLD Import & Pricing Sync»): several in
 * parallel, or every TLD it offers, paged. Reads only.
 */
interface ListsTldPricing
{
    /**
     * The costs of these TLDs, in the order asked: tld => its TldPricing, or why that one could not be read (one never
     * stops the rest). A TLD the registrar does not offer is left out.
     *
     * @param  list<string>  $tlds
     * @return array<string, TldPricing|string>
     */
    public function getTldPricingMany(array $tlds, DomainRegistrarCredentials $credentials): array;

    /**
     * Every TLD the registrar offers, with its costs: tld => TldPricing. All or nothing: a page that fails fails the list
     * (a partial list would hide TLDs without saying so).
     *
     * @return array<string, TldPricing>
     */
    public function listTldPricing(DomainRegistrarCredentials $credentials): array;
}
