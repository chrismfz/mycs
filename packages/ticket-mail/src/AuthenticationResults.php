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
                // Its own From domain, as our server read it: without one, no pass counts.
                'dmarc' => self::domainOf($result['props']['header.from'] ?? ''),
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
        $parts = self::statements(str_replace(["\r", "\n"], ' ', $value));
        $id = strtolower((string) preg_replace('/\s+\d+$/', '', trim((string) array_shift($parts))));
        if ($id === '' || preg_match('/^[a-z0-9._-]+$/', $id) !== 1) {
            return null;
        }

        $results = [];
        foreach ($parts as $part) {
            // A method's result opens its statement; a quoted value is one token (the sender chose it).
            if (preg_match('/^([a-z0-9_-]+)(?:\/\d+)?\s*=\s*([a-z]+)(.*)$/is', trim($part), $m) !== 1) {
                continue;
            }
            $props = [];
            if (preg_match_all('/(?:^|\s)([a-z0-9_-]+\.[a-z0-9_.-]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^\s"]+)/i', $m[3], $pairs, PREG_SET_ORDER) > 0) {
                foreach ($pairs as $pair) {
                    $props[strtolower($pair[1])] ??= strtolower(trim($pair[2], '"'));
                }
            }
            $results[] = ['method' => strtolower($m[1]), 'result' => strtolower($m[2]), 'props' => $props];
        }

        return ['authserv_id' => $id, 'results' => $results];
    }

    /**
     * The statements of a header, split at the «;» outside quoted strings and comments, comments dropped (RFC 8601,
     * RFC 5322). A «;» or a «dmarc=pass» inside the sender's quoted envelope address or a comment stays inside it.
     *
     * @return list<string>
     */
    private static function statements(string $value): array
    {
        $statements = [];
        $current = '';
        $quoted = false;
        $depth = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' && ($quoted || $depth > 0) && $i + 1 < $length) {
                if ($depth === 0) {
                    $current .= $char . $value[$i + 1];
                }
                $i++;

                continue;
            }
            if ($depth > 0) {
                $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
                if ($depth === 0) {
                    $current .= ' ';
                }

                continue;
            }
            if ($quoted) {
                $current .= $char;
                $quoted = $char !== '"';

                continue;
            }
            if ($char === '"') {
                $quoted = true;
                $current .= $char;
            } elseif ($char === '(') {
                $depth = 1;
            } elseif ($char === ';') {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $statements[] = $current;

        return $statements;
    }

    /** Aligned (DMARC relaxed alignment): the same domain, a parent or a subdomain of it. DMARC's own: the same. */
    private static function aligned(string $fromDomain, string $domain, bool $exact): bool
    {
        if ($fromDomain === $domain) {
            return true;
        }

        // The same organisation either way (relaxed alignment, without the public suffix list): a parent with a dot of
        // its own (never a bare TLD), or a subdomain of the From's domain. mail.example.com and example.com align.
        return !$exact && ((str_contains($domain, '.') && str_ends_with($fromDomain, '.' . $domain)) || str_ends_with($domain, '.' . $fromDomain));
    }

    /** The domain of an address (or a domain as such), lower case; null when there is none. */
    public static function domainOf(string $address): ?string
    {
        $address = strtolower(trim($address, " \t<>\""));
        $domain = str_contains($address, '@') ? substr($address, strrpos($address, '@') + 1) : $address;

        return preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain) === 1 ? $domain : null;
    }
}
