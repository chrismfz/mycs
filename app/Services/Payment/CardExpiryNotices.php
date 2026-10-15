<?php

namespace App\Services\Payment;

use App\Helpers\NotificationHelper;
use App\Models\BillingAgreement;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The expiry notice of saved payment methods (PR H2b, sweep Π7.7; WHMCS CCDaySendExpiryNotices = 25 at both companies):
 * from the day of the month set, a method expiring at the end of this month is told to its customer, once a month. A
 * missed day (the cron down) is sent the next one. 0 turns it off.
 */
final class CardExpiryNotices
{
    /**
     * @param  callable(int, Throwable): void|null  $failed  a method whose notice could not be sent
     * @return int the notices sent
     */
    public function run(?callable $failed = null): int
    {
        $day = self::day();
        $today = CarbonImmutable::today();
        if ($day === 0 || $today->day < $day) {
            return 0;
        }
        $sent = 0;
        $due = BillingAgreement::query()->with('client')
            ->whereNotNull('expiry')
            ->whereBetween('expiry', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])
            ->where(fn ($query) => $query->whereNull('expiry_notified_at')->orWhere('expiry_notified_at', '<', $today->startOfMonth()))
            ->orderBy('id');
        foreach ($due->get() as $agreement) {
            try {
                // Marked first, under a condition: two cron runs at once send it once.
                $claimed = BillingAgreement::query()->whereKey($agreement->id)
                    ->where(fn ($query) => $query->whereNull('expiry_notified_at')->orWhere('expiry_notified_at', '<', $today->startOfMonth()))
                    ->update(['expiry_notified_at' => now()]);
                if ($claimed === 0 || $agreement->client === null) {
                    continue;
                }
                NotificationHelper::cardExpiringNotification($agreement->client, $agreement);
                $sent++;
            } catch (Throwable $e) {
                BillingAgreement::query()->whereKey($agreement->id)->update(['expiry_notified_at' => $agreement->expiry_notified_at]);
                report($e);
                if ($failed !== null) {
                    $failed($agreement->id, $e);
                }
            }
        }

        return $sent;
    }

    /** The day of the month the notices start (0: off). */
    public static function day(): int
    {
        return min(28, max(0, (int) config('settings.cc_expiry_notice_day', 25)));
    }
}
