<?php

namespace App\Contracts;

/**
 * A registrar with names never sold, billed or done at their TLD's price, only by staff by hand (the .gr registry: names of
 * 2 characters, the EETT's 300 € a deed). A catalog price never applies to them; a domain's own price set by staff does.
 */
interface StaffPricedNames
{
    public function staffPriced(string $fqdn): bool;
}
