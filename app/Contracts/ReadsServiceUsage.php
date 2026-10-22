<?php

namespace App\Contracts;

use App\Models\Service;
use App\Support\Services\ServiceUsageRead;

/**
 * A server extension that reads its accounts' usage (disk, bandwidth; a reseller's accounts too), all of a server's
 * services in one go (cPanel: two calls a server, not two an account). Reads only.
 */
interface ReadsServiceUsage
{
    /**
     * @param  list<Service>  $services  this server's services
     * @return array<int, ServiceUsageRead> by service id; a service not found there is left out
     */
    public function usage(array $services): array;
}
