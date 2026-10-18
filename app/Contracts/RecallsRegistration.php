<?php

namespace App\Contracts;

use App\Models\Domain;
use App\Support\Domains\DomainRegistrarCredentials;

/**
 * A registrar where a registration can be recalled within days of it (the .gr registry: 5 days, by its protocol).
 * Callers go through DomainManagementService (staff only).
 */
interface RecallsRegistration
{
    /** @return string what the registry says */
    public function recall(Domain $domain, DomainRegistrarCredentials $credentials): string;
}
