<?php

namespace MyCS\Vies;

/**
 * An EU VAT number split into its member state (ISO) and the number VIES checks (without the prefix). Spaces, dots and
 * dashes are dropped. A number without a prefix takes the customer's country.
 */
final class VatNumber
{
    private function __construct(
        /** ISO 3166-1 alpha-2: GR for Greece. */
        public readonly string $country,
        public readonly string $number,
    ) {}

    /**
     * @param  string|null  $countryHint  the customer's ISO country, for a number typed without its prefix
     *
     * @throws ViesInvalidFormat not an EU member state's number
     */
    public static function parse(string $vat, ?string $countryHint = null): self
    {
        $clean = strtoupper((string) preg_replace('/[\s.\-]/', '', $vat));
        if ($clean === '') {
            throw new ViesInvalidFormat('Empty VAT number.');
        }
        $country = VatCountry::isoOf(substr($clean, 0, 2));
        if ($country !== null) {
            $number = substr($clean, 2);
        } else {
            $country = $countryHint !== null && VatCountry::isEu($countryHint) && !ctype_alpha(substr($clean, 0, 2))
                ? strtoupper($countryHint) : null;
            $number = $clean;
        }
        if ($country === null) {
            throw new ViesInvalidFormat(sprintf('"%s" is not an EU VAT number.', $vat));
        }
        if (!preg_match('/^[0-9A-Z+*]{2,13}$/', $number)) {
            throw new ViesInvalidFormat(sprintf('"%s" is not an EU VAT number.', $vat));
        }

        return new self($country, $number);
    }

    /** As printed and stored: the VIES prefix and the number (EL… for Greece). */
    public function full(): string
    {
        return VatCountry::prefixOf($this->country) . $this->number;
    }
}
