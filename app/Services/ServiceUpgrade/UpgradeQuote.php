<?php

namespace App\Services\ServiceUpgrade;

use App\Models\ServiceUpgrade;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use MyCS\Pricing\DifferenceDiscount;
use MyCS\Pricing\DifferenceDiscountRefused;
use MyCS\Pricing\LinePricer;

/**
 * What a change of package does before it is placed, as WHMCS's «Upgrade Summary» (owner 2026-11-04): the days left of the
 * period and the days in it, what is due now (an upgrade: the difference for the days left, with tax), what a downgrade
 * gives back (only with «Credit on downgrade» and credits on; else nothing, as WHMCS: no credit and no days given), and
 * what it renews at now and after. The staff's «Upgrade/Downgrade» and the customer's upgrade page show it; `PlaceUpgrade`
 * charges and credits by the same numbers.
 */
final class UpgradeQuote
{
    private function __construct(
        /** Days from today to its expiry (all of them, a renewal paid ahead too), or null without a period (one-time, free). */
        public readonly ?int $daysLeft,
        /** Days of the period it is in (more than one cycle when a renewal was paid ahead). */
        public readonly ?int $daysInPeriod,
        /** Due now, with tax: what the upgrade's invoice charges; 0.00 for a downgrade. */
        public readonly string $dueNow,
        /** Given back as credit now, with tax: 0.00 unless a downgrade and the settings give credit. */
        public readonly string $credit,
        /** It lowers the recurring amount (the new package is cheaper for the days left). */
        public readonly bool $downgrade,
        /** What it renews at now and after the change, per period with tax. */
        public readonly string $renewsNow,
        public readonly string $renewsAfter,
    ) {}

    /** The quote of an upgrade as chosen (saved or not), its `service` loaded, on $today. */
    public static function for(ServiceUpgrade $upgrade, ?CarbonImmutable $today = null): self
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $service = $upgrade->service;
        $start = $service->currentPeriodStart();
        $end = $service->expires_at === null ? null : CarbonImmutable::instance($service->expires_at)->startOfDay();
        $daysLeft = $daysInPeriod = null;
        if ($start !== null && $end !== null) {
            $daysInPeriod = (int) $start->diffInDays($end);
            // Every day to the expiry, as BillingPeriod::remaining charges them: more than the period's when a renewal
            // was paid ahead (ADV-UPG-3 of #638: «31 / 31» was shown for 47 days charged).
            $daysLeft = max(0, (int) $today->diffInDays($end, false));
        }

        $net = (float) $upgrade->calculatePrice()->price;
        $downgrade = $net < 0;
        $dueNow = $downgrade ? '0.00' : number_format((float) $upgrade->grossPrice()->price, 2, '.', '');

        $quantity = max(1, (int) $service->quantity);
        $renews = fn (string $netPrice, $product) => LinePricer::invoiceLine($netPrice, $quantity,
            OrderPricing::taxRate($service->client, $product))['total'];

        return new self($daysLeft, $daysInPeriod, $dueNow, $downgrade ? self::credit($upgrade, $today) : '0.00', $downgrade,
            (string) $renews(number_format((float) $service->price, 2, '.', ''), $service->product),
            (string) $renews($upgrade->newRecurringPrice(), $upgrade->product ?? $service->product));
    }

    /**
     * Staff's discount on what an upgrade charges now (owner 2026-11-06): $mode a DifferenceDiscount one (a percentage, an
     * amount to pay with tax, or waived), on the difference its invoice line charges, at that line's tax rate (as
     * grossPrice and LineTax decide it: the new product's). Priced as the invoice prices its two lines.
     *
     * @throws DifferenceDiscountRefused a downgrade or nothing due (REFUSED_NOTHING_DUE), too large, not a number
     */
    public static function discount(ServiceUpgrade $upgrade, string $mode, string $value = ''): DifferenceDiscount
    {
        $service = $upgrade->service;

        return DifferenceDiscount::of(number_format((float) $upgrade->calculatePrice()->price, 2, '.', ''),
            OrderPricing::taxRate($service->client, $upgrade->product ?? $service->product), $mode, $value);
    }

    /** A downgrade gives credit only where staff turned it on: «Credit on downgrade» and credits (AUTH-5). */
    public static function givesCredit(): bool
    {
        return (bool) config('settings.credits_on_downgrade', true) && (bool) config('settings.credits_enabled', false);
    }

    /**
     * What a downgrade gives back, with tax, before the service changes: never more than what is left of what was paid,
     * less what earlier downgrades gave back and what the days left cost on the new plan (DowngradeCredit: P-1, #326).
     * 0.00 where the settings give no credit.
     */
    public static function credit(ServiceUpgrade $upgrade, CarbonImmutable $today): string
    {
        if (!self::givesCredit()) {
            return '0.00';
        }
        $gross = abs((float) $upgrade->grossPrice()->price);

        return (string) DowngradeCredit::credit($upgrade->service, $today,
            BigDecimal::of(number_format($gross, 2, '.', '')), BigDecimal::of($upgrade->newPriceForDaysLeft()));
    }
}
