<?php

namespace MyCS\Vies;

/**
 * Checks an EU VAT number with the European Commission's VIES REST service (https, no credentials):
 *
 *   POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number
 *   {"countryCode": "AT", "vatNumber": "U18522105", "requesterMemberStateCode": "EE", "requesterNumber": "…"}
 *
 * With the requester's own VAT number, VIES answers with a consultation number (requestIdentifier), the proof of the
 * check; a requester VIES rejects (INVALID_REQUESTER_INFO, a wrong setting) is left out and the result says so. Fails
 * closed: any error, including VIES's own in a 200 body, is ViesUnavailable, never «not valid» and never «valid». Ported from Ekdosi's ViesLookup, without the framework (no cache: the caller keeps the result).
 */
final class Vies
{
    public const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    private ?VatNumber $requester = null;

    public function __construct(
        private readonly Transport $transport = new CurlTransport,
        ?string $requesterVat = null,
    ) {
        // An unusable requester is left out: the check still answers, only without a consultation number.
        if ($requesterVat !== null && trim($requesterVat) !== '') {
            try {
                $this->requester = VatNumber::parse($requesterVat);
            } catch (ViesInvalidFormat) {
                $this->requester = null;
            }
        }
    }

    /**
     * @param  string|null  $countryHint  the customer's ISO country, for a number typed without its prefix
     *
     * @throws ViesInvalidFormat not an EU member state's number (nothing is sent)
     * @throws ViesUnavailable no answer about the number
     */
    public function check(string $vat, ?string $countryHint = null): ViesResult
    {
        $number = VatNumber::parse($vat, $countryHint);
        try {
            return $this->ask($number, $this->requester);
        } catch (ViesRequesterRejected) {
            // Our own number is wrong (a setting): the check still answers without it, flagged so staff fix it.
            return $this->ask($number, null, requesterRejected: true);
        }
    }

    private function ask(VatNumber $number, ?VatNumber $requester, bool $requesterRejected = false): ViesResult
    {
        $body = ['countryCode' => VatCountry::prefixOf($number->country), 'vatNumber' => $number->number];
        if ($requester !== null) {
            $body['requesterMemberStateCode'] = VatCountry::prefixOf($requester->country);
            $body['requesterNumber'] = $requester->number;
        }

        $response = $this->transport->postJson(self::ENDPOINT, $body);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ViesUnavailable(sprintf('VIES answered HTTP %d.', $response['status']));
        }
        try {
            $data = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new ViesUnavailable('VIES answered something that is not JSON.');
        }
        // Its own failures come in the body, even with a 200 (SERVICE_UNAVAILABLE, MS_UNAVAILABLE, TIMEOUT, …).
        if (is_array($data['errorWrappers'] ?? null) && $data['errorWrappers'] !== []) {
            $error = $data['errorWrappers'][0]['error'] ?? 'UNKNOWN';
            $error = is_scalar($error) ? (string) $error : 'UNKNOWN';
            if ($requester !== null && in_array($error, ['INVALID_REQUESTER_INFO', 'INVALID_REQUESTER'], true)) {
                throw new ViesRequesterRejected($error);
            }
            throw new ViesUnavailable('VIES error: ' . $error);
        }
        if (!array_key_exists('valid', $data) || !is_bool($data['valid'])) {
            throw new ViesUnavailable('VIES answered without a verdict.');
        }

        return new ViesResult(
            vat: $number,
            valid: $data['valid'],
            name: self::identity($data['name'] ?? null),
            address: self::identity($data['address'] ?? null),
            requestDate: self::text($data['requestDate'] ?? null),
            requestIdentifier: self::text($data['requestIdentifier'] ?? null),
            requesterRejected: $requesterRejected,
        );
    }

    /** A scalar field as text, null when absent, empty or of another shape. */
    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** VIES writes «---» for what a member state does not publish. */
    private static function identity(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || $value === '---' ? null : $value;
    }
}
