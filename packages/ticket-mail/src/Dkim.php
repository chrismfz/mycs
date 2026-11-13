<?php

namespace MyCS\TicketMail;

use Closure;

/**
 * Our own DKIM check (RFC 6376, RFC 8301, RFC 8463) of a raw mail, for when our receiving server's
 * Authentication-Results vouch for too little (cPanel's exim: docs/tickets.md §3 Β, «Δικός μας έλεγχος DKIM»).
 * A From is vouched for by one valid signature whose d= is the From's domain or a parent of it, never a subdomain
 * (the rule of AuthenticationResults).
 *
 * Strict where the RFC lets a verifier choose, and closed on any doubt (nothing vouches; the mail is read as before):
 * - rsa-sha256 (key of 1024 bits or more) and ed25519-sha256 only; never rsa-sha1;
 * - a signature with l= (a body length: anyone may append to the body) is refused;
 * - the signature must sign From, and the mail must have exactly one From, by a name that only reads as From (the MIME
 *   library also takes «F-rom» for it), so the From signed is the From the router reads;
 * - an expired x=, a key in testing (t=y), revoked (empty p=), of another algorithm or service, more than one key
 *   record, or a DNS failure: nothing vouches;
 * - at most MAX_SIGNATURES aligned signatures are looked up in DNS; an unaligned one is never looked up.
 */
final class Dkim
{
    public const MAX_SIGNATURES = 5;

    public const VERIFIED = 'dkim_verified';

    /** @var Closure(string): (list<string>|null) */
    private readonly Closure $txt;

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param  (Closure(string): (list<string>|null))|null  $txt  the TXT records at a name (each record's strings joined);
     *                                                            null when the lookup failed. Default: the system resolver.
     * @param  (Closure(): int)|null  $now  the time, for x=
     */
    public function __construct(?Closure $txt = null, ?Closure $now = null)
    {
        $this->txt = $txt ?? self::dnsTxt(...);
        $this->now = $now ?? time(...);
    }

    public function verdict(string $raw, string $fromEmail): AuthVerdict
    {
        $fromDomain = AuthenticationResults::domainOf($fromEmail);
        if ($fromDomain === null) {
            return new AuthVerdict(false, 'dkim_no_from_domain');
        }
        $message = self::split($raw);
        if ($message === null) {
            return new AuthVerdict(false, 'dkim_malformed');
        }
        [$fields, $body] = $message;

        $signatures = array_values(array_filter($fields, fn (array $field) => strtolower($field[0]) === 'dkim-signature'));
        if ($signatures === []) {
            return new AuthVerdict(false, 'dkim_none');
        }
        // One From, named only From: the one the router reads is the one signed.
        $froms = array_filter($fields, fn (array $field) => preg_replace('/[^a-z0-9]/', '', strtolower($field[0])) === 'from');
        if (count($froms) !== 1 || strtolower((string) array_values($froms)[0][0]) !== 'from') {
            return new AuthVerdict(false, 'dkim_from_header');
        }

        $reason = 'dkim_not_aligned';
        $tried = 0;
        foreach ($signatures as $signature) {
            $tags = self::tags(substr($signature[1], strlen($signature[0]) + 1));
            if ($tags === null) {
                $reason = $tried === 0 ? 'dkim_bad_signature' : $reason;

                continue;
            }
            if (!self::aligned(strtolower(trim($tags['d'] ?? '')), $fromDomain)) {
                continue;
            }
            if (++$tried > self::MAX_SIGNATURES) {
                break;
            }
            $reason = $this->check($tags, $signature, $fields, $body);
            if ($reason === null) {
                return new AuthVerdict(true, self::VERIFIED);
            }
        }

        return new AuthVerdict(false, $reason);
    }

    /** The From's domain, or a parent of it (with a dot: never a TLD); never a subdomain of it. */
    private static function aligned(string $d, string $fromDomain): bool
    {
        return $d !== '' && ($d === $fromDomain || (str_contains($d, '.') && str_ends_with($fromDomain, '.' . $d)));
    }

    /**
     * Null when the signature is valid; otherwise why not.
     *
     * @param  array<string, string>  $tags
     * @param  array{0: string, 1: string}  $signature
     * @param  list<array{0: string, 1: string}>  $fields
     */
    private function check(array $tags, array $signature, array $fields, string $body): ?string
    {
        foreach (['v', 'a', 'b', 'bh', 'd', 'h', 's'] as $required) {
            if (!isset($tags[$required])) {
                return 'dkim_bad_signature';
            }
        }
        $algorithm = strtolower($tags['a']);
        if ($tags['v'] !== '1' || !in_array($algorithm, ['rsa-sha256', 'ed25519-sha256'], true)) {
            return 'dkim_unsupported';
        }
        if (isset($tags['l'])) {
            return 'dkim_length_tag';
        }
        if (isset($tags['q']) && !in_array('dns/txt', array_map('trim', explode(':', strtolower($tags['q']))), true)) {
            return 'dkim_unsupported';
        }
        $canon = explode('/', strtolower($tags['c'] ?? 'simple/simple'));
        [$headerCanon, $bodyCanon] = [$canon[0], $canon[1] ?? 'simple'];
        if (count($canon) > 2 || !in_array($headerCanon, ['simple', 'relaxed'], true) || !in_array($bodyCanon, ['simple', 'relaxed'], true)) {
            return 'dkim_unsupported';
        }
        $d = strtolower($tags['d']);
        $selector = strtolower($tags['s']);
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d) !== 1 || preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/', $selector) !== 1) {
            return 'dkim_bad_signature';
        }
        // i=: d= or a subdomain of it.
        $at = strrpos($tags['i'] ?? '@', '@');
        $identity = $at === false ? '' : strtolower(substr($tags['i'] ?? '@' . $d, $at + 1));
        if ($identity !== $d && !str_ends_with($identity, '.' . $d)) {
            return 'dkim_bad_signature';
        }
        $signed = array_values(array_filter(array_map(fn (string $name) => strtolower(trim($name)), explode(':', $tags['h'])), fn (string $name) => $name !== ''));
        if (!in_array('from', $signed, true)) {
            return 'dkim_from_unsigned';
        }
        foreach (['t', 'x'] as $time) {
            if (isset($tags[$time]) && preg_match('/^\d{1,12}$/', $tags[$time]) !== 1) {
                return 'dkim_bad_signature';
            }
        }
        if (isset($tags['x']) && ((int) $tags['x'] < ($this->now)() || (isset($tags['t']) && (int) $tags['x'] < (int) $tags['t']))) {
            return 'dkim_expired';
        }
        $bodyHash = self::base64($tags['bh']);
        $sig = self::base64($tags['b']);
        if ($bodyHash === null || $sig === null) {
            return 'dkim_bad_signature';
        }

        if (!hash_equals(hash('sha256', self::canonicalBody($body, $bodyCanon), true), $bodyHash)) {
            return 'dkim_body_hash';
        }

        [$key, $refused] = $this->key($selector, $d, $algorithm, $identity);
        if ($refused !== null) {
            return $refused;
        }

        $data = self::signedHeaders($signed, $fields, $headerCanon) . self::canonicalHeader(self::withoutB($signature), $headerCanon, false);
        if ($algorithm === 'ed25519-sha256') {
            $ok = strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($sig, hash('sha256', $data, true), $key);
        } else {
            $public = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($key), 64, "\n") . "-----END PUBLIC KEY-----\n");
            $details = $public === false ? false : openssl_pkey_get_details($public);
            if ($public === false || $details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                return 'dkim_bad_key';
            }
            if ((int) $details['bits'] < 1024) {
                return 'dkim_weak_key';
            }
            $ok = openssl_verify($data, $sig, $public, OPENSSL_ALGO_SHA256) === 1;
        }

        return $ok ? null : 'dkim_signature';
    }

    /**
     * The key's bytes, or why there is none.
     *
     * @return array{0: string, 1: null}|array{0: null, 1: string}
     */
    private function key(string $selector, string $d, string $algorithm, string $identity): array
    {
        $records = ($this->txt)("{$selector}._domainkey.{$d}");
        if ($records === null) {
            return [null, 'dkim_dns_failure'];
        }
        if ($records === []) {
            return [null, 'dkim_no_key'];
        }
        if (count($records) > 1) {
            return [null, 'dkim_key_ambiguous'];
        }
        $tags = self::tags($records[0]);
        if ($tags === null || !isset($tags['p']) || (isset($tags['v']) && $tags['v'] !== 'DKIM1')) {
            return [null, 'dkim_bad_key'];
        }
        $words = fn (string $tag, string $default) => array_map('trim', explode(':', strtolower($tags[$tag] ?? $default)));
        $type = strtolower($tags['k'] ?? 'rsa');
        if ($type !== explode('-', $algorithm)[0] || !in_array('sha256', $words('h', 'sha256'), true)
            || array_intersect(['*', 'email'], $words('s', '*')) === []) {
            return [null, 'dkim_bad_key'];
        }
        $flags = $words('t', '');
        if (in_array('y', $flags, true)) {
            return [null, 'dkim_key_testing'];
        }
        if (in_array('s', $flags, true) && $identity !== $d) {
            return [null, 'dkim_bad_signature'];
        }
        if ($tags['p'] === '') {
            return [null, 'dkim_key_revoked'];
        }

        $key = self::base64($tags['p']);

        return $key === null ? [null, 'dkim_bad_key'] : [$key, null];
    }

    /**
     * The header fields (name, the field as it stands, its folding kept, without its last CRLF) and the body, lines in
     * CRLF. Null when a header line is neither a field nor its folding, or holds a lone CR.
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: string}|null
     */
    private static function split(string $raw): ?array
    {
        $raw = (string) preg_replace('/\r?\n/', "\r\n", $raw);
        $end = str_starts_with($raw, "\r\n") ? 0 : strpos($raw, "\r\n\r\n");
        [$head, $body] = $end === false ? [$raw, ''] : [substr($raw, 0, $end), substr($raw, $end + ($end === 0 ? 2 : 4))];

        // A lone CR (or NUL) the MIME library might read as a line of its own: then its From may not be ours.
        if (preg_match('/\r(?!\n)|\x00/', $head) === 1) {
            return null;
        }
        $fields = [];
        foreach ($head === '' ? [] : explode("\r\n", $head) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                if ($fields === []) {
                    return null;
                }
                $fields[count($fields) - 1][1] .= "\r\n" . $line;

                continue;
            }
            if (preg_match('/^([\x21-\x39\x3B-\x7E]+):/', $line, $m) !== 1) {
                return null;
            }
            $fields[] = [$m[1], $line];
        }

        return [$fields, $body];
    }

    /**
     * A tag list (RFC 6376 §3.2), folding whitespace dropped around names and values (and inside base64 values when
     * they are decoded). Null when it does not read cleanly or names a tag twice.
     *
     * @return array<string, string>|null
     */
    private static function tags(string $value): ?array
    {
        $tags = [];
        $parts = explode(';', $value);
        foreach ($parts as $i => $part) {
            $part = trim($part, " \t\r\n");
            if ($part === '' && $i === count($parts) - 1) {
                continue;
            }
            if (preg_match('/^([a-z][a-z0-9_]*)[ \t\r\n]*=[ \t\r\n]*(.*)$/is', $part, $m) !== 1) {
                return null;
            }
            // Tag names are case-sensitive (RFC 6376 §3.2): «B=» is no b=.
            $name = $m[1];
            if (array_key_exists($name, $tags) || preg_match('/[^\x21-\x3A\x3C-\x7E \t\r\n]/', $m[2]) === 1) {
                return null;
            }
            $tags[$name] = $m[2];
        }

        return $tags;
    }

    /** Base64 with folding whitespace in it; null when it is not base64. */
    private static function base64(string $value): ?string
    {
        $decoded = base64_decode((string) preg_replace('/[ \t\r\n]+/', '', $value), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * The DKIM-Signature field with its b= value deleted (RFC 6376 §3.7), all else as it stands.
     *
     * @param  array{0: string, 1: string}  $signature
     */
    private static function withoutB(array $signature): string
    {
        [$name, $field] = $signature;
        $value = (string) preg_replace('/(^|;)([ \t\r\n]*b[ \t\r\n]*=)[^;]*/', '$1$2', substr($field, strlen($name) + 1));

        return $name . ':' . $value;
    }

    /**
     * The fields h= names, each the next one up of its name (the last one first), canonical, each with its CRLF; a name
     * with no field left signs nothing.
     *
     * @param  list<string>  $names
     * @param  list<array{0: string, 1: string}>  $fields
     */
    private static function signedHeaders(array $names, array $fields, string $canon): string
    {
        $used = [];
        $data = '';
        foreach ($names as $name) {
            for ($i = count($fields) - 1; $i >= 0; $i--) {
                if (!isset($used[$i]) && strtolower($fields[$i][0]) === $name) {
                    $used[$i] = true;
                    $data .= self::canonicalHeader($fields[$i][1], $canon, true);

                    break;
                }
            }
        }

        return $data;
    }

    private static function canonicalHeader(string $field, string $canon, bool $crlf): string
    {
        if ($canon === 'relaxed') {
            [$name, $value] = explode(':', $field, 2);
            $value = (string) preg_replace('/[ \t]+/', ' ', str_replace("\r\n", '', $value));
            $field = strtolower(rtrim($name, " \t")) . ':' . trim($value, ' ');
        }

        return $field . ($crlf ? "\r\n" : '');
    }

    private static function canonicalBody(string $body, string $canon): string
    {
        if ($canon === 'relaxed') {
            $body = (string) preg_replace('/[ \t]+/', ' ', $body);
            $body = (string) preg_replace('/ (?=\r\n|$)/', '', $body);
            $body = (string) preg_replace('/(\r\n)+$/', '', $body);

            return $body === '' ? '' : $body . "\r\n";
        }

        return (string) preg_replace('/(\r\n)+$/', '', $body) . "\r\n";
    }

    /** @return list<string>|null */
    private static function dnsTxt(string $name): ?array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return null;
        }
        $out = [];
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'TXT') {
                $out[] = isset($record['entries']) && is_array($record['entries']) ? implode('', $record['entries']) : (string) ($record['txt'] ?? '');
            }
        }

        return $out;
    }
}
