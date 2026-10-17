<?php

namespace App\Services\Domains;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Payment\AutoCharge;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Domain expiry notices, the cron's «domain_reminders» step (docs/domains.md D-14, D7; WHMCS's «Upcoming Domain Renewal
 * Notice» and «Expired Domain Notice»).
 *
 * - On each day of domain_reminder_days («30,7,1,-3»): a positive day (or 0) before the expiry, «domain_renewal_notice»
 *   («domain_free_renewal_notice» at no price); a negative one after it, «domain_expired_notice» (while it can still be
 *   recovered: until its grace or redemption ends). The last call (a day or less before) is not sent for a renewal
 *   charged on its own (a saved payment method): nothing for the customer to do.
 * - Only domains that renew (auto renew on: «do not renew» gets none), the customer's (not ended), and not those whose
 *   next period is paid already (waiting for its registrar). An open renewal invoice is named, with its link.
 *
 * Each notice goes once per domain, day and expiry (domain_reminders): a renewal moves the expiry, and the next year's go
 * again. A run missed is caught up within CATCH_UP_DAYS (the latest day reached, the earlier ones marked sent).
 */
class DomainReminders
{
    public const CATCH_UP_DAYS = 3;

    /** The farthest day the setting takes, either side of the expiry. */
    public const MAX_DAYS = 365;

    /** Before the expiry (status Active) and after it (still the customer's). */
    private const AFTER = [DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption];

    /** @param  (callable(int, Throwable): void)|null  $failed  told of a domain whose notice failed */
    public function send(?callable $failed = null): int
    {
        $days = self::days();
        if ($days === []) {
            return 0;
        }
        $today = Carbon::today();
        // Within reach of a notice day: from max(days) before the expiry to the farthest day after it (and its catch-up).
        $domains = Domain::query()->where('auto_renew', true)->whereNotNull('expires_at')->whereNotNull('client_id')
            ->whereIn('status', array_map(fn (DomainStatus $s) => $s->value, [DomainStatus::Active, ...self::AFTER]))
            ->whereDate('expires_at', '<=', $today->copy()->addDays(max($days)))
            ->whereDate('expires_at', '>', $today->copy()->subDays(max(0, -min($days)) + self::CATCH_UP_DAYS))
            ->orderBy('id')->pluck('id');
        $sent = 0;
        foreach ($domains as $id) {
            $sent += $this->remind((int) $id, $days, $failed);
        }

        return $sent;
    }

    /** @return list<int> the notice days, from the farthest before the expiry to the farthest after («30,7,1,-3»), no repeats */
    public static function days(): array
    {
        $days = array_map('intval', array_filter(preg_split('/\s*,\s*/', trim((string) config('settings.domain_reminder_days', '30,7,1,-3'))) ?: [],
            fn ($day) => is_numeric($day)));
        $days = array_values(array_unique(array_filter($days, fn (int $day) => abs($day) <= self::MAX_DAYS)));
        rsort($days);

        return $days;
    }

    /** @param  list<int>  $days */
    private function remind(int $id, array $days, ?callable $failed): int
    {
        $domain = Domain::query()->with(['client', 'currency'])->find($id);
        if ($domain === null || $domain->expires_at === null || !$domain->auto_renew) {
            return 0;
        }
        $today = Carbon::today();
        $expires = $domain->expires_at->copy()->startOfDay();
        $left = (int) $today->diffInDays($expires, false); // negative: past the expiry
        // The latest notice day reached (days are from the farthest before to the farthest after).
        $reached = array_values(array_filter($days, fn (int $day) => $left <= $day));
        $day = end($reached);
        if ($day === false || $day - $left >= self::CATCH_UP_DAYS) {
            return 0;
        }
        $after = $day < 0;
        if ($after ? !in_array($domain->status, self::AFTER, true) : $domain->status !== DomainStatus::Active) {
            return 0;
        }
        $lastDay = $domain->redemptionEndsOn() ?? $domain->graceEndsOn() ?? $domain->expires_at;
        if ($after && $lastDay->copy()->startOfDay()->lessThan($today)) {
            return 0; // nothing left to recover
        }
        // Its next period paid already: it renews (D5b-2), nothing to remind of.
        $line = DomainRenewalService::billedLine($domain, $expires, payable: true);
        if ($line?->invoice?->status === Invoice::STATUS_PAID) {
            return 0;
        }

        $data = $this->data($domain, $left, $lastDay, $line);
        // The last call (a day or less before) only for what the customer still has to do: not one charged on its own.
        if (!$after && $day <= 1 && $data['invoice'] !== null && $data['auto_charged']) {
            return 0;
        }
        $key = match (true) {
            $after => 'domain_expired_notice',
            $data['free'] => 'domain_free_renewal_notice',
            default => 'domain_renewal_notice',
        };
        $row = ['domain_id' => $domain->id, 'days' => $day, 'expires_on' => $expires->toDateString()];
        // Taken first (unique): a second run, or one running at the same time, sends nothing again.
        if (DB::table('domain_reminders')->insertOrIgnore($row + ['sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]) === 0) {
            return 0;
        }
        foreach (array_slice($reached, 0, -1) as $skipped) {
            DB::table('domain_reminders')->insertOrIgnore(['days' => $skipped] + $row + ['sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        try {
            NotificationHelper::domainNotification($domain->client, $domain, $key, $data);
        } catch (Throwable $e) {
            // Not sent: tried again on the next run (within the catch-up days).
            DB::table('domain_reminders')->where($row)->delete();
            report($e);
            if ($failed !== null) {
                $failed($domain->id, $e);
            }

            return 0;
        }

        return 1;
    }

    /** @return array<string, mixed> what the templates read, besides {{ domain }} */
    private function data(Domain $domain, int $left, Carbon|CarbonInterface $lastDay, ?InvoiceItem $line): array
    {
        $invoice = $line?->invoice !== null && in_array($line->invoice->status, Invoice::OPEN_STATUSES, true) ? $line->invoice : null;
        $price = $domain->renewalPrice();
        $free = $price !== null && bccomp($price, '0', 2) === 0;
        // What renewing late costs on top (owner 2026-10-17: the point is paying before it): before the expiry the fee of
        // the first stage after it that has one; after it, the fee of the stage it is in.
        $stage = in_array($domain->status, [DomainStatus::Grace, DomainStatus::Redemption], true) ? $domain->status : null;
        $fee = $stage !== null ? $domain->stageFee($stage)
            : ($domain->graceDays() > 0 ? $domain->stageFee(DomainStatus::Grace) : null) ?? ($domain->redemptionDays() > 0 ? $domain->stageFee(DomainStatus::Redemption) : null);
        $money = fn (?string $amount) => $amount === null || bccomp($amount, '0', 2) <= 0 || $domain->currency === null ? ''
            : (new Price(['price' => $amount, 'currency' => $domain->currency]))->formatted->price;

        return [
            'fqdn' => $domain->fqdn,
            'expires' => $domain->expires_at?->format('d/m/Y'),
            'days_left' => max(0, $left),
            'days_past' => max(0, -$left),
            'last_day' => $lastDay->format('d/m/Y'),
            'free' => $free,
            'price' => $free ? '' : $money($price),
            'late_fee' => $free ? '' : $money($fee),
            'years' => max(1, (int) $domain->years),
            'invoice' => $invoice,
            // Its renewal invoice paid by the saved method on its own (D5b, PaymentReminders::chargedOnItsOwn).
            'auto_charged' => $invoice !== null ? AutoCharge::agreementFor($invoice) !== null : $domain->client->autoChargeAgreement() !== null,
        ];
    }
}
