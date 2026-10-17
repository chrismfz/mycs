<?php

namespace App\Services\Domains\Ordering;

use App\Models\Contact;
use App\Models\DomainTld;

/** A domain in an order (D8b): register or transfer a name of a TLD we sell, for its years, owned by a contact. */
final class DomainOrderLine
{
    public const REGISTER = 'register';

    public const TRANSFER = 'transfer';

    public function __construct(
        public readonly string $fqdn,
        public readonly DomainTld $tld,
        public readonly string $operation,
        public readonly int $years,
        public readonly Contact $registrant,
        public readonly ?string $eppCode = null,
    ) {}
}
