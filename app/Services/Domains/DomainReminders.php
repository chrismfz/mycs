<?php

namespace App\Services\Domains;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\NotificationTemplate;
use App\Services\Invoice\PaymentReminders;
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

    /** An expiry moved by this many days or fewer is the same one: its notices are not sent again (review R5). */
    private const SAME_EXPIRY_DAYS = 7;

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

    /** Its last recoverable day: the end of its redemption, else of its grace, else its expiry. */
    public static function lastDay(Domain $domain): CarbonInterface
    {
        return $domain->redemptionEndsOn() ?? $domain->graceEndsOn() ?? $domain->expires_at;
    }

    /** Gets notices: renewing, the customer's, before the expiry Active, after it still recoverable. */
    public static function notifiable(Domain $domain, bool $after): bool
    {
        if (!$domain->auto_renew || $domain->expires_at === null) {
            return false;
        }
        if (!$after) {
            return $domain->status === DomainStatus::Active;
        }

        return in_array($domain->status, self::AFTER, true) && !self::lastDay($domain)->copy()->startOfDay()->lessThan(Carbon::today());
    }

    /**
     * Its expiry notices stand in for the invoice's own reminders (review R1): every line the renewal of the current
     * expiry of a domain that gets them (or its stage fee), with a notice day on that side of the expiry («due»: before,
     * «overdue»: after) and the template on. Else the invoice keeps its reminders.
     */
    public static function covers(Invoice $invoice, string $kind): bool
    {
        $days = self::days();
        $after = $kind !== 'due';
        $template = $after ? 'domain_expired_notice' : 'domain_renewal_notice';
        if ($invoice->items->isEmpty() || ($after ? min($days ?: [0]) >= 0 : max($days ?: [-1]) < 0)
            || !NotificationTemplate::query()->where('key', $template)->where('enabled', true)->exists()) {
            return false;
        }
        foreach ($invoice->items as $line) {
            $domain = $line->reference_type === Domain::class ? Domain::query()->find($line->reference_id) : null;
            if ($domain === null || $domain->client_id !== $invoice->client_id) {
                return false;
            }
            // The very invoice its notice names (adversarial ADV-4/4b): its current expiry's renewal, unpaid; a stage fee
            // billed apart, or a line staff added, keeps its own reminders.
            // A line with a period only (review R6: billedLine also takes a hand-made line without one).
            $renewal = $domain->expires_at !== null ? DomainRenewalService::billedLine($domain, $domain->expires_at->copy()->startOfDay(), payable: true) : null;
            $named = $renewal?->period_start !== null ? $renewal->invoice_id : null;
            // After the due date: the domain past its expiry gets the expired notice; one still Active (synced late)
            // gets it within the catch-up days.
            if ((int) $named !== (int) $invoice->id || !(self::notifiable($domain, false) || self::notifiable($domain, true))) {
                return false;
            }
        }

        return true;
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
        // A day before the expiry never once it is past (review R4: a missed run caught up the day after).
        if (!$after && $left < 0) {
            return 0;
        }
        if (!self::notifiable($domain, $after)) {
            return 0;
        }
        $lastDay = self::lastDay($domain);
        // Its next period paid already: it renews (D5b-2), nothing to remind of.
        $line = DomainRenewalService::billedLine($domain, $expires, payable: true);
        if ($line?->invoice?->status === Invoice::STATUS_PAID) {
            return 0;
        }

        // A payment on its way (adversarial ADV-5): nothing to ask for, as the invoice reminders.
        if ($line?->invoice !== null && $line->invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists()) {
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
        // Sent for an expiry a few days off (review R5: the registrar's sync corrected it): not again.
        if (DB::table('domain_reminders')->where('domain_id', $domain->id)->where('days', $day)
            ->whereBetween('expires_on', [$expires->copy()->subDays(self::SAME_EXPIRY_DAYS)->toDateString(), $expires->copy()->addDays(self::SAME_EXPIRY_DAYS)->toDateString()])
            ->exists()) {
            return 0;
        }
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
        // Unpaid only: a draft is not the customer's to see (review R3).
        $invoice = $line?->invoice !== null && $line->invoice->status === Invoice::STATUS_UNPAID ? $line->invoice : null;
        $price = $domain->renewalPrice();
        // Free by its open invoice when it has one (adversarial ADV-9: billed at a price since), else by its price.
        $free = $invoice !== null ? $invoice->remaining <= 0 : $price !== null && bccomp($price, '0', 2) === 0;
        // What renewing late costs on top (owner 2026-10-17: the point is paying before it): before the expiry the fee of
        // the first stage after it that has one; after it, the fee of the stage it is in.
        $stage = in_array($domain->status, [DomainStatus::Grace, DomainStatus::Redemption], true) ? $domain->status : null;
        // The stage right after the expiry: grace if it has one, else redemption (review R7: never redemption's fee for a
        // grace that is not priced).
        $fee = match (true) {
            $stage !== null => $domain->stageFee($stage),
            $domain->graceDays() > 0 || $domain->graceEndsOn() !== null => $domain->stageFee(DomainStatus::Grace),
            $domain->redemptionDays() > 0 || $domain->redemptionEndsOn() !== null => $domain->stageFee(DomainStatus::Redemption),
            default => null,
        };
        $money = fn (?string $amount) => $amount === null || bccomp($amount, '0', 2) <= 0 || $domain->currency === null ? ''
            : (new Price(['price' => $amount, 'currency' => $domain->currency]))->formatted->price;

        return [
            'fqdn' => $domain->fqdn,
            'expires' => $domain->expires_at?->format('d/m/Y'),
            'days_left' => max(0, $left),
            'days_past' => max(0, -$left),
            'last_day' => $lastDay->format('d/m/Y'),
            // In grace with a redemption after it (adversarial ADV-8): the grace fee until its end, then the restore fee.
            'stage_end' => ($stage === DomainStatus::Grace ? $domain->graceEndsOn() : null)?->format('d/m/Y') ?? $lastDay->format('d/m/Y'),
            'next_fee' => $stage === DomainStatus::Grace && $domain->redemptionEndsOn() !== null ? $money($domain->stageFee(DomainStatus::Redemption)) : '',
            'free' => $free,
            'price' => $free ? '' : $money($price),
            'late_fee' => $free ? '' : $money($fee),
            'years' => max(1, (int) $domain->years),
            'invoice' => $invoice,
            // Charged without the customer, by AutoCharge's own rules (review R2: not a mixed invoice it leaves alone).
            'auto_charged' => $invoice !== null ? PaymentReminders::chargedOnItsOwn($invoice) : $domain->client->autoChargeAgreement() !== null,
        ];
    }
}
