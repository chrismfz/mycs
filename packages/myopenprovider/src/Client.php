<?php

namespace Nixpal\MyOpenprovider;

use Nixpal\MyOpenprovider\Http\Transport;

/**
 * Openprovider REST API (v1beta) without the generated SDK: plain JSON over a
 * Transport. Ported from Ekdosi's OpenproviderRegistrar; request and response field
 * names checked against openprovider/rest-client-php (docs/openprovider.md).
 *
 * Auth: POST /v1beta/auth/login → bearer (~24h at Openprovider); kept in the
 * TokenStore for 6h and re-fetched once when a request answers 401.
 */
final class Client
{
    const PRODUCTION_URL = 'https://api.openprovider.eu';

    // The sandbox Openprovider documents (also what Ekdosi uses).
    const SANDBOX_URL = 'http://api.sandbox.openprovider.nl:8480';

    const TOKEN_TTL = 21600;

    /** Statuses of a record that no longer represents the domain (deleted, failed request). */
    const DEAD_STATUSES = ['DEL', 'FAI'];

    /** Request fields never written to the log. */
    const SECRET_FIELDS = ['password', 'auth_code', 'token'];

    /** @var Transport */
    private $http;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var bool */
    private $sandbox;

    /** @var TokenStore|null */
    private $tokens;

    /** @var callable|null fn(string, string, ?string, int, string): void */
    private $logger;

    /** @var string|null token for this instance when there is no store */
    private $token;

    public function __construct(Transport $http, $username, $password, $sandbox = false, ?TokenStore $tokens = null, $logger = null)
    {
        if ((string) $username === '' || (string) $password === '') {
            throw new OpenproviderException('Openprovider username/password missing');
        }
        $this->http = $http;
        $this->username = (string) $username;
        $this->password = (string) $password;
        $this->sandbox = (bool) $sandbox;
        $this->tokens = $tokens;
        $this->logger = $logger;
    }

    public function baseUrl()
    {
        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    // ---- domains -------------------------------------------------------------------------

    /**
     * The account's record for a domain name, or null when the account has none.
     * After a lapse and re-registration the account can hold both the old DEL record and
     * the new live one; the live one wins.
     *
     * @return array<string,mixed>|null
     */
    public function findDomain($fqdn)
    {
        [$name, $extension] = self::splitFqdn($fqdn);
        $data = $this->request('GET', '/v1beta/domains?full_name=' . rawurlencode($name . '.' . $extension));
        $results = isset($data['results']) && is_array($data['results'])
            ? array_values(array_filter($data['results'], 'is_array'))
            : [];
        foreach ($results as $record) {
            if (!in_array(strtoupper((string) ($record['status'] ?? '')), self::DEAD_STATUSES, true)) {
                return $record;
            }
        }

        return $results[0] ?? null;
    }

    /** @return array<string,mixed> */
    public function getDomain($id)
    {
        $data = $this->request('GET', '/v1beta/domains/' . self::id($id));
        if (!is_array($data) || !isset($data['id'])) {
            throw new OpenproviderException('Openprovider returned no domain for id ' . self::id($id));
        }

        return $data;
    }

    /** Renews for $years. Openprovider charges the account from here on. */
    public function renewDomain($id, $years)
    {
        $years = (int) $years;
        if ($years < 1 || $years > 10) {
            throw new OpenproviderException('Invalid renewal period: ' . $years);
        }

        return $this->request('POST', '/v1beta/domains/' . self::id($id) . '/renew', [
            'id' => (int) self::id($id),
            'period' => $years,
        ]);
    }

    /** Restores a deleted domain from quarantine (redemption fee). */
    public function restoreDomain($id)
    {
        return $this->request('POST', '/v1beta/domains/' . self::id($id) . '/restore', [
            'id' => (int) self::id($id),
        ]);
    }

    /** @param string $value on, off or default (the account's setting) */
    public function setAutorenew($id, $value)
    {
        if (!in_array($value, ['on', 'off', 'default'], true)) {
            throw new OpenproviderException('Invalid autorenew value: ' . $value);
        }

        return $this->request('PUT', '/v1beta/domains/' . self::id($id), [
            'id' => (int) self::id($id),
            'autorenew' => $value,
        ]);
    }

    // ---- DNS zones -----------------------------------------------------------------------

    /**
     * The records of the zone of $fqdn at Openprovider, or null when there is no such zone.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public function getZoneRecords($fqdn)
    {
        [$name, $extension] = self::splitFqdn($fqdn);
        $zone = $name . '.' . $extension;
        $zones = $this->request('GET', '/v1beta/dns/zones?name_pattern=' . rawurlencode($zone) . '&limit=10');
        $found = false;
        foreach ((array) ($zones['results'] ?? []) as $candidate) {
            if (is_array($candidate) && strtolower((string) ($candidate['name'] ?? '')) === $zone && empty($candidate['is_deleted'])) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return null;
        }

        $records = [];
        for ($offset = 0; $offset < 5000; $offset += 500) {
            $data = $this->request('GET', '/v1beta/dns/zones/' . rawurlencode($zone) . '/records?limit=500&offset=' . $offset);
            $batch = array_values(array_filter((array) ($data['results'] ?? []), 'is_array'));
            $records = array_merge($records, $batch);
            if (count($batch) < 500) {
                break;
            }
        }

        return $records;
    }

    /** Creates a master zone for $fqdn with $records (name/type/value/ttl/prio arrays). */
    public function createZone($fqdn, array $records = [])
    {
        [$name, $extension] = self::splitFqdn($fqdn);

        return $this->request('POST', '/v1beta/dns/zones', [
            'domain' => ['name' => $name, 'extension' => $extension],
            'type' => 'master',
            'records' => array_values($records),
        ]);
    }

    /** Adds and/or removes records of the zone of $fqdn. */
    public function updateZoneRecords($fqdn, array $add = [], array $remove = [])
    {
        [$name, $extension] = self::splitFqdn($fqdn);
        $records = [];
        if ($add) {
            $records['add'] = array_values($add);
        }
        if ($remove) {
            $records['remove'] = array_values($remove);
        }

        return $this->request('PUT', '/v1beta/dns/zones/' . rawurlencode($name . '.' . $extension), [
            'name' => $name . '.' . $extension,
            'records' => $records,
        ]);
    }

    /**
     * The nameserver hosts (glue) registered under $fqdn: [['name', 'ip', 'ip6'], ...].
     */
    public function listNameservers($fqdn)
    {
        [$name, $extension] = self::splitFqdn($fqdn);
        $suffix = '.' . $name . '.' . $extension;
        $data = $this->request('GET', '/v1beta/dns/nameservers?pattern=' . rawurlencode('*' . $suffix) . '&limit=100');
        $hosts = [];
        foreach ((array) ($data['results'] ?? []) as $host) {
            $host_name = is_array($host) ? strtolower(rtrim((string) ($host['name'] ?? ''), '.')) : '';
            if ($host_name !== '' && substr($host_name, -strlen($suffix)) === $suffix) {
                $hosts[] = ['name' => $host_name, 'ip' => (string) ($host['ip'] ?? ''), 'ip6' => (string) ($host['ip6'] ?? '')];
            }
        }

        return $hosts;
    }

    /** Creates a nameserver host, or changes its addresses when $exists. */
    public function saveNameserver(array $host, $exists)
    {
        if ($exists) {
            return $this->request('PUT', '/v1beta/dns/nameservers/' . rawurlencode($host['name']), $host);
        }

        return $this->request('POST', '/v1beta/dns/nameservers', $host);
    }

    /** Deletes a nameserver host (Openprovider refuses while a domain still uses it). */
    public function deleteNameserver($host_name)
    {
        return $this->request('DELETE', '/v1beta/dns/nameservers/' . rawurlencode(strtolower((string) $host_name)));
    }

    // ---- name suggestions ----------------------------------------------------------------

    /**
     * Openprovider's name suggestions for $term (POST /v1beta/domains/suggest-name), as
     * lower-case domain names. Not availability-checked, and the TLD filter is a hint
     * only (other TLDs come back too).
     *
     * @param  string[]  $tlds  TLDs without the dot (com, co.uk)
     * @return string[]
     */
    public function suggestNames($term, array $tlds = [], $limit = 9, $language = 'eng', $sensitive = true)
    {
        $payload = [
            'name' => (string) $term,
            'limit' => max(1, min(50, (int) $limit)),
            'language' => (string) $language,
            'sensitive' => (bool) $sensitive,
        ];
        if ($tlds) {
            $payload['tlds'] = array_values(array_map('strval', $tlds));
        }
        $data = $this->request('POST', '/v1beta/domains/suggest-name', $payload);

        $names = [];
        foreach ((array) ($data['results'] ?? []) as $result) {
            if (!is_array($result)) {
                continue;
            }
            $name = strtolower(trim((string) ($result['name'] ?? ''), ' .'));
            if ($name === '' && isset($result['domain'], $result['tld'])) {
                $name = strtolower(trim((string) $result['domain'] . '.' . (string) $result['tld'], ' .'));
            }
            if ($name !== '' && strpos($name, '.') !== false) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    // ---- plumbing ------------------------------------------------------------------------

    /**
     * One authenticated call; returns the envelope's "data". Openprovider answers
     * {"code":0,"data":…} on success and {"code":N,"desc":"…"} on failure.
     *
     * @return mixed
     */
    public function request($method, $path, ?array $payload = null)
    {
        [$status, $json] = $this->send($method, $path, $payload, $this->token());
        if ($status === 401) {
            $this->forgetToken();
            [$status, $json] = $this->send($method, $path, $payload, $this->token());
        }
        self::throwIfError($status, $json, $method . ' ' . strtok($path, '?'));

        return $json['data'] ?? null;
    }

    private function token()
    {
        $key = $this->tokenKey();
        $cached = $this->tokens ? $this->tokens->get($key) : $this->token;
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$status, $json] = $this->send('POST', '/v1beta/auth/login', [
            'username' => $this->username,
            'password' => $this->password,
        ], null);
        self::throwIfError($status, $json, 'login');
        $token = $json['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new OpenproviderException('Openprovider login returned no token');
        }
        if ($this->tokens) {
            $this->tokens->set($key, $token, self::TOKEN_TTL);
        } else {
            $this->token = $token;
        }

        return $token;
    }

    private function forgetToken()
    {
        if ($this->tokens) {
            $this->tokens->forget($this->tokenKey());
        }
        $this->token = null;
    }

    /** Endpoint × username; never the password. */
    private function tokenKey()
    {
        return substr(md5($this->baseUrl() . '|' . $this->username), 0, 12);
    }

    /** @return array{0:int,1:array} */
    private function send($method, $path, $payload, $token)
    {
        $url = $this->baseUrl() . $path;
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $body = $payload === null ? null : json_encode($payload);
        [$status, $raw] = $this->http->send($method, $url, $headers, $body);

        if ($this->logger) {
            call_user_func($this->logger, $method, strtok($url, '?'), self::masked($payload), (int) $status, self::maskedResponse((string) $raw));
        }
        $json = json_decode((string) $raw, true);

        return [(int) $status, is_array($json) ? $json : []];
    }

    private static function throwIfError($status, array $json, $what)
    {
        $hasCode = array_key_exists('code', $json) && is_numeric($json['code']);
        $code = $hasCode ? (int) $json['code'] : null;
        if ($status >= 200 && $status < 300 && $code === 0) {
            return;
        }
        $desc = isset($json['desc']) && is_scalar($json['desc']) ? (string) $json['desc'] : 'no description';
        // Only a 4xx, or a 2xx carrying an Openprovider error code, says for sure that
        // nothing was done; no answer, a 5xx or a body without the envelope does not.
        $definitive = $hasCode && (($status >= 400 && $status < 500) || ($status >= 200 && $status < 300));
        throw new OpenproviderException(
            'Openprovider ' . $what . ' failed (HTTP ' . (int) $status . ', code ' . ($hasCode ? $code : 'none') . '): ' . $desc,
            $definitive
        );
    }

    private static function masked($payload)
    {
        if ($payload === null) {
            return null;
        }
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '***';
            }
        }

        return json_encode($payload);
    }

    /** Responses can carry the token (login) or a domain's auth code. */
    private static function maskedResponse($raw)
    {
        return preg_replace('/"(token|auth_code|internal_auth_code)"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/', '"$1":"***"', $raw);
    }

    private static function id($id)
    {
        $id = trim((string) $id);
        if (!ctype_digit($id)) {
            throw new OpenproviderException('Invalid Openprovider domain id');
        }

        return $id;
    }

    /** @return array{0:string,1:string} name and extension ("example", "co.uk") */
    public static function splitFqdn($fqdn)
    {
        $fqdn = trim((string) $fqdn, " \t\n\r\0\x0B.");
        if (preg_match('/[^\x20-\x7E]/', $fqdn) && function_exists('idn_to_ascii')) {
            // IDN: Openprovider stores the punycode form (non-transitional: «ς» and «ß» kept, as DomainName).
            $ascii = idn_to_ascii($fqdn, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_CONTEXTJ | IDNA_CHECK_BIDI, INTL_IDNA_VARIANT_UTS46);
            $fqdn = $ascii !== false ? $ascii : $fqdn;
        }
        $fqdn = strtolower($fqdn);
        $dot = strpos($fqdn, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($fqdn) - 1) {
            throw new OpenproviderException('Invalid domain name: ' . $fqdn);
        }

        return [substr($fqdn, 0, $dot), substr($fqdn, $dot + 1)];
    }
}
