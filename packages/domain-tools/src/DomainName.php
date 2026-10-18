<?php

namespace Nixpal\DomainTools;

/**
 * What a visitor typed, as a name to check: lower case, IDN in punycode, without a
 * scheme, "www.", path or port. The TLD is the longest one we sell that the input ends
 * with ("example.co.uk" is co.uk when co.uk is sold); a bare word has no TLD.
 */
final class DomainName
{
    /** @var string the name without the TLD ("example"), in punycode */
    public $sld;

    /** @var string|null the TLD without the dot ("co.uk"), null for a bare word */
    public $tld;

    private function __construct($sld, $tld)
    {
        $this->sld = $sld;
        $this->tld = $tld;
    }

    /**
     * @param  string  $input  what the visitor typed
     * @param  string[]  $tlds  the TLDs we sell, with or without the dot
     * @return self|null null when nothing usable is left
     */
    public static function parse($input, array $tlds)
    {
        $name = strtolower(trim((string) $input));
        $name = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $name);
        $name = preg_replace('#[/?\#:].*$#', '', $name);
        $name = trim(preg_replace('/^www\./', '', $name), ' .');
        // Spaces between words ("greek bakery") make one name.
        $name = preg_replace('/\s+/', '', $name);
        if ($name === '') {
            return null;
        }
        $name = self::toAscii($name);
        if ($name === null) {
            return null;
        }

        $sold = [];
        foreach ($tlds as $tld) {
            $tld = self::toAscii(strtolower(trim((string) $tld, ' .')));
            if ($tld !== null && $tld !== '') {
                $sold[$tld] = true;
            }
        }
        uksort($sold, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        $tld = null;
        foreach (array_keys($sold) as $candidate) {
            if (substr($name, -strlen('.' . $candidate)) === '.' . $candidate) {
                $tld = $candidate;
                $name = substr($name, 0, -strlen('.' . $candidate));
                break;
            }
        }
        if ($tld === null && strpos($name, '.') !== false) {
            // A TLD we do not sell: keep the name, without the unknown TLD.
            $name = substr($name, 0, strpos($name, '.'));
        }
        // A sub-domain typed ("shop.example.com"): the registrable name is the last label.
        if (strpos($name, '.') !== false) {
            $name = substr($name, strrpos($name, '.') + 1);
        }
        if (!self::validLabel($name)) {
            return null;
        }

        return new self($name, $tld);
    }

    /** The full name for $tld (or the parsed one): "example.co.uk". */
    public function withTld($tld = null)
    {
        $tld = $tld === null ? $this->tld : strtolower(trim((string) $tld, ' .'));

        return $tld === null || $tld === '' ? $this->sld : $this->sld . '.' . $tld;
    }

    /** A registrable label: 1-63 of a-z, 0-9 and inner hyphens (punycode "xn--" allowed). */
    public static function validLabel($label)
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', (string) $label);
    }

    /** A full, syntactically valid domain name (labels as above, at least two). */
    public static function validDomain($domain)
    {
        $domain = (string) $domain;
        if ($domain === '' || strlen($domain) > 253 || strpos($domain, '.') === false) {
            return false;
        }
        foreach (explode('.', $domain) as $label) {
            if (!self::validLabel($label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Punycode for non-ASCII input; null when it cannot be converted. Non-transitional (IDNA2008, as browsers and the
     * .gr registry): «ς» and «ß» are kept, so «καλός.ελ» is xn--mxare2a1c, not xn--mxare6a6b (καλόσ.ελ). A name
     * IDNA2008 refuses (a zero-width joiner out of context, mixed directions) is null, no longer silently changed.
     */
    public static function toAscii($name)
    {
        if (!preg_match('/[^\x20-\x7E]/', (string) $name)) {
            return (string) $name;
        }
        if (!function_exists('idn_to_ascii')) {
            return null;
        }
        $ascii = idn_to_ascii((string) $name, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_CONTEXTJ | IDNA_CHECK_BIDI, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? null : strtolower($ascii);
    }
}
