<?php

namespace App\Contracts;

use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\RegistrarBalance;

/**
 * A registrar that tells what is left in our account with it (G8-1: the .gr registry's account:info, Openprovider's
 * reseller): what pays for registrations, renewals and transfers. Reads only.
 */
interface ReadsAccountBalance
{
    public function accountBalance(DomainRegistrarCredentials $credentials): RegistrarBalance;
}
