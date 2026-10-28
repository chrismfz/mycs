<?php

namespace Nixpal\Mailcow;

/**
 * The DNS a domain needs to send and receive mail through a mailcow server, checked against what the DNS answers (owner
 * 2026-10-28: «what it needs and whether it is OK», for staff and the customer). MX, SPF and DKIM are needed; DMARC and
 * the autodiscover/autoconfig names are advised. Read only: it looks, it never changes a record.
 *
 * The resolver is given a name and a type (MX, TXT, CNAME, A) and returns the records: MX and CNAME as their target, TXT
 * as its text, A as its address (strings); none, an empty list. Each name and type is looked up once per check, and SPF
 * spends at most the 10 lookups RFC 7208 allows, so a domain's DNS can never make it ask without end. A row's note
 * is a code: other_mx, spf_many, spf_not_ours, dkim_none, dkim_other, dmarc_many.
 */
final class MailDns
{
    const OK = 'ok';

    const WARN = 'warn';

    const FAIL = 'fail';

    /** @var callable(string, string): list<string> */
    private $resolve;

    /** @var string */
    private $mailHost;

    /** @var list<string> */
    private $serverIps;

    /** @var string|null */
    private $dkimSelector;

    /** @var string|null */
    private $dkimTxt;

    /** @var array<string, list<string>> lookups of this check, each once */
    private $memo = [];

    /** @var int SPF lookups spent in this check: a, mx, include, redirect, exists, ptr (RFC 7208: 10) */
    private $spfLookups = 0;

    const SPF_LOOKUPS = 10;

    /**
     * @param  callable(string, string): list<string>  $resolve
     * @param  string  $mailHost  the server's hostname (its MX target)
     * @param  array<int, string>  $serverIps  its addresses
     * @param  string|null  $dkimSelector  the domain's DKIM selector at mailcow
     * @param  string|null  $dkimTxt  its DKIM record at mailcow (none: DKIM not made yet)
     */
    public function __construct(callable $resolve, $mailHost, array $serverIps, $dkimSelector, $dkimTxt)
    {
        $this->resolve = $resolve;
        $this->mailHost = self::host((string) $mailHost);
        $this->serverIps = array_values(array_map('strval', $serverIps));
        $this->dkimSelector = $dkimSelector === null ? null : (string) $dkimSelector;
        $this->dkimTxt = $dkimTxt === null ? null : (string) $dkimTxt;
    }

    /**
     * Each record: what it is, where, what it should hold, what the DNS holds, and its status.
     *
     * @return list<array{key: string, type: string, name: string, expected: string, found: list<string>, status: string, required: bool, note: ?string}>
     */
    public function check(string $domain): array
    {
        $domain = self::host($domain);
        $this->memo = [];
        $this->spfLookups = 0;
        $rows = [$this->mx($domain), $this->spf($domain), $this->dkim($domain), $this->dmarc($domain)];
        foreach (['autodiscover', 'autoconfig'] as $name) {
            $rows[] = $this->alias($name, $domain);
        }

        return $rows;
    }

    /** OK only when every needed record is. */
    public static function allNeededOk(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['required'] && $row['status'] !== self::OK) {
                return false;
            }
        }

        return true;
    }

    private function mx(string $domain): array
    {
        $found = array_map([self::class, 'host'], $this->lookup($domain, 'MX'));
        $status = in_array($this->mailHost, $found, true) ? ($found === [$this->mailHost] ? self::OK : self::WARN) : self::FAIL;

        return self::row('mx', 'MX', $domain, '10 ' . $this->mailHost, $found, $status, true,
            $status === self::WARN ? 'other_mx' : null);
    }

    private function spf(string $domain): array
    {
        $records = self::spfRecords($this->lookup($domain, 'TXT'));
        $expected = 'v=spf1 mx ~all';
        if ($records === []) {
            return self::row('spf', 'TXT', $domain, $expected, [], self::FAIL, true, null);
        }
        if (count($records) > 1) {
            return self::row('spf', 'TXT', $domain, $expected, $records, self::FAIL, true, 'spf_many');
        }
        $ok = $this->spfCovers($records[0], $domain);

        return self::row('spf', 'TXT', $domain, $expected, $records, $ok ? self::OK : self::WARN, true,
            $ok ? null : 'spf_not_ours');
    }

    /**
     * Whether the record lets our server send, as a receiver evaluates it (RFC 7208 §4.6): its terms left to right, the
     * first mechanism that matches decides, by its qualifier (only «+» lets it); a redirect only when there is no «all».
     * a, mx, include, redirect, exists and ptr each spend one of the 10 lookups the RFC allows for the whole check: past
     * them, it does not.
     */
    private function spfCovers(string $record, string $domain): bool
    {
        $redirect = null;
        foreach (preg_split('/\s+/', strtolower(trim($record))) as $term) {
            if ($term === '' || $term === 'v=spf1') {
                continue;
            }
            if (strpos($term, 'redirect=') === 0) {
                $redirect = substr($term, 9);

                continue;
            }
            if (strpos($term, '=') !== false) {
                continue; // another modifier (exp=)
            }
            $qualifier = in_array($term[0], ['+', '-', '~', '?'], true) ? $term[0] : '+';
            $term = ltrim($term, '+-~?');
            [$mechanism, $value] = array_pad(explode(':', $term, 2), 2, null);
            $mechanism = (string) preg_replace('#/\d+(//\d+)?$#', '', $mechanism);
            $target = $value === null ? $domain : (string) preg_replace('#/\d+(//\d+)?$#', '', $value);
            if ($mechanism === 'all') {
                return $qualifier === '+'; // so a redirect is never followed when there is an «all»
            }
            if (in_array($mechanism, ['a', 'mx', 'include', 'exists', 'ptr'], true) && !$this->spend()) {
                return false; // past the RFC's 10: a receiver's permerror
            }
            $matches = false;
            if ($mechanism === 'ip4') {
                $matches = $value !== null && $this->coversIp($value);
            } elseif ($mechanism === 'a') {
                $matches = array_intersect($this->lookup($target, 'A'), $this->serverIps) !== [];
            } elseif ($mechanism === 'mx') {
                $matches = in_array($this->mailHost, array_map([self::class, 'host'], $this->lookup($target, 'MX')), true);
            } elseif ($mechanism === 'include') {
                $matches = $value !== null && $this->included($value);
            }
            if ($matches) {
                return $qualifier === '+';
            }
        }
        if ($redirect === null || $redirect === '' || !$this->spend()) {
            return false;
        }

        return $this->included($redirect);
    }

    /** One of the RFC's 10 lookups spent; false when none is left. */
    private function spend(): bool
    {
        return ++$this->spfLookups <= self::SPF_LOOKUPS;
    }

    /** An included or redirected domain's SPF (one record) lets our server send. */
    private function included(string $domain): bool
    {
        $records = self::spfRecords($this->lookup($domain, 'TXT'));

        return count($records) === 1 && $this->spfCovers($records[0], $domain);
    }

    /** One of our IPv4 addresses within «a.b.c.d» or «a.b.c.d/n». */
    private function coversIp(string $value): bool
    {
        [$network, $bits] = array_pad(explode('/', $value, 2), 2, '32');
        $net = ip2long($network);
        $bits = (int) $bits;
        if ($net === false || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
        foreach ($this->serverIps as $ip) {
            $long = ip2long($ip);
            if ($long !== false && ($long & $mask) === ($net & $mask)) {
                return true;
            }
        }

        return false;
    }

    private function dkim(string $domain): array
    {
        $name = ($this->dkimSelector ?: 'dkim') . '._domainkey.' . $domain;
        $ours = self::dkimKey((string) $this->dkimTxt);
        if ($ours === '') {
            // No key at the mail server: nothing it sends is signed, whatever the DNS says (review of #494).
            return self::row('dkim', 'TXT', $name, '', [], self::FAIL, true, 'dkim_none');
        }
        $found = array_values(array_filter($this->lookup($name, 'TXT'), fn ($txt) => stripos($txt, 'p=') !== false));
        $status = $found === [] ? self::FAIL : (in_array($ours, array_map([self::class, 'dkimKey'], $found), true) ? self::OK : self::WARN);

        return self::row('dkim', 'TXT', $name, (string) $this->dkimTxt, $found, $status, true,
            $status === self::WARN ? 'dkim_other' : null);
    }

    private function dmarc(string $domain): array
    {
        $name = '_dmarc.' . $domain;
        $found = array_values(array_filter($this->lookup($name, 'TXT'), fn ($txt) => stripos(ltrim($txt), 'v=DMARC1') === 0));

        return self::row('dmarc', 'TXT', $name, 'v=DMARC1; p=quarantine', $found, count($found) === 1 ? self::OK : self::WARN, false,
            count($found) > 1 ? 'dmarc_many' : null);
    }

    private function alias(string $label, string $domain): array
    {
        $name = $label . '.' . $domain;
        $targets = array_map([self::class, 'host'], $this->lookup($name, 'CNAME'));
        $ok = in_array($this->mailHost, $targets, true);
        if (!$ok && $targets === []) {
            // An A record to the server's address does as well.
            $ok = array_intersect($this->lookup($name, 'A'), $this->serverIps) !== [];
        }

        return self::row($label, 'CNAME', $name, $this->mailHost, $targets ?: $this->lookup($name, 'A'), $ok ? self::OK : self::WARN, false, null);
    }

    /** @return list<string> */
    private function lookup(string $name, string $type): array
    {
        $key = $type . ' ' . strtolower($name);
        if (!array_key_exists($key, $this->memo)) {
            try {
                $this->memo[$key] = array_values(array_filter(array_map('strval', (array) ($this->resolve)($name, $type)), fn ($v) => $v !== ''));
            } catch (\Throwable $e) {
                $this->memo[$key] = [];
            }
        }

        return $this->memo[$key];
    }

    /** @return list<string> */
    private static function spfRecords(array $txts): array
    {
        return array_values(array_filter($txts, fn ($txt) => preg_match('/^v=spf1(\s|$)/i', trim($txt)) === 1));
    }

    private static function dkimKey(string $txt): string
    {
        return preg_match('/(?:^|;)\s*p=([^;]*)/i', $txt, $m) === 1 ? preg_replace('/\s+/', '', $m[1]) : '';
    }

    private static function host(string $host): string
    {
        $host = strtolower(trim($host));
        if (strpos($host, '://') !== false) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }

        return rtrim($host, '.');
    }

    private static function row(string $key, string $type, string $name, string $expected, array $found, string $status, bool $required, ?string $note): array
    {
        return ['key' => $key, 'type' => $type, 'name' => $name, 'expected' => $expected, 'found' => array_values($found), 'status' => $status,
            'required' => $required, 'note' => $note];
    }
}
