<?php

namespace Nixpal\Mailcow;

use Nixpal\Mailcow\Http\Transport;

/**
 * mailcow API v1 client for what a hosting panel needs per service: one domain, its
 * domain admin, DKIM, an overview of mailboxes/aliases, and SSO for the domain admin.
 *
 * Ported from our WHMCS module (modules/servers/mailcow/MailcowAPI.php) without WHMCS:
 * the package limits arrive as a DomainLimits object, HTTP goes through a Transport.
 * mailcow reports many errors as HTTP 200 with [{"type":"error","msg":...}]; those
 * throw MailcowException like HTTP errors do.
 */
final class MailcowClient
{
    const DEFAULT_SSO_ENDPOINT = '/api/v1/add/sso/domain-admin';

    /** @var Transport */
    private $http;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $ssoEndpoint;

    /** @var callable(string,string,?string,int,string):void|null */
    private $logger;

    /**
     * @param  string  $host  hostname, IP or URL of the mailcow server (https is assumed)
     * @param  callable|null  $logger  fn(method, url, requestBody, status, responseBody); never sees the API key
     */
    public function __construct(Transport $http, $host, $apiKey, $ssoEndpoint = null, $logger = null)
    {
        $this->baseUrl = self::baseUrl($host);
        if ((string) $apiKey === '') {
            throw new MailcowException('mailcow API key is missing');
        }
        $this->http = $http;
        $this->apiKey = (string) $apiKey;
        $endpoint = trim((string) ($ssoEndpoint ?: self::DEFAULT_SSO_ENDPOINT));
        $this->ssoEndpoint = $endpoint[0] === '/' ? $endpoint : '/' . $endpoint;
        $this->logger = $logger;
    }

    /** https://host from a hostname, IP or URL; throws when empty. */
    public static function baseUrl($host)
    {
        $host = trim((string) $host);
        if ($host === '') {
            throw new MailcowException('mailcow server hostname is missing');
        }
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }

        return rtrim($host, '/');
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    // ---- provisioning ------------------------------------------------------------------

    public function addDomain($domain, DomainLimits $limits, $restartSogo = true)
    {
        return $this->post('/api/v1/add/domain', $limits->toAttr($domain) + [
            'domain' => (string) $domain,
            'active' => '1',
            'restart_sogo' => $restartSogo ? '1' : '0',
            'tags' => (string) $domain,
        ]);
    }

    /** Only the limits: description, backup MX and relay settings made in mailcow stay. */
    public function editDomain($domain, DomainLimits $limits)
    {
        return $this->post('/api/v1/edit/domain', ['items' => (string) $domain, 'attr' => $limits->toLimitAttr()]);
    }

    public function removeDomain($domain)
    {
        return $this->post('/api/v1/delete/domain', ['items' => (string) $domain]);
    }

    public function addDomainAdmin($domain, $username, $password)
    {
        return $this->post('/api/v1/add/domain-admin', [
            'active' => '1',
            'domains' => (string) $domain,
            'username' => (string) $username,
            'password' => (string) $password,
            'password2' => (string) $password,
        ]);
    }

    /*
     * mailcow's edit/domain-admin rebuilds the admin's domain list from "domains", so every
     * edit sends the admin's full current list ($domains), never just this service's domain;
     * fields left out keep their value (the password change does not touch "active").
     */

    /** @param string[] $domains the admin's full domain list */
    public function changeDomainAdminPassword(array $domains, $username, $password)
    {
        return $this->editDomainAdmin($username, [
            'domains' => array_values($domains),
            'username_new' => (string) $username,
            'password' => (string) $password,
            'password2' => (string) $password,
        ]);
    }

    /** @param string[] $domains the admin's full domain list */
    public function setDomainAdminActive(array $domains, $username, $active)
    {
        return $this->editDomainAdmin($username, [
            'domains' => array_values($domains),
            'username_new' => (string) $username,
            'active' => $active ? 1 : 0,
        ]);
    }

    /** Sets the admin's domain list (add or drop one domain of a multi-domain admin). */
    public function setDomainAdminDomains($username, array $domains)
    {
        return $this->editDomainAdmin($username, [
            'domains' => array_values($domains),
            'username_new' => (string) $username,
        ]);
    }

    private function editDomainAdmin($username, array $attr)
    {
        return $this->post('/api/v1/edit/domain-admin', ['items' => [(string) $username], 'attr' => $attr]);
    }

    public function removeDomainAdmin($username)
    {
        return $this->post('/api/v1/delete/domain-admin', ['items' => (string) $username]);
    }

    /**
     * The domain's DKIM record at mailcow (dkim_selector, dkim_txt), or none yet (an empty answer); any other answer throws.
     *
     * @return array<string, mixed>
     */
    public function getDkim($domain)
    {
        $json = $this->get('/api/v1/get/dkim/' . rawurlencode((string) $domain));
        if ($json === []) { // mailcow's «none» is an empty list or object; a body that is no JSON (a proxy's page) is no «none»
            return [];
        }
        if (!is_array($json) || empty($json['dkim_txt'])) {
            // Anything else is no «none»: a stored record is never wiped by it (review of #494).
            throw new MailcowException('mailcow: unexpected DKIM answer for ' . $domain);
        }

        return $json;
    }

    /** DKIM for the domain, created with the given selector/key size when it has none. */
    public function ensureDkim($domain, $selector = 'dkim', $keySize = 2048)
    {
        $existing = $this->get('/api/v1/get/dkim/' . rawurlencode((string) $domain));
        if (is_array($existing) && !empty($existing['dkim_txt'])) {
            return $existing;
        }

        return $this->post('/api/v1/add/dkim', [
            'domains' => (string) $domain,
            'dkim_selector' => (string) $selector,
            'key_size' => (string) $keySize,
        ]);
    }

    // ---- reading ---------------------------------------------------------------------

    /**
     * Every domain on the server, as mailcow lists them (an inventory: read only).
     *
     * @return list<array<string, mixed>>
     */
    public function listDomains()
    {
        return $this->listOf('/api/v1/get/domain/all', 'domain_name');
    }

    /**
     * Every mailbox on the server, with its quota and use (an inventory: read only).
     *
     * @return list<array<string, mixed>>
     */
    public function listMailboxes()
    {
        return $this->listOf('/api/v1/get/mailbox/all', 'username');
    }

    /**
     * A list of rows, each with its key field; anything else (an error, a message) is no list: thrown, so that a failed
     * read is never taken for an empty server.
     *
     * @return list<array<string, mixed>>
     */
    private function listOf($path, $key)
    {
        $rows = $this->get($path);
        if ($rows === [] || $rows === null) {
            return [];
        }
        if (!is_array($rows) || array_values($rows) !== $rows || array_filter($rows, fn ($row) => !is_array($row) || !isset($row[$key])) !== []) {
            throw new MailcowException("mailcow: unexpected answer for {$path}");
        }

        return $rows;
    }

    /** Every domain admin (one request; check many usernames against it in memory). */
    public function getDomainAdmins()
    {
        $rows = $this->get('/api/v1/get/domain-admin/all');

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** The domain admin's record, or [] when it does not exist. */
    public function getDomainAdmin($username, ?array $admins = null)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return [];
        }
        foreach ($admins ?? $this->getDomainAdmins() as $row) {
            if ((string) ($row['username'] ?? '') === $username) {
                return $row;
            }
        }

        return [];
    }

    /** Every domain the domain admin manages (one service can cover several). */
    public function getDomainAdminDomains($username)
    {
        $record = $this->getDomainAdmin($username);

        return $record ? self::parseDomainList($record['selected_domains'] ?? '') : [];
    }

    /** @param string|array $field mailcow returns an array or a comma/space separated string */
    public static function parseDomainList($field)
    {
        $parts = is_array($field)
            ? $field
            : preg_split('/[\s,]+/', (string) $field, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ((array) $parts as $part) {
            $part = strtolower(trim((string) $part));
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }

    public function getDomain($domain)
    {
        $json = $this->get('/api/v1/get/domain/' . rawurlencode((string) $domain));
        if (is_array($json) && isset($json[0]) && is_array($json[0])) {
            return $json[0];
        }

        return is_array($json) ? $json : [];
    }

    /**
     * Domain info, mailboxes and aliases for each domain; mailboxes and aliases are
     * fetched once for all domains (the WHMCS module fetched them per domain).
     *
     * @param  string[]  $domains
     * @return array<int,array{domain:array,mailboxes:array,aliases:array}>
     */
    public function overview(array $domains)
    {
        if ($domains === []) {
            return [];
        }
        $mailboxes = $this->get('/api/v1/get/mailbox/all');
        $aliases = $this->get('/api/v1/get/alias/all');
        $out = [];
        foreach ($domains as $domain) {
            $suffix = '@' . strtolower((string) $domain);
            $out[] = [
                'domain' => $this->getDomain($domain) + ['domain_name' => (string) $domain],
                'mailboxes' => self::endingWith(is_array($mailboxes) ? $mailboxes : [], 'username', $suffix),
                'aliases' => self::endingWith(is_array($aliases) ? $aliases : [], 'address', $suffix),
            ];
        }

        return $out;
    }

    /** One-time login token for the domain admin (mailcow >= 2025-01 SSO API). */
    public function issueSsoToken($username)
    {
        $token = Sso::extractToken($this->post($this->ssoEndpoint, ['username' => (string) $username]));
        if ($token === '') {
            throw new MailcowException('mailcow returned no SSO token');
        }

        return $token;
    }

    public function testConnection()
    {
        return $this->get('/api/v1/get/status/version');
    }

    // ---- delete_all termination --------------------------------------------------------

    public function removeDomainMailboxes($domain)
    {
        $all = $this->get('/api/v1/get/mailbox/all');
        $usernames = array_column(self::endingWith(is_array($all) ? $all : [], 'username', '@' . strtolower((string) $domain)), 'username');

        return $usernames ? $this->post('/api/v1/delete/mailbox', $usernames) : [];
    }

    public function removeDomainAliases($domain)
    {
        $all = $this->get('/api/v1/get/alias/all');
        $ids = array_column(self::endingWith(is_array($all) ? $all : [], 'address', '@' . strtolower((string) $domain)), 'id');

        return $ids ? $this->post('/api/v1/delete/alias', $ids) : [];
    }

    // ---- internals -----------------------------------------------------------------------

    private static function endingWith(array $rows, $field, $suffix)
    {
        $out = [];
        $n = strlen($suffix);
        foreach ($rows as $row) {
            $value = is_array($row) ? strtolower((string) ($row[$field] ?? '')) : '';
            if ($value !== '' && substr($value, -$n) === $suffix) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function get($path)
    {
        return $this->request('GET', $path, null);
    }

    private function post($path, $body)
    {
        return $this->request('POST', $path, $body);
    }

    /** @return mixed decoded JSON */
    private function request($method, $path, $body)
    {
        $url = $this->baseUrl . $path;
        $json = $body === null ? null : json_encode($body);
        [$status, $raw] = $this->http->send($method, $url, [
            'X-API-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $json);
        if ($this->logger) {
            call_user_func($this->logger, $method, $url, self::maskPasswords($json), (int) $status, (string) $raw);
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            self::throwIfError($decoded, $path, (int) $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new MailcowException('mailcow HTTP ' . (int) $status . ' for ' . $path);
        }

        return $decoded;
    }

    private static function throwIfError(array $json, $path, $status)
    {
        $rows = isset($json['type']) ? [$json] : $json;
        foreach ($rows as $row) {
            if (is_array($row) && in_array($row['type'] ?? '', ['error', 'danger'], true)) {
                $msg = $row['msg'] ?? $row['message'] ?? 'unknown error';
                $msg = is_array($msg) ? implode(' ', array_map('strval', $msg)) : (string) $msg;
                throw new MailcowException('mailcow error for ' . $path . ': ' . $msg . ' (HTTP ' . $status . ')');
            }
        }
    }

    /** Request body for the log without passwords. */
    private static function maskPasswords($json)
    {
        if ($json === null) {
            return null;
        }

        return preg_replace('/"(password2?)":"(?:[^"\\\\]|\\\\.)*"/', '"$1":"***"', $json);
    }
}
