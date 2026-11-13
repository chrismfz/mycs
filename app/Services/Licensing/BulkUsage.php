<?php

namespace App\Services\Licensing;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\License;
use App\Models\LicenseUsagePeriod;
use App\Models\Service;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use MyCS\BillingPeriod\BillingPeriod;
use MyCS\Pricing\LinePricer;
use MyCS\Pricing\Tiers;
use MyCS\Pricing\UsageCharge;

/**
 * A bulk license's billing (docs/zonecloud-bulk-license.md §6.0, the owner's decisions of 2026-11-12):
 * - in advance, each period at cost(IPs on its list when it is invoiced), by graduated tiers: the license's own (agreed
 *   prices) or its product's;
 * - monthly only; no cycle change or upgrade (ChangeCycle::refusal, Service::upgradable); a staff edit of a renewal line's price
 *   or the account group's discount is not kept (the period is closed at its tiers): agreed prices are the license's tiers;
 * - once a paid period is over, it is closed at what it really cost: every distinct IP that was on the list during it,
 *   for the whole period (an agent that cannot reach zlic runs to its stored due date). More than billed: a line on the
 *   next renewal invoice. Less: credit at once.
 *
 * Called by the renewal billing under the service's lock. Days are the company's (settings.timezone).
 */
final class BulkUsage
{
    /**
     * «1=1.80, 51=1.50» as Tiers steps.
     *
     * @return list<array{from: string, price: string}>
     */
    public static function parseTiers(string $text): array
    {
        $steps = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $step) {
            if (preg_match('/^(\d+)=(\d+(?:\.\d{1,2})?)$/', $step, $m) !== 1) {
                throw new DisplayException(__('«:step» is not a tier: write «from=price», e.g. «1=1.80, 51=1.50».', ['step' => mb_substr($step, 0, 32)]));
            }
            $steps[] = ['from' => $m[1], 'price' => $m[2]];
        }
        try {
            Tiers::of($steps);
        } catch (\InvalidArgumentException $e) {
            throw new DisplayException(__('The tiers start at 1 and each starts higher than the last.'));
        }

        return $steps;
    }

    /** The tiers that price $service: its license's own, else its product's (the «tiers» setting). */
    public static function tiersText(Service $service): string
    {
        $own = trim((string) $service->license?->tiers);
        if ($own !== '') {
            return $own;
        }

        return trim((string) (ExtensionHelper::settingsToArray($service->product->settings ?? collect())['tiers'] ?? ''));
    }

    public static function tiers(Service $service): Tiers
    {
        $text = self::tiersText($service);
        if ($text === '') {
            throw new DisplayException("Service #{$service->id}: its bulk product has no tiers (set them on the product).");
        }

        return Tiers::of(self::parseTiers($text));
    }

    /**
     * What the period that is invoiced now costs in advance: the IPs on the list now.
     *
     * @return array{price: string, ips: int, note: string}
     */
    public static function advance(Service $service): array
    {
        $tiers = self::tiers($service);
        $ips = $service->license === null ? 0 : $service->license->ips()->active()->count();

        // A catalog amount, as every price: net of the tax it holds when the company's prices include it (ADV-823-4).
        return ['price' => OrderPricing::toNet((string) $tiers->cost($ips), $service->client, $service->product), 'ips' => $ips, 'note' => self::note($tiers, $ips)];
    }

    /** «60 IPs: 50 × 1.80 + 10 × 1.50» for a line. */
    public static function note(Tiers $tiers, int $ips): string
    {
        $parts = array_map(fn ($row) => $row[0] . ' × ' . $row[1], $tiers->breakdown($ips));

        return trans_choice(':count IP|:count IPs', $ips, ['count' => $ips]) . ($parts === [] ? '' : ': ' . implode(' + ', $parts));
    }

    /**
     * Closes every paid period of the service that is over and not closed yet: its distinct IPs, priced, against what it
     * was billed. Less than billed: the difference (with its tax) is credit now. More: left for the next renewal invoice
     * (owed()). A period whose invoice is not paid waits; one cancelled or refunded is never billed, so never closed.
     *
     * @return list<LicenseUsagePeriod> the periods closed now
     */
    public static function close(Service $service): array
    {
        $license = $service->license;
        if ($license === null) {
            return [];
        }
        $zone = new DateTimeZone((string) config('settings.timezone', 'UTC'));
        $today = CarbonImmutable::now($zone)->startOfDay();
        $closed = LicenseUsagePeriod::query()->where('license_id', $license->id)->pluck('period_start')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())->all();
        $lines = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereNotNull('period_start')->whereNotNull('period_end')
            ->where(fn ($line) => $line->whereNull('kind')->orWhereNotIn('kind', [InvoiceItem::KIND_CHARGE, InvoiceItem::KIND_DISCOUNT]))
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_PAID))
            ->orderBy('period_start')->get();
        $intervals = $license->ips()->get(['ip', 'added_at', 'removed_at'])
            ->map(fn ($row) => [$row->ip, $row->added_at->toImmutable(), $row->removed_at?->toImmutable()])->all();

        $done = [];
        foreach ($lines as $line) {
            $start = CarbonImmutable::parse($line->period_start->toDateString(), $zone);
            $end = CarbonImmutable::parse($line->period_end->toDateString(), $zone);
            if ($end->greaterThan($today) || in_array($start->toDateString(), $closed, true)) {
                continue;
            }
            $tiers = self::tiers($service);
            $days = (int) $start->diffInDays($end);
            $cycleDays = max($days, (int) $start->diffInDays(BillingPeriod::end($start, (string) $service->plan?->billing_unit,
                max(1, (int) $service->plan?->billing_period), $service->billing_day)));
            $ips = UsageCharge::distinctUnits($intervals, $start, $end);
            $actual = OrderPricing::toNet(UsageCharge::periodCharge($tiers, $ips, $days, $cycleDays), $service->client, $service->product);
            $billed = self::lineNet($line);
            $adjustment = UsageCharge::adjustment($actual, $billed);

            $period = LicenseUsagePeriod::query()->create([
                'license_id' => $license->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'invoice_item_id' => $line->id, 'ips' => $ips, 'actual' => $actual, 'billed' => $billed, 'adjustment' => $adjustment,
                'tiers' => mb_substr(self::tiersText($service), 0, 255), 'closed_at' => now(),
            ]);
            if (BigDecimal::of($adjustment)->isNegative()) {
                // Given back with the tax it was billed with (Ε5: credit; a refund only by hand).
                $gross = LinePricer::invoiceLine((string) BigDecimal::of($adjustment)->abs(), 1, OrderPricing::taxRate($service->client, $service->product))['total'];
                // To whoever paid the period, in its currency (a service moved since: its first customer's, ADV-823-5).
                (new CreditLedger)->add((int) $line->invoice->client_id, (string) $line->invoice->currency_code, (int) (string) BigDecimal::of($gross)->multipliedBy(100)->toScale(0),
                    MoneyTrail::CAUSE_USAGE_CORRECTION, MoneyTrail::SOURCE_SYSTEM, $line->invoice,
                    note: "Service #{$service->id}, {$start->toDateString()} – {$end->toDateString()}: {$ips} IPs, {$actual} instead of {$billed}");
            }
            $closed[] = $start->toDateString();
            $done[] = $period;
        }

        return $done;
    }

    /**
     * The period running today, for the customer's screen: what was billed for it, and what it comes to if the list stays
     * as it is (every IP that has been or is on it in the period). Null when no paid renewal line covers today.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, ips: int, billed: string, estimate: string, correction: string}|null
     */
    public static function thisPeriod(Service $service): ?array
    {
        $license = $service->license;
        if ($license === null) {
            return null;
        }
        $zone = new DateTimeZone((string) config('settings.timezone', 'UTC'));
        $today = CarbonImmutable::now($zone)->toDateString();
        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereNotNull('period_start')->whereNotNull('period_end')
            ->where(fn ($line) => $line->whereNull('kind')->orWhereNotIn('kind', [InvoiceItem::KIND_CHARGE, InvoiceItem::KIND_DISCOUNT]))
            ->whereDate('period_start', '<=', $today)->whereDate('period_end', '>', $today)
            // Paid, as close() takes it: an unpaid one is not «billed» yet (review of #825).
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_PAID))
            ->orderByDesc('period_start')->first();
        if ($line === null) {
            return null;
        }
        $start = CarbonImmutable::parse($line->period_start->toDateString(), $zone);
        $end = CarbonImmutable::parse($line->period_end->toDateString(), $zone);
        $days = (int) $start->diffInDays($end);
        $cycleDays = max($days, (int) $start->diffInDays(BillingPeriod::end($start, (string) $service->plan?->billing_unit,
            max(1, (int) $service->plan?->billing_period), $service->billing_day)));
        $intervals = $license->ips()->get(['ip', 'added_at', 'removed_at'])
            ->map(fn ($row) => [$row->ip, $row->added_at->toImmutable(), $row->removed_at?->toImmutable()])->all();
        $ips = UsageCharge::distinctUnits($intervals, $start, $end);
        $estimate = OrderPricing::toNet(UsageCharge::periodCharge(self::tiers($service), $ips, $days, $cycleDays), $service->client, $service->product);
        $billed = self::lineNet($line);

        return ['start' => $start, 'end' => $end, 'ips' => $ips, 'billed' => $billed, 'estimate' => $estimate,
            'correction' => UsageCharge::adjustment($estimate, $billed)];
    }

    /**
     * A line's net for its period, (price − setup fee) × quantity, from the stored decimals (never a float). An order's
     * line holds its setup fee in its price: that fee is not usage, never counted against the IPs (ADV-832-1/2).
     */
    private static function lineNet(InvoiceItem $line): string
    {
        $price = BigDecimal::of((string) $line->getRawOriginal('price'))->minus(BigDecimal::of((string) ($line->getRawOriginal('setup_fee') ?? '0')));

        return (string) $price->multipliedBy(max(1, (int) $line->quantity))->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * The closed periods that cost more than billed and are not on an invoice yet.
     *
     * @return Collection<int, LicenseUsagePeriod>
     */
    public static function owed(License $license)
    {
        // Not on a live invoice: never put on one, or its line deleted, or its invoice cancelled (re-issued, ADV-823-3).
        // To waive one, staff set its line to 0.00 and the invoice is paid.
        return LicenseUsagePeriod::query()->where('license_id', $license->id)->where('adjustment', '>', 0)
            ->where(fn ($period) => $period->whereNull('adjustment_item_id')
                ->orWhereHas('adjustmentItem.invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_CANCELLED)))
            ->orderBy('period_start')->get();
    }

    /** The line of an owed correction: «Usage 01/10/2026 – 31/10/2026: 80 IPs, 135.00 billed 105.00». */
    public static function correctionLine(LicenseUsagePeriod $period): string
    {
        return __('Bulk license usage :from – :to: :ips, :actual instead of :billed billed in advance', [
            'from' => $period->period_start->format('d/m/Y'), 'to' => $period->period_end->copy()->subDay()->format('d/m/Y'),
            'ips' => trans_choice(':count IP|:count IPs', $period->ips, ['count' => $period->ips]),
            'actual' => $period->actual, 'billed' => $period->billed,
        ]);
    }
}
