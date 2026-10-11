<?php

namespace Nixpal\MyOpenprovider\Tests;

use Nixpal\MyOpenprovider\RenewalPlan;
use PHPUnit\Framework\TestCase;

final class RenewalPlanTest extends TestCase
{
    const NOW = '2026-10-10 12:00:00';

    private function plan($domain, $years = 1, $state = null, $period = 'renews:2026-10-25 00:00:00')
    {
        return RenewalPlan::decide($domain, $years, new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $state, $period);
    }

    private static function act($expiry, array $extra = [])
    {
        return ['id' => 1, 'status' => 'ACT', 'expiration_date' => $expiry] + $extra;
    }

    public function test_live_domain_renews_with_target_from_its_expiry()
    {
        $plan = $this->plan(self::act('2026-10-25 00:00:00', ['can_renew' => true]), 2);

        $this->assertSame([RenewalPlan::RENEW, 'ok', 2, '2028-10-25'], [$plan->action, $plan->reason, $plan->years, $plan->target]);
    }

    public function test_grace_period_renewal_extends_from_the_old_expiry()
    {
        $this->assertSame('2027-10-01', $this->plan(self::act('2026-10-01 00:00:00'))->target);
    }

    public function test_early_payment_still_renews()
    {
        // 13 months left: no "already far out" shortcut that would skip a paid renewal.
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2027-11-10 00:00:00'))->action);
    }

    public function test_pending_charge_that_showed_up_is_adopted()
    {
        $state = ['status' => 'pending', 'action' => 'renew', 'target' => '2027-10-25', 'at' => '2026-10-10 11:00:00'];

        $this->assertSame([RenewalPlan::SKIP, 'adopted'], $this->ar($this->plan(self::act('2027-10-25 00:00:00'), 1, $state)));
    }

    public function test_target_tolerance_covers29_february()
    {
        $state = ['status' => 'pending', 'action' => 'renew', 'target' => '2029-03-01', 'at' => '2026-10-10 11:00:00'];

        $this->assertSame('adopted', $this->plan(self::act('2029-02-28 00:00:00'), 1, $state)->reason);
    }

    public function test_pending_charge_not_visible_blocks_retries_for_a_week()
    {
        // Timeout on POST /renew: Openprovider may have charged. A retry must not charge again.
        $state = ['status' => 'pending', 'action' => 'renew', 'target' => '2027-10-25', 'at' => '2026-10-08 12:00:00'];
        $this->assertSame([RenewalPlan::REFUSE, 'renewal_unconfirmed'], $this->ar($this->plan(self::act('2026-10-25 00:00:00'), 1, $state)));

        $old = ['at' => '2026-09-30 12:00:00'] + $state;
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2026-10-25 00:00:00'), 1, $old)->action);
    }

    public function test_pending_restore_is_adopted_once_the_domain_is_live()
    {
        $state = ['status' => 'pending', 'action' => 'restore', 'target' => null, 'at' => '2026-10-10 11:00:00'];

        $this->assertSame('adopted', $this->plan(self::act('2027-10-01 00:00:00'), 1, $state)->reason);
        $this->assertSame('renewal_unconfirmed', $this->plan(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-10-20 00:00:00'], 1, $state)->reason);
    }

    public function test_a_pending_record_of_an_earlier_period_is_not_adopted()
    {
        $renew = ['status' => 'pending', 'action' => 'renew', 'target' => '2026-10-25', 'period' => 'renews:2025-10-25 00:00:00', 'at' => '2025-10-20 12:00:00'];
        $restore = ['action' => 'restore', 'target' => null] + $renew;

        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2026-10-30 00:00:00'), 1, $renew)->action);
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2026-10-30 00:00:00'), 1, $restore)->action);
    }

    public function test_an_unconfirmed_charge_of_an_earlier_period_blocks_stacking()
    {
        $state = ['status' => 'pending', 'action' => 'renew', 'target' => '2027-10-25', 'period' => 'renews:2026-09-25 00:00:00', 'at' => '2026-10-08 12:00:00'];

        $this->assertSame('renewal_unconfirmed', $this->plan(self::act('2026-10-25 00:00:00'), 1, $state)->reason);
    }

    public function test_renewal_date_counts_as_confirmation_and_targets_are_both()
    {
        $plan = $this->plan(self::act('2026-10-25 00:00:00', ['renewal_date' => '2026-10-15 00:00:00']));
        $this->assertSame(['2027-10-25', '2027-10-15'], [$plan->target, $plan->renewalTarget]);

        $state = ['status' => 'pending', 'action' => 'renew', 'target' => '2027-10-25', 'renewal_target' => '2027-10-15', 'at' => '2026-10-10 11:00:00'];
        $this->assertSame('adopted', $this->plan(self::act('2026-10-25 00:00:00', ['renewal_date' => '2027-10-15 00:00:00']), 1, $state)->reason);
    }

    public function test_same_paid_period_is_not_renewed_twice_but_the_next_one_is()
    {
        $state = ['status' => 'done', 'action' => 'renew', 'target' => '2027-10-25', 'period' => 'renews:2026-10-25 00:00:00', 'at' => '2026-10-09 12:00:00'];

        $this->assertSame([RenewalPlan::SKIP, 'already_renewed'], $this->ar($this->plan(self::act('2027-10-25 00:00:00'), 1, $state)));
        // A second paid renewal (next period) renews even within days of the first one.
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2027-10-25 00:00:00'), 1, $state, 'renews:2027-10-25 00:00:00')->action);
    }

    public function test_without_a_period_key_a_recent_done_renewal_is_the_same()
    {
        $state = ['status' => 'done', 'action' => 'renew', 'target' => '2027-10-25', 'at' => '2026-10-09 12:00:00'];

        $this->assertSame('already_renewed', $this->plan(self::act('2027-10-25 00:00:00'), 1, $state, null)->reason);
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2027-10-25 00:00:00'), 1, ['at' => '2026-08-01 12:00:00'] + $state, null)->action);
    }

    public function test_paid_invoices_are_period_keys()
    {
        // Two renewal invoices of the same renew date are two periods.
        $state = ['status' => 'done', 'action' => 'renew', 'target' => '2027-10-25', 'period' => ['inv:12'], 'at' => '2026-10-09 12:00:00'];

        $this->assertSame('already_renewed', $this->plan(self::act('2027-10-25 00:00:00'), 1, $state, ['inv:12'])->reason);
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2027-10-25 00:00:00'), 1, $state, ['inv:13'])->action);
    }

    public function test_a_pending_charge_of_another_kind_of_period()
    {
        // 1.0.0 stored the renew date bare (kind unknown): pending = this period, adopt.
        $legacy = ['status' => 'pending', 'action' => 'renew', 'target' => '2027-10-25', 'period' => '2026-10-25 00:00:00', 'at' => '2026-09-01 12:00:00'];
        $this->assertSame([RenewalPlan::SKIP, 'adopted'], $this->ar($this->plan(self::act('2027-10-25 00:00:00'), 1, $legacy, ['inv:5'])));

        // A run without paid invoices ("renews:"), then the cron with an invoice: recent and
        // shown up, it may or may not be the same payment, pending or done alike → a person;
        // not shown yet → held; a year old → an earlier period, renew.
        $renews = ['period' => 'renews:2026-10-25 00:00:00', 'at' => '2026-10-01 12:00:00'] + $legacy;
        $plan = $this->plan(self::act('2027-10-25 00:00:00'), 1, $renews, ['inv:5']);
        $this->assertSame([RenewalPlan::REFUSE, 'period_ambiguous', true], [$plan->action, $plan->reason, $plan->confirmed]);
        $this->assertSame('period_ambiguous', $this->plan(self::act('2027-10-25 00:00:00'), 1, ['status' => 'done'] + $renews, ['inv:5'])->reason);
        $this->assertSame('renewal_unconfirmed', $this->plan(self::act('2026-10-25 00:00:00'), 1, ['at' => '2026-10-09 12:00:00'] + $renews, ['inv:5'])->reason);
        // Once refused, it stays refused after the 30 days (no unattended charge).
        $this->assertSame('period_ambiguous', $this->plan(self::act('2027-10-25 00:00:00'), 1, ['status' => 'done', 'ambiguous' => true, 'at' => '2026-08-01 12:00:00'] + $renews, ['inv:5'])->reason);
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2027-10-25 00:00:00'), 1, ['at' => '2025-09-01 12:00:00'] + $renews, ['inv:5'])->action);

        // Same kind, disjoint: an earlier invoice Blesta already processed → renew.
        $state = ['period' => ['inv:4']] + $legacy;
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2027-10-25 00:00:00'), 1, $state, ['inv:5'])->action);

        // A recent done record of another kind that does not show (an earlier charge): renew.
        $done = ['status' => 'done', 'period' => ['renews:2026-10-25 00:00:00'], 'target' => '2026-10-25', 'at' => '2026-10-09 12:00:00'];
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2026-10-20 00:00:00'), 1, $done, ['inv:5'])->action);
    }

    public function test_unknown_cycles_charge_nothing_but_a_prepaid_record_is_used_up()
    {
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        $live = self::act('2026-10-25 00:00:00');

        $this->assertSame('cycles_unknown', RenewalPlan::decide($live, 1, $now, null, ['inv:12'], true)->reason);
        // Only a record made for this invoice (the way out) counts at "at least one term".
        $prepaid = ['status' => 'prepaid', 'years' => 3, 'for' => ['inv:12'], 'at' => '2026-10-01 12:00:00'];
        $this->assertSame('prepaid', RenewalPlan::decide($live, 1, $now, $prepaid, ['inv:12'], true)->reason);
        unset($prepaid['for']);
        $this->assertSame('prepaid_mismatch', RenewalPlan::decide($live, 1, $now, $prepaid, ['inv:12'], true)->reason);
        // The way out: renew now is allowed although one paid invoice waits.
        $this->assertSame(RenewalPlan::RENEW, RenewalPlan::decideManual($live, 3, $now, null, 1, true)->action);
    }

    public function test_several_paid_invoices_need_a_person()
    {
        // Blesta clears one of them after a successful run without saying which.
        $this->assertSame([RenewalPlan::REFUSE, 'multiple_invoices'], $this->ar($this->plan(self::act('2026-10-25 00:00:00'), 1, null, ['inv:13', 'inv:12'])));

        // Both already covered (a retry after a crash): nothing to do.
        $done = ['status' => 'done', 'period' => ['inv:12', 'inv:13'], 'at' => '2026-10-09 12:00:00'];
        $this->assertSame('already_renewed', $this->plan(self::act('2027-10-25 00:00:00'), 1, $done, ['inv:13', 'inv:12'])->reason);
        $this->assertSame('already_renewed', $this->plan(self::act('2027-10-25 00:00:00'), 1, $done, ['inv:13'])->reason);
    }

    public function test_a_pending_charge_is_matched_by_an_overlapping_invoice()
    {
        $state = ['status' => 'pending', 'action' => 'renew', 'target' => '2027-10-25', 'period' => ['inv:12'], 'at' => '2026-10-10 11:00:00'];

        $plan = $this->plan(self::act('2027-10-25 00:00:00'), 1, $state, ['inv:12']);
        $this->assertSame([RenewalPlan::SKIP, 'adopted', true], [$plan->action, $plan->reason, $plan->confirmed]);

        // Another invoice was paid meanwhile: record the charge, let a person handle the rest.
        $plan = $this->plan(self::act('2027-10-25 00:00:00'), 1, $state, ['inv:12', 'inv:14']);
        $this->assertSame([RenewalPlan::REFUSE, 'multiple_invoices', true], [$plan->action, $plan->reason, $plan->confirmed]);

        $this->assertSame('renewal_unconfirmed', $this->plan(self::act('2026-10-25 00:00:00'), 1, $state, ['inv:12', 'inv:14'])->reason);
    }

    public function test_prepaid_record_is_used_up_by_the_next_paid_renewal()
    {
        $prepaid = ['status' => 'prepaid', 'years' => 1, 'at' => '2026-09-01 12:00:00'];

        $this->assertSame([RenewalPlan::SKIP, 'prepaid'], $this->ar($this->plan(self::act('2027-10-25 00:00:00'), 1, $prepaid, ['inv:12'])));
        // Several invoices: only when it pays at least one term for each one owed.
        $this->assertSame('prepaid_mismatch', $this->plan(self::act('2027-10-25 00:00:00'), 1, $prepaid, ['inv:12', 'inv:13'])->reason);
        $this->assertSame('prepaid', $this->plan(self::act('2027-10-25 00:00:00'), 1, ['years' => 2] + $prepaid, ['inv:12', 'inv:13'])->reason);
        // One of them was already charged (adopted before the admin renewed the rest).
        $this->assertSame('prepaid', $this->plan(self::act('2027-10-25 00:00:00'), 1, ['already' => ['inv:12']] + $prepaid, ['inv:12', 'inv:13'])->reason);
        $this->assertSame('prepaid_mismatch', $this->plan(self::act('2027-10-25 00:00:00'), 2, $prepaid, ['inv:12'])->reason);
        $this->assertSame('prepaid_stale', $this->plan(self::act('2027-10-25 00:00:00'), 1, ['at' => '2026-03-01 12:00:00'] + $prepaid, ['inv:12'])->reason);
    }

    public function test_an_unconfirmed_renew_now_is_held_then_used_up_once_visible()
    {
        $state = ['status' => 'pending', 'prepaid' => true, 'action' => 'renew', 'years' => 1, 'target' => '2027-10-25', 'period' => [], 'at' => '2026-10-09 12:00:00'];

        $this->assertSame('renewal_unconfirmed', $this->plan(self::act('2026-10-25 00:00:00'), 1, $state, ['inv:12'])->reason);
        $plan = $this->plan(self::act('2027-10-25 00:00:00'), 1, $state, ['inv:12']);
        $this->assertSame([RenewalPlan::SKIP, 'prepaid', true], [$plan->action, $plan->reason, $plan->confirmed]);
        // Never visible and past the hold: it was not charged, the paid renewal goes ahead.
        $this->assertSame(RenewalPlan::RENEW, $this->plan(self::act('2026-10-25 00:00:00'), 1, ['at' => '2026-09-20 12:00:00'] + $state, ['inv:12'])->action);
    }

    public function test_renew_now_refuses_what_would_double_charge()
    {
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        $live = self::act('2026-10-25 00:00:00');

        $plan = RenewalPlan::decideManual($live, 2, $now);
        $this->assertSame([RenewalPlan::RENEW, '2028-10-25'], [$plan->action, $plan->target]);
        $this->assertSame('paid_invoice_waiting', RenewalPlan::decideManual($live, 1, $now, null, 1)->reason);
        // Several waiting: Blesta refuses them, so renewing by hand is the way out.
        $this->assertSame(RenewalPlan::RENEW, RenewalPlan::decideManual($live, 1, $now, null, 2)->action);
        $this->assertSame('already_prepaid', RenewalPlan::decideManual($live, 1, $now, ['status' => 'prepaid', 'at' => '2026-10-01 12:00:00'])->reason);
        $pending = ['status' => 'pending', 'action' => 'renew', 'target' => '2027-10-25', 'at' => '2026-10-10 11:00:00'];
        $this->assertSame('renewal_unconfirmed', RenewalPlan::decideManual($live, 1, $now, $pending)->reason);
        $this->assertSame('hard_quarantine', RenewalPlan::decideManual(['status' => 'DEL', 'hard_quarantine_expiry_date' => '2026-11-20 00:00:00'], 1, $now)->reason);

        // Mark as renewed (no lookup): a held pending record blocks, a done one does not.
        $this->assertSame('renewal_unconfirmed', RenewalPlan::blocksManual($pending, $now));
        $this->assertNull(RenewalPlan::blocksManual(['status' => 'done'] + $pending, $now));
    }

    public function test_deleted_domain_inside_soft_quarantine_is_restored_for_one_year_only()
    {
        $domain = ['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-10-20 00:00:00', 'hard_quarantine_expiry_date' => '2026-11-20 00:00:00'];

        $this->assertSame([RenewalPlan::RESTORE, 'soft_quarantine'], $this->ar($this->plan($domain)));
        $this->assertSame([RenewalPlan::REFUSE, 'restore_multi_year'], $this->ar($this->plan($domain, 2)));
    }

    public function test_hard_quarantine_and_gone_are_refused()
    {
        $hard = ['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-10-01 00:00:00', 'hard_quarantine_expiry_date' => '2026-11-20 00:00:00'];
        $gone = ['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-09-01 00:00:00', 'hard_quarantine_expiry_date' => '2026-10-01 00:00:00'];

        $this->assertSame('hard_quarantine', $this->plan($hard)->reason);
        $this->assertSame('deleted', $this->plan($gone)->reason);
    }

    public function test_quarantine_dates_are_amsterdam_time()
    {
        // 12:00 UTC = 14:00 in Amsterdam (CEST): a soft quarantine ending 13:30 local has passed.
        $this->assertSame('deleted', $this->plan(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-10-10 13:30:00'])->reason);
        $this->assertSame('soft_quarantine', $this->plan(['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-10-10 14:30:00'])->reason);
    }

    public function test_other_states_are_refused()
    {
        $this->assertSame('status_req', $this->plan(['status' => 'REQ'])->reason);
        $this->assertSame('status_fai', $this->plan(['status' => 'FAI'])->reason);
        $this->assertSame('status_unknown', $this->plan(['id' => 1])->reason);
        $this->assertSame('cannot_renew', $this->plan(self::act('2026-10-25 00:00:00', ['can_renew' => false]))->reason);
        $this->assertSame('no_expiry', $this->plan(['status' => 'ACT'])->reason);
        $this->assertSame('no_expiry', $this->plan(self::act('garbage'))->reason);
        $this->assertSame('not_in_account', $this->plan(null)->reason);
        $this->assertSame('invalid_period', $this->plan(self::act('2026-10-25 00:00:00'), 0)->reason);
        $this->assertSame('invalid_period', $this->plan(self::act('2026-10-25 00:00:00'), 11)->reason);
    }

    private function ar(RenewalPlan $plan)
    {
        return [$plan->action, $plan->reason];
    }
}
