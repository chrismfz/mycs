<?php

namespace MyCS\TicketMail;

/**
 * Who really sent a mail (docs/tickets.md §3 Β), from the Authentication-Results (RFC 8601) of OUR receiving server
 * only. Any sender can write an Authentication-Results header, also with our authserv-id; our server adds its own on
 * top. So only the topmost one counts, and only when its authserv-id is ours: a forged one below it is ignored, and a
 * mail where ours is missing is unauthenticated. One that does not read cleanly (see parse) vouches for nothing. A From is authenticated by DMARC pass for its domain (which allows
 * a related domain, checked by our server with the public suffix list), or by DKIM or SPF pass of its domain or a
 * parent of it (never a subdomain of it: a third party may control one).
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
        if ($parsed === null) {
            return new AuthVerdict(false, 'unreadable_results');
        }
        if ($parsed['authserv_id'] !== $authservId) {
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
            // DMARC's own pass names exactly the From's domain (our server already allowed a related one, with the public
            // suffix list). A DKIM or SPF pass: the same domain, or a parent of the From's (its owner signs for all of
            // it), never a subdomain of it, which a third party may control (security audit API-10).
            $parent = $result['method'] !== 'dmarc' && $domain !== null && str_contains($domain, '.') && str_ends_with($fromDomain, '.' . $domain);
            if ($domain !== null && ($domain === $fromDomain || $parent)) {
                return new AuthVerdict(true, $result['method'] . '_pass');
            }
        }

        return new AuthVerdict(false, 'no_aligned_pass');
    }

    /**
     * One Authentication-Results value: its authserv-id and each method's result with its properties. Null when it does
     * not read cleanly, and then it vouches for nothing.
     *
     * Our server writes the header, but it copies into it text the sender chose (the envelope sender inside the SPF
     * comment, the DKIM domain), and not every server escapes it. So it is read strictly, as the few shapes real servers
     * write (RFC 8601 §2.2), and whatever else is refused whole, never skipped:
     * - a comment ends at its «)»; a comment inside a comment, a backslash in one, or a parenthesis inside quotes in one
     *   is refused (there the sender's text and the server's cannot be told apart);
     * - outside comments, a quote, a backslash, a stray «)» or a control character is refused: a value is one plain
     *   word (a quoted envelope sender is the sender's text; such a mail is not vouched for);
     * - a statement is «method=result» and then «name=value» words only; «ptype.property» ones are its properties, each
     *   once; nothing is ever read out of a comment or out of another value;
     * - one DMARC result per mail, and a method that says «none» says nothing else.
     *
     * @return array{authserv_id: string, results: list<array{method: string, result: string, props: array<string, string>}>}|null
     */
    public static function parse(string $value): ?array
    {
        $statements = self::statements(str_replace(["\r\n", "\r", "\n"], ' ', $value));
        if ($statements === null) {
            return null;
        }
        // The authserv-id, and at most its version.
        $first = array_shift($statements);
        $id = strtolower($first[0] ?? '');
        if (preg_match('/^[a-z0-9._-]+$/', $id) !== 1 || count($first) > 2 || (isset($first[1]) && !ctype_digit($first[1]))) {
            return null;
        }

        $results = [];
        $seen = [];
        foreach ($statements as $words) {
            // Nothing between two «;» (or after the last), or the «none» of a server that checked nothing.
            if ($words === [] || (count($words) === 1 && strtolower($words[0]) === 'none')) {
                continue;
            }
            if (preg_match('/^([a-z0-9-]+)(?:\/\d+)?=([a-z]+)$/i', array_shift($words), $m) !== 1) {
                return null;
            }
            [$method, $result] = [strtolower($m[1]), strtolower($m[2])];
            $props = [];
            foreach ($words as $word) {
                if (preg_match('/^([a-z0-9_-]+)(\.[a-z0-9_-]+)?=(.*)$/is', $word, $pair) !== 1) {
                    return null;
                }
                if ($pair[2] === '') {
                    // «reason=…», or a server's own word («action=none»): not a property.
                    continue;
                }
                $name = strtolower($pair[1] . $pair[2]);
                if (array_key_exists($name, $props)) {
                    return null;
                }
                $props[$name] = strtolower($pair[3]);
            }
            $seen[$method][] = $result;
            $results[] = ['method' => $method, 'result' => $result, 'props' => $props];
        }
        foreach ($seen as $method => $said) {
            if (count($said) > 1 && ($method === 'dmarc' || in_array('none', $said, true))) {
                return null;
            }
        }

        return ['authserv_id' => $id, 'results' => $results];
    }

    /**
     * The words of each statement of a header (split at the «;»), comments dropped; null when it is not clean (see parse).
     *
     * @return list<list<string>>|null
     */
    private static function statements(string $value): ?array
    {
        $statements = [];
        $current = '';
        $comment = $quoted = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' || ($char !== "\t" && (ord($char) < 32 || ord($char) === 127))) {
                return null;
            }
            if ($comment) {
                if ($char === '"') {
                    $quoted = !$quoted;
                } elseif ($char === '(' || ($char === ')' && $quoted)) {
                    return null;
                } elseif ($char === ')') {
                    $comment = false;
                    $current .= ' ';
                }
            } elseif ($char === '"' || $char === ')') {
                return null;
            } elseif ($char === '(') {
                $comment = true;
            } elseif ($char === ';') {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if ($comment) {
            return null;
        }
        $statements[] = $current;

        return array_map(fn (string $statement) => preg_split('/\s+/', $statement, -1, PREG_SPLIT_NO_EMPTY) ?: [], $statements);
    }

    /** The domain of an address (or a domain as such), lower case; null when there is none. */
    public static function domainOf(string $address): ?string
    {
        $address = strtolower(trim($address, " \t<>\""));
        $domain = str_contains($address, '@') ? substr($address, strrpos($address, '@') + 1) : $address;

        return preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain) === 1 ? $domain : null;
    }
}
