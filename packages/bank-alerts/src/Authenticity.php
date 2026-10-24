<?php

namespace MyCS\BankAlerts;

use MyCS\TicketMail\AuthenticationResults;
use MyCS\TicketMail\AuthVerdict;

/**
 * Whether our mail server vouches that the bank sent the alert. Directly: the topmost Authentication-Results of our
 * server says DMARC (or DKIM/SPF) pass for the bank's domain (ticket-mail's verdict). Or forwarded inside our server (a
 * sieve redirect to the alerts mailbox): our topmost result says arc=pass, the chain's first seal (i=1) is ours, and our
 * own first ARC-Authentication-Results says the bank's DMARC passed. A sender can write any of these headers, but not
 * our seal: a forged i=1 set breaks the chain (no arc=pass), and another sealer's i=1 is not ours.
 */
final class Authenticity
{
    /**
     * @param  list<string>  $authenticationResults  Authentication-Results values, top first
     * @param  list<string>  $arcSeals  ARC-Seal values
     * @param  list<string>  $arcResults  ARC-Authentication-Results values
     */
    public static function verdict(array $authenticationResults, array $arcSeals, array $arcResults, string $authservId, string $sealer, string $fromEmail): AuthVerdict
    {
        $direct = AuthenticationResults::verdict($authenticationResults, $authservId, $fromEmail);
        if ($direct->authenticated) {
            return $direct;
        }
        $sealer = strtolower(trim($sealer));
        if ($sealer === '' || $authenticationResults === []) {
            return $direct;
        }
        $top = AuthenticationResults::parse($authenticationResults[0]);
        if ($top === null || $top['authserv_id'] !== strtolower(trim($authservId))) {
            return new AuthVerdict(false, 'not_our_server');
        }
        $arcPass = array_filter($top['results'], fn (array $r) => $r['method'] === 'arc' && $r['result'] === 'pass') !== [];
        if (!$arcPass) {
            return $direct;
        }
        $firstSeals = self::instance($arcSeals, 1);
        if (count($firstSeals) !== 1 || self::tag($firstSeals[0], 'd') !== $sealer) {
            return new AuthVerdict(false, 'arc_first_seal_not_ours');
        }
        $firstResults = self::instance($arcResults, 1);
        if (count($firstResults) !== 1) {
            return new AuthVerdict(false, 'arc_no_single_first_results');
        }
        $forwarded = AuthenticationResults::verdict([$firstResults[0]], $authservId, $fromEmail);

        return new AuthVerdict($forwarded->authenticated, 'arc:' . $forwarded->reason);
    }

    /**
     * The values of one ARC instance, «i=N;» taken off.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function instance(array $values, int $i): array
    {
        $found = [];
        foreach ($values as $value) {
            $value = trim(str_replace(["\r", "\n"], ' ', $value));
            if (preg_match('/^i\s*=\s*(\d+)\s*;(.*)$/s', $value, $m) === 1 && (int) $m[1] === $i) {
                $found[] = trim($m[2]);
            }
        }

        return $found;
    }

    private static function tag(string $value, string $name): ?string
    {
        return preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\s*=\s*([^;\s]+)/i', $value, $m) === 1 ? strtolower($m[1]) : null;
    }
}
