<?php

namespace Nixpal\MyOpenprovider;

/**
 * What a paid renewal should do at Openprovider, decided from the domain's record and
 * our own renewal record for the service, before any money moves. Pure: no I/O.
 *
 * Our record ($state) is written by the caller BEFORE each charge ("pending", with the
 * expiry the charge must reach) and turned "done" once Openprovider shows it, so:
 * - renew:   live record → POST /renew; target = current expiry + paid years;
 * - restore: deleted, still inside the soft quarantine → POST /restore (the WHMCS
 *            module's rule); only for a 1-year renewal, since a restore brings back one
 *            year and anything longer needs a person;
 * - skip:    "adopted": a pending charge has since shown up at Openprovider;
 *            "already_renewed": this paid period was done;
 *            "prepaid": an admin renewed (or marked as renewed) before the payment;
 * - refuse:  a pending charge whose outcome is still unknown (timeout, 5xx, accepted but
 *            not visible yet) for PENDING_HOLD_DAYS, so a retry never charges twice;
 *            several paid invoices at once (see below); and hard quarantine, gone,
 *            non-active records, no readable expiry.
 *
 * The paid period ($period) is a set of keys: the service's paid renewal invoices that
 * Blesta has not processed yet ("inv:<id>"), or the renew date when there are none.
 * Blesta removes ONE of those invoices after each successful run without saying which,
 * so a run with several paid invoices only succeeds when it covers all of them (a
 * prepaid record); otherwise a person decides ("multiple_invoices").
 *
 * There is deliberately no "expiry already far out" shortcut: measured from today it
 * cannot tell a renewal done elsewhere from an early payment. Renewals done elsewhere are
 * prevented instead (autorenew off at Openprovider), or recorded as prepaid.
 *
 * Quarantine dates are Openprovider's local time (Europe/Amsterdam), as in their WHMCS
 * module.
 */
final class RenewalPlan
{
    const RENEW = 'renew';

    const RESTORE = 'restore';

    const SKIP = 'skip';

    const REFUSE = 'refuse';

    /** How long a pending charge blocks new charges for the service. */
    const PENDING_HOLD_DAYS = 7;

    /** Slack when comparing an expiry with a target (29 Feb, registry rounding). */
    const TOLERANCE_DAYS = 3;

    /** Without a period key, a done renewal this recent counts as the same one. */
    const RECENT_DAYS = 30;

    /** A prepaid record older than this is not used up silently: a person decides. */
    const PREPAID_VALID_DAYS = 180;

    /** @var string */
    public $action;

    /** @var string ok, adopted, already_renewed, prepaid, renewal_unconfirmed, multiple_invoices, … */
    public $reason;

    /** @var int */
    public $years;

    /** @var string|null Y-m-d the expiration_date must reach (renew only) */
    public $target;

    /**
     * @var string|null Y-m-d the renewal_date must reach (renew only). Openprovider calls
     *                  renewal_date the primary reference; expiration_date may follow the registry later.
     */
    public $renewalTarget;

    /**
     * @var bool a pending charge has shown up at Openprovider: the caller records it as
     *           done, also when the plan refuses (several paid invoices)
     */
    public $confirmed = false;

    private function __construct($action, $reason, $years = 0, $target = null, $renewalTarget = null)
    {
        $this->action = $action;
        $this->reason = $reason;
        $this->years = (int) $years;
        $this->target = $target;
        $this->renewalTarget = $renewalTarget;
    }

    /**
     * Whether a charge aimed at these targets shows on the record (expiration_date or
     * renewal_date, TOLERANCE_DAYS of slack).
     */
    public static function reached(array $domain, $target, $renewalTarget = null)
    {
        foreach ([['expiration_date', $target], ['renewal_date', $renewalTarget]] as $pair) {
            $have = self::date($domain[$pair[0]] ?? null, 'UTC');
            $want = self::date($pair[1], 'UTC');
            if ($have !== null && $want !== null && $have >= $want->modify('-' . self::TOLERANCE_DAYS . ' days')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>|null  $domain  Openprovider domain record (null: not in the account)
     * @param  int  $years  paid period
     * @param  array|null  $state  our record: status pending|done|prepaid, action, at, target, period
     * @param  string|string[]|null  $period  keys of the paid period (paid renewal invoices
     *                                        "inv:<id>", or the renew date); see the class comment
     * @param  bool  $yearsAtLeast  $years is only what was surely paid (one term) and the real
     *                              period is unknown: a prepaid record of at least that many years is used up, but
     *                              nothing is charged ("cycles_unknown", a person renews by hand)
     */
    public static function decide($domain, $years, \DateTimeImmutable $now, ?array $state = null, $period = null, $yearsAtLeast = false)
    {
        $years = (int) $years;
        if ($years < 1 || $years > 10) {
            return new self(self::REFUSE, 'invalid_period');
        }
        if (!is_array($domain)) {
            return new self(self::REFUSE, 'not_in_account');
        }

        $keys = self::keys($period);

        if ($state) {
            $stateStatus = (string) ($state['status'] ?? '');
            $stateKeys = self::keys($state['period'] ?? null);
            // Keys compare when both are of the same kind. An invoice set against a renew
            // date (a run without paid invoices, then the cron) cannot tell: a recent charge
            // of the other kind that has shown up goes to a person ("period_ambiguous"),
            // whether it is still pending or already done; an older one is an earlier period.
            // A record of unknown kind (the 1.0.0 bare date) is treated as before: pending =
            // this period.
            $kind = $keys ? self::kind($keys) : null;
            $stateKind = $stateKeys ? self::kind($stateKeys) : null;
            $comparable = $kind !== null && $kind === $stateKind;
            $otherKind = $kind !== null && $stateKind !== null && $kind !== $stateKind;
            $shownUp = self::shownUp($domain, $state);
            $recentOther = $otherKind && self::within($state['at'] ?? null, $now, self::RECENT_DAYS);
            // Once refused, it stays with the person (no charge when the 30 days run out).
            if ((($recentOther && $shownUp) || ($otherKind && !empty($state['ambiguous'])))
                && in_array($stateStatus, ['pending', 'done'], true) && empty($state['prepaid'])) {
                $plan = new self(self::REFUSE, 'period_ambiguous');
                $plan->confirmed = $stateStatus === 'pending';

                return $plan;
            }
            $samePending = $comparable ? (bool) array_intersect($keys, $stateKeys) : (!$otherKind || $recentOther);

            if ($stateStatus === 'prepaid') {
                // Renewed (or marked as renewed) by an admin before this payment: it covers
                // everything paid now, so Blesta may consume any of the invoices.
                if (!self::within($state['at'] ?? null, $now, self::PREPAID_VALID_DAYS)) {
                    return new self(self::REFUSE, 'prepaid_stale');
                }

                return self::prepaidCovers($state, $keys, $years, $yearsAtLeast)
                    ? new self(self::SKIP, 'prepaid')
                    : new self(self::REFUSE, 'prepaid_mismatch');
            }
            if ($stateStatus === 'pending' && !empty($state['prepaid'])) {
                // An admin's "renew now" whose outcome was unknown.
                if ($shownUp) {
                    $plan = self::prepaidCovers($state, $keys, $years, $yearsAtLeast)
                        ? new self(self::SKIP, 'prepaid')
                        : new self(self::REFUSE, 'prepaid_mismatch');
                    $plan->confirmed = true;

                    return $plan;
                }
                if (self::within($state['at'] ?? null, $now, self::PENDING_HOLD_DAYS)) {
                    return new self(self::REFUSE, 'renewal_unconfirmed');
                }
                // Older and never showed up: it was not charged.
            } elseif ($stateStatus === 'pending' && $samePending) {
                // A charge for THIS paid period whose outcome was unknown (an unsuccessful
                // run leaves Blesta's invoice in place, so the keys still overlap).
                if ($shownUp) {
                    $plan = count($keys) > 1 ? new self(self::REFUSE, 'multiple_invoices') : new self(self::SKIP, 'adopted');
                    $plan->confirmed = true;

                    return $plan;
                }
                if (self::within($state['at'] ?? null, $now, self::PENDING_HOLD_DAYS)) {
                    return new self(self::REFUSE, 'renewal_unconfirmed');
                }
                // Older pending record never showed up: it was not charged.
            } elseif ($stateStatus === 'pending' && self::within($state['at'] ?? null, $now, self::PENDING_HOLD_DAYS)
                && !$shownUp) {
                // An earlier period's charge is still unconfirmed: do not stack another on it.
                return new self(self::REFUSE, 'renewal_unconfirmed');
            } elseif ($stateStatus === 'done') {
                $samePeriod = $comparable
                    ? !array_diff($keys, $stateKeys)
                    : !$otherKind && self::within($state['at'] ?? null, $now, self::RECENT_DAYS) && $shownUp;
                if ($samePeriod) {
                    return new self(self::SKIP, 'already_renewed');
                }
            }
            // A record of an earlier period (done, or pending and since visible or expired)
            // does not cover this paid period: renew normally below.
        }

        if (count($keys) > 1) {
            return new self(self::REFUSE, 'multiple_invoices');
        }
        if ($yearsAtLeast) {
            return new self(self::REFUSE, 'cycles_unknown');
        }

        return self::forDomain($domain, $years, $now);
    }

    /**
     * An admin's renewal before the payment ("renew now"): the same checks as a paid
     * renewal, but never on top of a prepaid record, an unconfirmed charge, or a paid
     * invoice Blesta is about to renew anyway.
     *
     * @param  int  $paidInvoices  paid renewal invoices of the service not processed yet
     * @param  bool  $cyclesUnknown  the paid invoice's years are unknown (Blesta refuses it,
     *                               so renewing by hand is the way out)
     */
    public static function decideManual($domain, $years, \DateTimeImmutable $now, ?array $state = null, $paidInvoices = 0, $cyclesUnknown = false)
    {
        $years = (int) $years;
        if ($years < 1 || $years > 10) {
            return new self(self::REFUSE, 'invalid_period');
        }
        if (!is_array($domain)) {
            return new self(self::REFUSE, 'not_in_account');
        }
        if ((int) $paidInvoices === 1 && !$cyclesUnknown) {
            // Blesta renews it within minutes. (Several: Blesta refuses them, so an admin
            // renews by hand and the prepaid record covers them all.)
            return new self(self::REFUSE, 'paid_invoice_waiting');
        }
        if ($blocked = self::blocksManual($state, $now, $domain)) {
            return new self(self::REFUSE, $blocked);
        }

        return self::forDomain($domain, $years, $now);
    }

    /**
     * Why our record does not allow a new prepaid record (renew now, or mark as renewed),
     * or null. $domain null: not looked up (mark as renewed), so a pending charge counts
     * as unconfirmed while it is held.
     */
    public static function blocksManual(?array $state, \DateTimeImmutable $now, ?array $domain = null)
    {
        $stateStatus = (string) ($state['status'] ?? '');
        if ($stateStatus === 'prepaid' && self::within($state['at'] ?? null, $now, self::PREPAID_VALID_DAYS)) {
            return 'already_prepaid';
        }
        if ($stateStatus === 'pending' && self::within($state['at'] ?? null, $now, self::PENDING_HOLD_DAYS)
            && !($domain !== null && self::shownUp($domain, $state))) {
            return 'renewal_unconfirmed';
        }

        return null;
    }

    /**
     * Whether a prepaid record pays for the invoices waiting now: exactly the paid years
     * for one invoice; for several (each at least $years, one term) at least $years each.
     * Invoices an earlier charge already covered ("already", from the done record the
     * prepaid one replaced) are not owed again. $atLeast: the paid years are unknown, at
     * least $years: only a record made for this very invoice ("for", the way out of
     * cycles_unknown) counts; an earlier one may be fewer years than were paid.
     */
    private static function prepaidCovers(array $state, array $keys, $years, $atLeast = false)
    {
        $owed = array_values(array_diff($keys, self::keys($state['already'] ?? null)));
        $have = (int) ($state['years'] ?? 0);
        if (count($owed) <= 1) {
            if (!$owed && $keys) {
                return true;
            }
            if ($atLeast) {
                return $have >= $years && array_intersect($keys, self::keys($state['for'] ?? null));
            }

            return $have === $years;
        }

        return $have >= $years * count($owed);
    }

    /** "inv" or "renews" when all keys are of that kind, else null (e.g. the 1.0.0 bare date). */
    private static function kind(array $keys)
    {
        $kinds = array_unique(array_map(function ($key) {
            return strpos($key, ':') === false ? '' : strstr($key, ':', true);
        }, $keys));

        return count($kinds) === 1 && in_array($kinds[0], ['inv', 'renews'], true) ? $kinds[0] : null;
    }

    /** Whether the charge a pending record stands for shows on the domain's record. */
    private static function shownUp(array $domain, array $state)
    {
        return ($state['action'] ?? null) === self::RESTORE
            ? strtoupper((string) ($domain['status'] ?? '')) === 'ACT'
            : self::reached($domain, $state['target'] ?? null, $state['renewal_target'] ?? null);
    }

    /** What the domain's own record allows (no records of ours involved). */
    private static function forDomain(array $domain, $years, \DateTimeImmutable $now)
    {
        $status = strtoupper((string) ($domain['status'] ?? ''));
        $expiry = self::date($domain['expiration_date'] ?? null, 'UTC');

        if ($status === 'DEL') {
            $amsterdam = $now->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
            $soft = self::date($domain['soft_quarantine_expiry_date'] ?? null, 'Europe/Amsterdam');
            $hard = self::date($domain['hard_quarantine_expiry_date'] ?? null, 'Europe/Amsterdam');
            if ($soft && $soft > $amsterdam) {
                return $years === 1
                    ? new self(self::RESTORE, 'soft_quarantine', 1)
                    : new self(self::REFUSE, 'restore_multi_year');
            }
            if ($hard && $hard > $amsterdam) {
                return new self(self::REFUSE, 'hard_quarantine');
            }

            return new self(self::REFUSE, 'deleted');
        }

        if ($status !== 'ACT') {
            return new self(self::REFUSE, 'status_' . ($status !== '' ? strtolower($status) : 'unknown'));
        }
        if (($domain['can_renew'] ?? true) === false) {
            return new self(self::REFUSE, 'cannot_renew');
        }
        if ($expiry === null) {
            return new self(self::REFUSE, 'no_expiry');
        }

        // Registries extend from the current expiry, also during the grace period.
        $renewal = self::date($domain['renewal_date'] ?? null, 'UTC');

        return new self(
            self::RENEW,
            'ok',
            $years,
            $expiry->modify('+' . $years . ' years')->format('Y-m-d'),
            $renewal ? $renewal->modify('+' . $years . ' years')->format('Y-m-d') : null
        );
    }

    /** @return string[] the non-empty keys of a period (one key or a list), sorted */
    private static function keys($period)
    {
        $keys = [];
        foreach ((array) $period as $key) {
            if (is_scalar($key) && (string) $key !== '') {
                $keys[] = (string) $key;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    private static function within($at, \DateTimeImmutable $now, $days)
    {
        $when = self::date($at, 'UTC');

        return $when !== null && $when > $now->modify('-' . (int) $days . ' days') && $when <= $now->modify('+1 day');
    }

    /** "YYYY-MM-DD[ HH:MM:SS]" in $zone, or null. */
    private static function date($value, $zone)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', trim($value))
            || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim($value), new \DateTimeZone($zone));
        } catch (\Exception $e) {
            return null;
        }
    }
}
