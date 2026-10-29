<?php

namespace MyCS\EuVatRates;

/**
 * The European Commission's TEDB web service (Taxes in Europe Database, SOAP, free, no key): the request for the
 * member states' rates on a day, and its answer read into each state's standard rate. Sending it is the caller's
 * (with its own timeout).
 *
 * A state can answer more than one standard rate: its regions' (Spain's Canary Islands, 7%) beside the national one.
 * The national rate is the highest of them (every regional standard rate is lower).
 */
final class Tedb
{
    public const ENDPOINT = 'https://ec.europa.eu/taxation_customs/tedb/ws/';

    public const SOAP_ACTION = 'urn:ec.europa.eu:taxud:tedb:services:v1:VatRetrievalService/RetrieveVatRates';

    private const SERVICE = 'urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService';

    private const TYPES = 'urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types';

    /** @param  list<string>  $countries ISO codes (GR is sent as TEDB's EL) */
    public static function request(\DateTimeInterface $on, array $countries = StandardRates::EU): string
    {
        $states = implode('', array_map(fn (string $iso) => '<t:isoCode>' . self::code($iso) . '</t:isoCode>', $countries));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:s="' . self::SERVICE . '" xmlns:t="' . self::TYPES . '">'
            . '<soapenv:Header/><soapenv:Body><s:retrieveVatRatesReqMsg>'
            . "<t:memberStates>{$states}</t:memberStates><t:situationOn>{$on->format('Y-m-d')}</t:situationOn>"
            . '</s:retrieveVatRatesReqMsg></soapenv:Body></soapenv:Envelope>';
    }

    /**
     * Each asked state's standard rate. Anything less than all of them is no answer: a fault, XML that does not read, a
     * state without a standard rate, a rate that is not one.
     *
     * @param  list<string>  $countries  ISO codes
     * @return array<string, string> ISO country => rate («20.00»)
     *
     * @throws TedbUnavailable
     */
    public static function parse(string $xml, array $countries = StandardRates::EU): array
    {
        if (trim($xml) === '') {
            throw new TedbUnavailable('TEDB answered nothing');
        }
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // No network, no entities: the answer is data.
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new TedbUnavailable('TEDB answered something that is not XML');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('env', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('t', self::TYPES);
        $fault = $xpath->query('//env:Fault');
        if ($fault !== false && $fault->length > 0) {
            $text = trim((string) $xpath->evaluate('string(//env:Fault/faultstring)')) ?: trim((string) $fault->item(0)?->textContent);
            throw new TedbUnavailable('TEDB fault: ' . mb_substr($text, 0, 300));
        }

        $found = [];
        $results = $xpath->query('//t:vatRateResults[t:type="STANDARD"][t:rate/t:type="DEFAULT"]');
        foreach ($results ?: [] as $result) {
            $iso = self::iso(trim((string) $xpath->evaluate('string(t:memberState)', $result)));
            $value = trim((string) $xpath->evaluate('string(t:rate/t:value)', $result));
            if (!in_array($iso, $countries, true) || $value === '') {
                continue;
            }
            try {
                $rate = Rate::normalize($value);
            } catch (\InvalidArgumentException) {
                throw new TedbUnavailable("TEDB answered a standard rate for {$iso} that is not a rate: " . mb_substr($value, 0, 20));
            }
            // The national rate is the highest: the regions' are lower.
            if (!isset($found[$iso]) || self::cents($rate) > self::cents($found[$iso])) {
                $found[$iso] = $rate;
            }
        }
        $missing = array_values(array_diff($countries, array_keys($found)));
        if ($missing !== []) {
            throw new TedbUnavailable('TEDB answered no standard rate for ' . implode(', ', $missing));
        }
        ksort($found);

        return $found;
    }

    private static function cents(string $rate): int
    {
        [$whole, $fraction] = explode('.', $rate);

        return (int) $whole * 100 + (int) $fraction;
    }

    private static function code(string $iso): string
    {
        $iso = strtoupper($iso);

        return $iso === 'GR' ? 'EL' : $iso;
    }

    private static function iso(string $code): string
    {
        $code = strtoupper($code);

        return $code === 'EL' ? 'GR' : $code;
    }
}
