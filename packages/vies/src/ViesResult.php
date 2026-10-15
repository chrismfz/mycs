<?php

namespace MyCS\Vies;

/**
 * What VIES answered for one number. The request identifier is the consultation number VIES gives when the request names
 * the requester's own VAT number: the proof that the number was checked, and valid, on that date.
 */
final class ViesResult
{
    public function __construct(
        public readonly VatNumber $vat,
        public readonly bool $valid,
        /** null when the member state withholds it (VIES «---»). */
        public readonly ?string $name,
        public readonly ?string $address,
        /** As VIES gives it (ISO 8601), null when absent. */
        public readonly ?string $requestDate,
        public readonly ?string $requestIdentifier,
    ) {}
}
