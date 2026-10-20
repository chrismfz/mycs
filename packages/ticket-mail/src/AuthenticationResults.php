<?php

namespace MyCS\TicketMail;

/**
 * Who really sent a mail (docs/tickets.md §3 Β), from the Authentication-Results (RFC 8601) of OUR receiving server
 * only. Any sender can write an Authentication-Results header, also with our authserv-id; our server adds its own on
 * top. So only the topmost one counts, and only when its authserv-id is ours: a forged one below it is ignored, and a
 * mail where ours is missing is unauthenticated. A From is authenticated by DMARC pass for its domain, or by DKIM or
 * SPF pass of a domain aligned with it (the same, or a parent of it).
 */
final class AuthenticationResults
{
    /**
     * @param  list<string>  $headers  the Authentication-Results values, top first
     */
    public static function verdict(array $headers, string $authservId, string $fromEmail): AuthVerdict
    {
        $authservId = strtolower(trim($authservId));
        $fromDomain = self::domainOf($fromEmail);
        if ($authservId === '' || $fromDomain === null) {
            return new AuthVerdict(false, $authservId === '' ? 'no_authserv_id' : 'no_from_domain');
        }
        if ($headers === []) {
            return new AuthVerdict(false, 'no_results');
        }

        $parsed = self::parse($headers[0]);
        if ($parsed === null || $parsed['authserv_id'] !== $authservId) {
            return new AuthVerdict(false, 'not_our_server');
        }

        foreach ($parsed['results'] as $result) {
            if ($result['result'] !== 'pass') {
                continue;
            }
            $domain = match ($result['method']) {
                'dmarc' => self::domainOf($result['props']['header.from'] ?? $fromEmail),
                'dkim' => self::domainOf($result['props']['header.d'] ?? $result['props']['header.i'] ?? ''),
                'spf' => self::domainOf($result['props']['smtp.mailfrom'] ?? ''),
                default => null,
            };
            if ($domain !== null && self::aligned($fromDomain, $domain, $result['method'] === 'dmarc')) {
                return new AuthVerdict(true, $result['method'] . '_pass');
            }
        }

        return new AuthVerdict(false, 'no_aligned_pass');
    }

    /**
     * One Authentication-Results value: its authserv-id and each method's result with its properties.
     *
     * @return array{authserv_id: string, results: list<array{method: string, result: string, props: array<string, string>}>}|null
     */
    public static function parse(string $value): ?array
    {
        $value = self::withoutComments(str_replace(["\r", "\n"], ' ', $value));
        $parts = array_map('trim', explode(';', $value));
        $id = strtolower((string) preg_replace('/\s+\d+$/', '', (string) array_shift($parts)));
        if ($id === '' || preg_match('/^[a-z0-9._-]+$/', $id) !== 1) {
            return null;
        }

        $results = [];
        foreach ($parts as $part) {
            if (preg_match('/^([a-z0-9_-]+)(?:\/\d+)?\s*=\s*([a-z]+)(.*)$/i', $part, $m) !== 1) {
                continue;
            }
            $props = [];
            if (preg_match_all('/([a-z0-9_-]+\.[a-z0-9_.-]+)\s*=\s*("[^"]*"|[^\s;]+)/i', $m[3], $pairs, PREG_SET_ORDER) > 0) {
                foreach ($pairs as $pair) {
                    $props[strtolower($pair[1])] ??= strtolower(trim($pair[2], '"'));
                }
            }
            $results[] = ['method' => strtolower($m[1]), 'result' => strtolower($m[2]), 'props' => $props];
        }

        return ['authserv_id' => $id, 'results' => $results];
    }

    /** Aligned (DMARC relaxed alignment): the same domain, or the From's domain under it. DMARC's own: the same. */
    private static function aligned(string $fromDomain, string $domain, bool $exact): bool
    {
        if ($fromDomain === $domain) {
            return true;
        }

        // A parent with at least one dot of its own (never a bare TLD): mail.example.com under example.com.
        return !$exact && str_contains($domain, '.') && str_ends_with($fromDomain, '.' . $domain);
    }

    /** The domain of an address (or a domain as such), lower case; null when there is none. */
    public static function domainOf(string $address): ?string
    {
        $address = strtolower(trim($address, " \t<>\""));
        $domain = str_contains($address, '@') ? substr($address, strrpos($address, '@') + 1) : $address;

        return preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain) === 1 ? $domain : null;
    }

    private static function withoutComments(string $value): string
    {
        // Nested comments (RFC 5322): strip the innermost until none is left.
        for ($i = 0; $i < 10 && str_contains($value, '('); $i++) {
            $value = (string) preg_replace('/\([^()]*\)/', ' ', $value);
        }

        return $value;
    }
}
