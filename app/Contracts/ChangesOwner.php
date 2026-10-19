<?php

namespace App\Contracts;

use App\Models\Contact;
use App\Models\Domain;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;

/**
 * A registrar whose change of a domain's holder is an act of its own, charged and never recalled (G8-5: the .gr
 * registry's «ownerNameChange», the same holder's name changed, and «ownerChange», another holder). Openprovider changes
 * a registrant with the domain's contacts (updateDomain), free: not this.
 */
interface ChangesOwner
{
    /** The same holder, its name (or company name) changed. */
    public const NAME = 'name';

    /** Another holder. */
    public const OWNER = 'owner';

    /**
     * WRITE, charged: the domain's holder set to $holder (a new registry contact with its details, made for it). Done at
     * once, or awaiting the regulator (the .gr registry's 1001: geographic terms, .gov.gr), said in its details.
     *
     * @param  string  $kind  self::NAME or self::OWNER; anything else is refused, nothing asked
     */
    public function changeOwner(Domain $domain, Contact $holder, string $kind, DomainRegistrarCredentials $credentials): DomainSyncResult;
}
