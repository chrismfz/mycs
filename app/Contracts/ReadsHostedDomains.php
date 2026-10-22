<?php

namespace App\Contracts;

use App\Models\Service;

/**
 * A server extension that reads the domains its accounts host (main, addon, parked, sub; a reseller's accounts' too),
 * all of a server's services in one go. Reads only.
 */
interface ReadsHostedDomains
{
    /**
     * @param  list<Service>  $services  this server's services
     * @return array<int, list<array{domain: string, type: string, account: string}>> by service id; a service whose
     *                                                                                account is not there is left out
     */
    public function hostedDomains(array $services): array;
}
