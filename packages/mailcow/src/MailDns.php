<?php

namespace Nixpal\Mailcow;

/**
 * The DNS a domain needs to send and receive mail through a mailcow server, checked against what the DNS answers (owner
 * 2026-10-28: «what it needs and whether it is OK», for staff and the customer). MX, SPF and DKIM are needed; DMARC and
 * the autodiscover/autoconfig names are advised. Read only: it looks, it never changes a record.
 *
 * The resolver is given a name and a type (MX, TXT, CNAME, A) and returns the records: MX and CNAME as their target, TXT
 * as its text, A as its address (strings); none, an empty list. A row's note is a code: other_mx, spf_many, spf_not_ours,
 * dkim_other, dmarc_many.
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
        $rows = [$this->mx($domain), $this->spf($domain)];
        if ($this->dkimTxt !== null && $this->dkimTxt !== '') {
            $rows[] = $this->dkim($domain);
        }
        $rows[] = $this->dmarc($domain);
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
        $ok = $this->spfCovers($records[0], $domain, true);

        return self::row('spf', 'TXT', $domain, $expected, $records, $ok ? self::OK : self::WARN, true,
            $ok ? null : 'spf_not_ours');
    }

    /** Its mechanisms name our server: mx (the MX is ours), a:host, ip4/ip6 of it, or an include that does (one level). */
    private function spfCovers(string $record, string $domain, bool $follow): bool
    {
        foreach (preg_split('/\s+/', strtolower(trim($record))) as $term) {
            $term = ltrim($term, '+');
            if ($term === 'mx' && in_array($this->mailHost, array_map([self::class, 'host'], $this->lookup($domain, 'MX')), true)) {
                return true;
            }
            if ($term === 'mx:' . $this->mailHost || $term === 'a:' . $this->mailHost || $term === 'include:' . $this->mailHost) {
                return true;
            }
            foreach ($this->serverIps as $ip) {
                if (in_array($term, ['ip4:' . strtolower($ip), 'ip6:' . strtolower($ip)], true)) {
                    return true;
                }
            }
            if ($follow && strpos($term, 'include:') === 0) {
                foreach (self::spfRecords($this->lookup(substr($term, 8), 'TXT')) as $included) {
                    if ($this->spfCovers($included, $domain, false)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function dkim(string $domain): array
    {
        $name = ($this->dkimSelector ?: 'dkim') . '._domainkey.' . $domain;
        $found = array_values(array_filter($this->lookup($name, 'TXT'), fn ($txt) => stripos($txt, 'p=') !== false));
        $ours = self::dkimKey((string) $this->dkimTxt);
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
        try {
            return array_values(array_filter(array_map('strval', (array) ($this->resolve)($name, $type)), fn ($v) => $v !== ''));
        } catch (\Throwable $e) {
            return [];
        }
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
