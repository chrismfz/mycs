<?php

namespace App\Services\Tax;

use App\Models\Client;
use App\Models\Contact;
use MyCS\Vies\VatCountry;
use MyCS\Vies\VatNumber;
use MyCS\Vies\ViesInvalidFormat;

/**
 * EU VAT for an instance that sells across the EU (PR G, docs/eu-vat.md; nixpal: home EE, decision 9). Off, nothing
 * changes: the account's country picks its rate (Settings::tax). On:
 * - outside the EU: no VAT (`outside_eu`), whatever rule exists for «all»;
 * - an EU business (contact type «business») of another member state with a VAT number VIES found valid: no VAT
 *   (`reverse_charge`);
 * - else the rate of the account's country, home included (a business at home is never reverse charged).
 * Fail closed: a number not checked yet, or that VIES could not check, is charged VAT.
 */
final class EuVat
{
    public const REASON_REVERSE_CHARGE = 'reverse_charge';

    public const REASON_OUTSIDE_EU = 'outside_eu';

    /** The account is tax exempt (staff, clients.tax_exempt). */
    public const REASON_EXEMPT = 'exempt';

    /** The product, or the line, is not taxed. */
    public const REASON_NOT_TAXABLE = 'not_taxable';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public static function enabled(): bool
    {
        return (bool) config('settings.tax_enabled', false) && (bool) config('settings.eu_vat_enabled', false);
    }

    /** The member state the instance is established in (ISO), or null: then nobody is reverse charged. */
    public static function home(): ?string
    {
        $home = self::iso(config('settings.eu_vat_home_country'));

        return VatCountry::isEu($home) ? $home : null;
    }

    /** Why the account pays no VAT under EU VAT, or null: it pays its country's rate. */
    public static function zeroReason(?Client $client): ?string
    {
        if (!self::enabled() || $client === null) {
            return null;
        }
        $country = self::territory(self::iso($client->country));
        // No country: its rate as before (the «all» rule, if any). Never a reason to charge none.
        if ($country === null) {
            return null;
        }
        if (!VatCountry::isEu($country)) {
            return self::REASON_OUTSIDE_EU;
        }

        return self::reverseCharged($client->primaryContact, $country) ? self::REASON_REVERSE_CHARGE : null;
    }

    /**
     * An EU business of another member state: its VAT number, as now written, is the one VIES (or staff) found valid,
     * and it is of the account's country (VIES's country is the number's, not the address's).
     */
    private static function reverseCharged(?Contact $contact, string $country): bool
    {
        $home = self::home();
        if ($contact === null || $home === null || $country === $home) {
            return false;
        }
        // A business only: reverse charge is for a taxable person, not a consumer who types a valid number (ADV-8).
        if ($contact->type !== 'business' || $contact->vat_status !== self::STATUS_VALID || $contact->vat_number === null) {
            return false;
        }
        $number = self::parse($contact->vat_number, $country);

        return $number !== null && $number->full() === $contact->vat_checked_number && $number->country === $country;
    }

    /** The number as stored and compared (EL… for Greece), or null when it is not an EU VAT number. */
    public static function parse(?string $vat, ?string $country = null): ?VatNumber
    {
        if ($vat === null || trim($vat) === '') {
            return null;
        }
        try {
            return VatNumber::parse($vat, self::iso($country));
        } catch (ViesInvalidFormat) {
            return null;
        }
    }

    /** What a VAT number is compared by: its EU form, else the text without spaces, dots and dashes. */
    public static function normalized(?string $vat, ?string $country = null): ?string
    {
        if ($vat === null || trim($vat) === '') {
            return null;
        }

        return self::parse($vat, $country)?->full() ?? strtoupper((string) preg_replace('/[\s.\-]/', '', $vat));
    }

    /**
     * Places outside a member state's ISO code that are in its VAT territory (Art. 7 of Directive 2006/112/EC): they pay
     * its VAT (adversarial ADV-3).
     */
    public const TERRITORIES = ['MC' => 'FR'];

    /**
     * An ISO code from a contact's country (a code, or an old full name), or null when it is not a known country: an
     * unknown code is «no country», never «outside the EU» (adversarial ADV-4). EL, VIES's code for Greece, is GR.
     */
    public static function iso(mixed $country): ?string
    {
        if (!is_string($country) || trim($country) === '') {
            return null;
        }
        $country = trim($country);
        $countries = (array) config('app.countries');
        if (strlen($country) !== 2) {
            $country = (string) (array_search($country, $countries, true) ?: '');
        }
        $country = strtoupper($country);
        if ($country === 'EL') {
            return 'GR';
        }

        // Never the list's empty «Select a country» key: no country is none (review of #524).
        return $country !== '' && array_key_exists($country, $countries) ? $country : null;
    }

    /** The member state whose VAT a country pays: Monaco France's, the others their own. */
    public static function territory(?string $iso): ?string
    {
        return $iso === null ? null : (self::TERRITORIES[$iso] ?? $iso);
    }
}
