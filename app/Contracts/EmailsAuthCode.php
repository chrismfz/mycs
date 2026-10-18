<?php

namespace App\Contracts;

use App\Models\Domain;
use App\Support\Domains\DomainRegistrarCredentials;

/**
 * A registrar that never gives a domain's EPP code, but has the registry email its registrant a link to reset it (the
 * .gr registry's DACoR, grepp.md §5.12). Free; any registrar may ask. Callers go through DomainManagementService.
 */
interface EmailsAuthCode
{
    /** @return string what the registry says, the address masked («a***@b***.tld») */
    public function emailAuthCode(Domain $domain, DomainRegistrarCredentials $credentials): string;
}
