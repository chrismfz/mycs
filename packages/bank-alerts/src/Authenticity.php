<?php

namespace MyCS\BankAlerts;

use MyCS\TicketMail\AuthenticationResults;
use MyCS\TicketMail\AuthVerdict;

/**
 * Whether our mail server vouches that the bank itself sent the alert (stricter than tickets: a mail that marks invoices
 * paid). It must say the bank's DKIM passed for exactly its domain, and DMARC passed for it; SPF alone never counts (a
 * shared sender may be in the bank's record). It says so either:
 * - directly: in its topmost Authentication-Results (a sender's own ones sit below ours and are ignored); or
 * - forwarded inside our server (a sieve redirect to the alerts mailbox): its topmost result says arc=pass, and the mail
 *   carries exactly one ARC set, i=1, sealed by our domain, whose ARC-Authentication-Results (ours) say so. A later set
 *   would let its sealer rewrite the body under our seal; another sealer's i=1 is not ours.
 * Tags of a seal are read as DKIM reads them (RFC 6376 §3.2): case-sensitive, one each.
 */
final class Authenticity
{
    /**
     * @param  list<string>  $authenticationResults  Authentication-Results values, top first
     * @param  list<string>  $arcSeals  ARC-Seal values
     * @param  list<string>  $arcSignatures  ARC-Message-Signature values
     * @param  list<string>  $arcResults  ARC-Authentication-Results values
     */
    public static function verdict(array $authenticationResults, array $arcSeals, array $arcSignatures, array $arcResults,
        string $authservId, string $sealer, string $fromEmail): AuthVerdict
    {
        $authservId = strtolower(trim($authservId));
        $domain = AuthenticationResults::domainOf($fromEmail);
        if ($authservId === '' || $domain === null) {
            return new AuthVerdict(false, $authservId === '' ? 'no_authserv_id' : 'no_from_domain');
        }
        $top = $authenticationResults === [] ? null : AuthenticationResults::parse($authenticationResults[0]);
        if ($top === null || $top['authserv_id'] !== $authservId) {
            return new AuthVerdict(false, 'not_our_server');
        }
        if (self::bankPassed($top['results'], $domain)) {
            return new AuthVerdict(true, 'dkim_dmarc_pass');
        }
        if (!self::has($top['results'], 'arc', 'pass')) {
            return new AuthVerdict(false, 'no_bank_dkim_and_dmarc');
        }

        $sealer = strtolower(trim($sealer));
        $seals = self::instances($arcSeals);
        $signatures = self::instances($arcSignatures);
        $results = self::instances($arcResults);
        if ($sealer === '' || array_keys($seals) !== [1] || array_keys($signatures) !== [1] || array_keys($results) !== [1]
            || count($seals[1]) !== 1 || count($signatures[1]) !== 1 || count($results[1]) !== 1) {
            return new AuthVerdict(false, 'arc_not_one_set');
        }
        $tags = self::tags($seals[1][0]);
        if ($tags === null || strtolower($tags['d'] ?? '') !== $sealer) {
            return new AuthVerdict(false, 'arc_seal_not_ours');
        }
        $first = AuthenticationResults::parse($results[1][0]);
        if ($first === null || $first['authserv_id'] !== $authservId) {
            return new AuthVerdict(false, 'arc_results_not_ours');
        }

        return self::bankPassed($first['results'], $domain) ? new AuthVerdict(true, 'arc:dkim_dmarc_pass') : new AuthVerdict(false, 'arc:no_bank_dkim_and_dmarc');
    }

    /** @param  list<array{method: string, result: string, props: array<string, string>}>  $results */
    private static function bankPassed(array $results, string $domain): bool
    {
        $dkim = $dmarc = false;
        foreach ($results as $result) {
            if ($result['result'] !== 'pass') {
                continue;
            }
            $dkim = $dkim || ($result['method'] === 'dkim' && ($result['props']['header.d'] ?? null) === $domain);
            $dmarc = $dmarc || ($result['method'] === 'dmarc' && ($result['props']['header.from'] ?? null) === $domain);
        }

        return $dkim && $dmarc;
    }

    /** @param  list<array{method: string, result: string, props: array<string, string>}>  $results */
    private static function has(array $results, string $method, string $result): bool
    {
        return array_filter($results, fn (array $r) => $r['method'] === $method && $r['result'] === $result) !== [];
    }

    /**
     * Each value by its ARC instance («i=N;» taken off); a value with no instance is under 0.
     *
     * @param  list<string>  $values
     * @return array<int, list<string>>
     */
    private static function instances(array $values): array
    {
        $found = [];
        foreach ($values as $value) {
            $value = trim(str_replace(["\r", "\n"], ' ', $value));
            $i = preg_match('/^i\s*=\s*(\d+)\s*;(.*)$/s', $value, $m) === 1 ? (int) $m[1] : 0;
            $found[$i][] = trim($m[2] ?? $value);
        }
        ksort($found);

        return $found;
    }

    /**
     * A tag-list's tags by their exact names; null when one is written twice.
     *
     * @return array<string, string>|null
     */
    private static function tags(string $value): ?array
    {
        $tags = [];
        foreach (explode(';', $value) as $part) {
            if (trim($part) === '' || !str_contains($part, '=')) {
                continue;
            }
            [$name, $tagValue] = explode('=', $part, 2);
            $name = trim($name);
            if (array_key_exists($name, $tags)) {
                return null;
            }
            $tags[$name] = trim((string) preg_replace('/\s+/', '', $tagValue));
        }

        return $tags;
    }
}
