<?php

namespace App\Services\Domains\Lookup;

use Illuminate\Support\Facades\Http;
use Nixpal\DomainTools\Http\Transport;
use Throwable;

/**
 * The domain-tools HTTP port over Laravel's client (D8a): faked in tests. No redirects (review R6 of #178: an RDAP answer
 * needs none, and one could point inward), short timeouts, at most 1 MB read.
 */
final class LaravelTransport implements Transport
{
    public const MAX_BYTES = 1048576;

    /**
     * A host on the internet, not ours (adversarial ADV-10: an RDAP «related» link to 169.254.169.254 or 127.0.0.1):
     * every address it resolves to is public.
     */
    /** @var (\Closure(string): list<string>)|null how a host resolves (tests: without DNS) */
    public static ?\Closure $resolve = null;

    public static function publicHost(string $host): bool
    {
        $host = trim($host, '[]');
        if ($host === '') {
            return false;
        }
        $ips = match (true) {
            filter_var($host, FILTER_VALIDATE_IP) !== false => [$host],
            self::$resolve !== null => (self::$resolve)($host),
            default => array_merge(array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'), array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')),
        };
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    public function get($url, array $headers = [], $timeout = 8)
    {
        if (!str_starts_with((string) $url, 'https://') || !self::publicHost((string) parse_url((string) $url, PHP_URL_HOST))) {
            return [0, ''];
        }
        $named = [];
        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', explode(':', (string) $header, 2)) + [1 => ''];
            $named[$name] = $value;
        }
        try {
            $response = Http::withHeaders($named)->withoutRedirecting()->timeout(min(6, (int) $timeout))->connectTimeout(3)->get((string) $url);
            $body = $response->body();

            return strlen($body) > self::MAX_BYTES ? [0, ''] : [$response->status(), $body];
        } catch (Throwable) {
            return [0, ''];
        }
    }
}
