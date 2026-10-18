<?php

namespace MyCS\GrEpp\Whois;

/**
 * A name as the registry's WHOIS RESTful API v1.0 gives it (docs/domains/grepp/whois-api.md): GET /api/v1/domain/{name},
 * a JSON list of one domain. No contacts: the registry publishes none. Its dates live as «dd-mm-yyyy» (2026-10-18),
 * the spec's example as «yyyy-mm-dd»: both read, kept as Y-m-d.
 */
final readonly class WhoisRecord
{
    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $bundles
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public string $name,
        public ?string $handle,
        public ?string $protocol,
        public array $statuses,
        public array $bundles,
        public array $nameservers,
        public ?bool $dnssec,
        public ?string $createdOn,
        public ?string $updatedOn,
        public ?string $expiresOn,
        public ?string $registrar,
        public ?string $registrarEmail,
        public ?string $registrarVoice,
        public ?string $registrarUrl,
    ) {}

    /** The record of a 200 answer, null when it is not one (a list of one domain with a name). */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        $domain = is_array($data) && array_is_list($data) ? ($data[0] ?? null) : $data;
        if (!is_array($domain) || !is_string($domain['name'] ?? null) || $domain['name'] === '') {
            return null;
        }
        $registrar = is_array($domain['registrar'] ?? null) ? $domain['registrar'] : [];
        $secure = is_array($domain['secureDNS'] ?? null) ? $domain['secureDNS'] : [];

        return new self(
            name: $domain['name'],
            handle: self::text($domain['handle'] ?? null),
            protocol: is_int($domain['protocol'] ?? null) || is_string($domain['protocol'] ?? null) ? (string) $domain['protocol'] : null,
            statuses: self::texts($domain['status'] ?? []),
            bundles: self::texts($domain['bundles'] ?? []),
            nameservers: array_map('strtolower', self::texts($domain['nameservers'] ?? [])),
            dnssec: is_bool($secure['delegationSigned'] ?? null) ? $secure['delegationSigned'] : null,
            createdOn: self::date($domain['createDate'] ?? null),
            updatedOn: self::date($domain['updateDate'] ?? null),
            expiresOn: self::date($domain['expireDate'] ?? null),
            registrar: self::text($registrar['name'] ?? null),
            registrarEmail: self::text($registrar['email'] ?? null),
            registrarVoice: self::text($registrar['voice'] ?? null),
            registrarUrl: self::text($registrar['url'] ?? null),
        );
    }

    /** «17-08-2004» or «2004-08-17» as 2004-08-17; anything else null (never a guess). */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', trim($value), $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private static function texts(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $values), fn ($v) => $v !== '')) : [];
    }
}
