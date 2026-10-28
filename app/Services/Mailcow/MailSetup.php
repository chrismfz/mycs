<?php

namespace App\Services\Mailcow;

use App\Models\MailcowDomain;
use Illuminate\Support\Facades\Cache;
use Nixpal\Mailcow\MailDns;

/**
 * A mail domain's DNS as its mailcow server needs it, checked (MailDns), kept 10 minutes: a page never waits on the DNS
 * again until then, or until staff or the customer ask to check again.
 */
final class MailSetup
{
    public const TTL = 600;

    /** @var (callable(string, string): list<string>)|null for tests */
    public static $resolve = null;

    /** @return array{rows: list<array<string, mixed>>, checked_at: int, ok: bool, status: string} */
    public static function check(MailcowDomain $domain, bool $fresh = false): array
    {
        $key = 'mailcow.dns.' . $domain->id . '.' . md5(implode('|', [$domain->dkim_selector, $domain->dkim_txt, $domain->server_id, self::host($domain)]));
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TTL, function () use ($domain) {
            $host = self::host($domain);
            $resolve = self::$resolve ?? self::dns(...);
            $rows = (new MailDns($resolve, $host, $host === '' ? [] : $resolve($host, 'A'), $domain->dkim_selector, $domain->dkim_txt))->check($domain->domain);

            return ['rows' => $rows, 'checked_at' => time(), 'ok' => MailDns::allNeededOk($rows), 'status' => self::status($rows)];
        });
    }

    /** For the list (owner 2026-10-28: a plain ✓ or ✗): ok only when every record is, needed or advised. */
    public static function status(array $rows): string
    {
        return self::issues($rows) === [] ? MailDns::OK : MailDns::FAIL;
    }

    /** @return list<string> the records that are not right, by name (MX, SPF, DKIM, DMARC, AUTODISCOVER, AUTOCONFIG) */
    public static function issues(array $rows): array
    {
        return array_values(array_map(fn (array $row) => strtoupper($row['key']), array_filter($rows, fn (array $row) => $row['status'] !== MailDns::OK)));
    }

    /** Each live domain of the server checked again, its status kept on it (after the sync; a DNS that fails is a fail). */
    public static function refreshStatuses(int $serverId): void
    {
        foreach (MailcowDomain::query()->where('server_id', $serverId)->whereNull('gone_at')->with('server.settings')->get() as $domain) {
            $check = self::check($domain, fresh: true);
            $domain->update(['dns_status' => $check['status'], 'dns_issues' => implode(', ', self::issues($check['rows'])) ?: null, 'dns_checked_at' => now()]);
        }
    }

    /** The server's mail hostname (its «host» setting, a URL or a name). */
    public static function host(MailcowDomain $domain): string
    {
        $host = trim((string) $domain->server?->settings->firstWhere('key', 'host')?->value);

        return strtolower(rtrim((string) (str_contains($host, '://') ? parse_url($host, PHP_URL_HOST) : $host), '.'));
    }

    /** @return list<string> */
    private static function dns(string $name, string $type): array
    {
        $records = @dns_get_record($name, match ($type) {
            'MX' => DNS_MX, 'TXT' => DNS_TXT, 'CNAME' => DNS_CNAME, default => DNS_A,
        }) ?: [];

        return array_values(array_filter(array_map(fn (array $r) => (string) match ($type) {
            'MX', 'CNAME' => $r['target'] ?? '',
            'TXT' => isset($r['entries']) ? implode('', (array) $r['entries']) : ($r['txt'] ?? ''),
            default => $r['ip'] ?? '',
        }, $records)));
    }
}
