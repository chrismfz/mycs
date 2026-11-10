<?php

namespace MyCS\TicketMail;

/**
 * Who really sent a mail (docs/tickets.md §3 Β), from the Authentication-Results (RFC 8601) of OUR receiving server
 * only. Any sender can write an Authentication-Results header, also with our authserv-id; our server adds its own on
 * top. So only the topmost one counts, and only when its authserv-id is ours: a forged one below it is ignored, and a
 * mail where ours is missing is unauthenticated. One that does not read cleanly (see parse) vouches for nothing.
 * A From is authenticated by DMARC pass for its domain (which allows a related domain, checked by our server with
 * the public suffix list), or by DKIM or SPF pass of its domain or a parent of it (never a subdomain of it: a third
 * party may control one).
 *
 * «Our server» is rspamd (mailcow): it quotes the sender's text wherever it copies it. A server that copies it
 * unquoted into a comment is not supported for vouching (docs/tickets.md, sweep 7).
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

        // DMARC's word first: when it passed, it is the reason staff read.
        $results = $parsed['results'];
        usort($results, fn (array $a, array $b) => ($b['method'] === 'dmarc') <=> ($a['method'] === 'dmarc'));
        foreach ($results as $result) {
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
     * Read as RFC 8601 §2.2 writes it and as rspamd (mailcow, the one server we support as «ours») fills it: rspamd
     * puts a value in double quotes as soon as it holds a character with a meaning here (parentheses, «;», «=», «/»,
     * a space…), so the text a sender chose (the envelope sender, also inside the SPF comment) arrives quoted.
     * - A quoted string is ONE opaque value, with its backslash escapes, as a property's value and inside a comment;
     *   what it holds is never read as statements.
     * - A comment ends at its «)»; a backslash-escaped character in it (also a parenthesis) is its text. A comment is
     *   never a source of statements: one that holds a «;» outside quotes, another comment, or a parenthesis inside
     *   quotes that is not escaped, is refused.
     * - A statement is «method=result» and then «name=value» words only; the «ptype.property» ones are its
     *   properties, each once. Nothing is read out of a comment or out of another value.
     * - One DMARC result per mail, and a method that says «none» says nothing else.
     * - Folding is CRLF (or LF) before a space or a tab; any other CR, LF or control character is refused.
     * Whatever else (a quote or comment never closed, a stray «)» or backslash, a word outside this grammar, spaces
     * around «=») is refused whole, never skipped.
     *
     * @return array{authserv_id: string, results: list<array{method: string, result: string, props: array<string, string>}>}|null
     */
    public static function parse(string $value): ?array
    {
        $statements = self::statements((string) preg_replace('/\r?\n(?=[ \t])/', '', $value));
        if ($statements === null) {
            return null;
        }
        // The authserv-id, and at most its version.
        $first = array_map(fn (array $word) => $word[1] === null ? $word[0] : '"', array_shift($statements));
        $id = strtolower($first[0] ?? '');
        if (preg_match('/^[a-z0-9._-]+$/', $id) !== 1 || count($first) > 2 || (isset($first[1]) && !ctype_digit($first[1]))) {
            return null;
        }

        $results = [];
        $seen = [];
        foreach ($statements as $words) {
            // Nothing between two «;» (or after the last), or the «none» of a server that checked nothing.
            if ($words === [] || (count($words) === 1 && $words[0][1] === null && strtolower($words[0][0]) === 'none')) {
                continue;
            }
            [$plain, $quoted] = array_shift($words);
            if ($quoted !== null || preg_match('/^([a-z0-9-]+)(?:\/\d+)?=([a-z]+)$/i', $plain, $m) !== 1) {
                return null;
            }
            [$method, $result] = [strtolower($m[1]), strtolower($m[2])];
            $props = [];
            foreach ($words as [$plain, $quoted]) {
                // The name and its «=» are the server's own; the value is plain, quoted, or both («"local part"@domain»).
                if (preg_match('/^([a-z0-9_-]+)(\.[a-z0-9_-]+)?=(.*)$/is', $plain, $pair) !== 1) {
                    return null;
                }
                if ($pair[2] === '') {
                    // «reason="…"», or a server's own word («action=none»): not a property.
                    continue;
                }
                $name = strtolower($pair[1] . $pair[2]);
                if (array_key_exists($name, $props)) {
                    return null;
                }
                $props[$name] = strtolower($pair[3] . $quoted);
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
     * The words of each statement of a header (split at the «;» outside quotes and comments), comments dropped; null
     * when it is not clean (see parse). A word is its text up to its first quote, and (when it has one) the rest of it
     * with the quoted strings unescaped: what stands after that first quote is a value, never a name.
     *
     * @return list<list<array{0: string, 1: ?string}>>|null
     */
    private static function statements(string $value): ?array
    {
        $statements = [];
        $words = [];
        // The word being read: its text up to its first quote, and what stands from that quote on (null: no quote yet).
        $plain = '';
        $rest = null;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char !== "\t" && (ord($char) < 32 || ord($char) === 127)) {
                return null;
            }
            if ($char === '"') {
                // A quoted string, to its closing quote: part of this word.
                $rest ??= '';
                for ($i++; $i < $length && $value[$i] !== '"'; $i++) {
                    $i += $value[$i] === '\\' ? 1 : 0;
                    $rest .= $value[$i] ?? '';
                }
                if ($i >= $length) {
                    return null;
                }

                continue;
            }
            if ($char === ')' || $char === '\\') {
                return null;
            }
            if ($char === '(') {
                // A comment, to its «)»: it ends the word and leaves nothing. A parenthesis inside quotes in it is refused,
                // or one quote in the sender's text could carry the comment over the server's own «)».
                $inQuotes = false;
                for ($i++; $i < $length && ($inQuotes || $value[$i] !== ')'); $i++) {
                    if ($value[$i] === '\\') {
                        $i++;
                    } elseif ($value[$i] === '"') {
                        $inQuotes = !$inQuotes;
                    } elseif ($value[$i] === '(' || $value[$i] === ')' || (!$inQuotes && $value[$i] === ';')) {
                        return null;
                    }
                }
                if ($i >= $length) {
                    return null;
                }
            }
            if ($char === '(' || $char === ';' || $char === ' ' || $char === "\t") {
                if ($plain !== '' || $rest !== null) {
                    $words[] = [$plain, $rest];
                    [$plain, $rest] = ['', null];
                }
                if ($char === ';') {
                    $statements[] = $words;
                    $words = [];
                }
            } elseif ($rest !== null) {
                $rest .= $char;
            } else {
                $plain .= $char;
            }
        }
        if ($plain !== '' || $rest !== null) {
            $words[] = [$plain, $rest];
        }
        $statements[] = $words;

        return $statements;
    }

    /** The domain of an address (or a domain as such), lower case; null when there is none. */
    public static function domainOf(string $address): ?string
    {
        $address = strtolower(trim($address, " \t<>\""));
        $domain = str_contains($address, '@') ? substr($address, strrpos($address, '@') + 1) : $address;

        return preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain) === 1 ? $domain : null;
    }
}
