<?php

namespace App\Services\Service;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Service;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Money\PaymentsLog;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MyCS\BillingPeriod\Prorata;
use Throwable;

/**
 * Owner 2026-11-08 (REV3-L-ROUNDING-TAX-2): staff change the plan of a service whose order is unpaid, and the order's own
 * line is a pro rata first period (OrderPricing::firstPeriod). The line keeps its price, setup fee and period
 * (Service::refreshOpenPeriods: rebuilt as a renewal, its setup fee and its day would be lost), so staff are told, once
 * per change: the price it keeps (the old plan's stub) and the price the new plan's stub would be, for the same first
 * day, so they fix the line or cancel the invoice and issue it again. Nothing is changed here.
 *
 * The new plan's stub is what an order of it on the line's first day would charge (OrderPricing::firstPaymentNet): its
 * recurring amount for the first cycle (catalog, options, a first-cycle coupon, the group discount), or the one staff set in
 * the same save, to the product's pro rata day; its own setup fee (review REV-PPN-2). A one-time or free plan (no period)
 * too (REV-PPN-1). Told also when only the first period's end would
 * differ. Also in the invoice's activity (ChangeOpenLine::AUDIT_EVENT, kept_because «order_line»).
 */
final class ProrataOrderLineNotice
{
    /**
     * @param  InvoiceItem  $line  the order's own open line, as refreshOpenPeriods kept it
     * @param  int|null  $fromPlanId  the plan before the change
     * @param  bool  $priceSet  staff set the recurring amount in the same save: the new plan's stub is priced at it
     * @param  bool  $scheduled  the plan set for the next renewal, applied by the cron, not a change by staff (REV-PPN-4)
     */
    public static function tell(Service $service, InvoiceItem $line, ?int $fromPlanId, bool $priceSet, bool $scheduled = false): void
    {
        $billed = InvoiceItem::query()->with('invoice')->find($line->getKey());
        if ($billed === null || $billed->invoice === null || !in_array($billed->invoice->status, Invoice::OPEN_STATUSES, true)
            || $billed->period_start === null || $billed->period_end === null) {
            return;
        }
        $plan = $service->plan;
        $product = $service->product;
        if ($plan === null || $product === null) {
            return;
        }
        $recurring = (string) BigDecimal::of((string) ($priceSet ? $service->price : $service->calculatePrice(cycle: 1)))
            ->toScale(2, RoundingMode::HalfUp);
        $start = CarbonImmutable::instance($billed->period_start)->startOfDay();
        $first = OrderPricing::firstPeriod($product, $plan, $start);
        $periodless = in_array($plan->type, ['one-time', 'free'], true);
        if ($first !== null) {
            $stub = Prorata::firstPrice($recurring, $start, (string) $plan->billing_unit, (int) $plan->billing_period,
                (int) $product->prorata_day, $product->prorata_next_after_day);
            $end = $first['end'];
        } else {
            // The new plan is not pro rata (another cycle the product does not prorate): a whole first period; a one-time
            // or free plan none (its order line has no period).
            $stub = $recurring;
            $end = $periodless ? null : $service->periodEnd($start);
        }
        // The new plan's own setup fee, as its order would charge it (review REV-PPN-2), not the old one's on the line.
        $setupFee = BigDecimal::of(self::setupFee($service));
        $newPrice = BigDecimal::of($stub)->plus($setupFee)->toScale(2, RoundingMode::HalfUp);
        $oldPrice = BigDecimal::of((string) $billed->price)->toScale(2, RoundingMode::HalfUp);
        $endMoves = $end !== null && $end->toDateString() !== $billed->period_end->toDateString();
        $billedSetupFee = BigDecimal::of($billed->setup_fee === null ? '0' : (string) $billed->setup_fee)->toScale(2, RoundingMode::HalfUp);
        if ($newPrice->isEqualTo($oldPrice) && !$endMoves) {
            return; // the same stub: nothing to fix
        }

        $wouldBe = clone $billed;
        $wouldBe->price = (string) $newPrice;
        if (!$setupFee->toScale(2, RoundingMode::HalfUp)->isEqualTo($billedSetupFee)) {
            $wouldBe->setup_fee = (string) $setupFee->toScale(2, RoundingMode::HalfUp);
        }
        if ($endMoves) {
            $wouldBe->period_end = $end;
        }
        ChangeOpenLine::recordKept($billed, $wouldBe, $billed->invoice, ChangeOpenLine::KEPT_ORDER_LINE);

        $currency = (string) $service->currency_code;
        $from = $fromPlanId === null ? null : Plan::query()->find($fromPlanId);
        $dates = $billed->period_start->format('d/m/Y') . ' - ' . $billed->period_end->format('d/m/Y');
        PaymentsLog::warning('Plan change of a service whose order is unpaid: the order\'s pro rata line keeps the old plan\'s price, staff are told.', [
            'service_id' => $service->id, 'invoice_id' => $billed->invoice_id, 'invoice_item_id' => $billed->id,
            'from_plan_id' => $fromPlanId, 'plan_id' => $plan->id, 'price' => (string) $oldPrice, 'new_plan_price' => (string) $newPrice,
            'period' => $dates, 'scheduled' => $scheduled, 'new_period_end' => $endMoves ? $end->toDateString() : null,
        ]);
        $quantity = max(1, (int) $billed->quantity);
        $body = "The plan of service #{$service->id} was changed" . ($from !== null ? " from «{$from->name}»" : '') . " to «{$plan->name}»" . ($scheduled ? ' by the cron (the plan set for its next renewal)' : '') . ' while its order is unpaid. '
            . "Its order line on invoice #{$billed->invoice_id} keeps the old plan's pro rata price: {$oldPrice} {$currency} net (before VAT) per unit, period {$dates}"
            . ($billedSetupFee->isPositive() ? ", its setup fee {$billedSetupFee} {$currency} included" : '') . '. '
            . "At the new plan it would be {$newPrice} {$currency} net per unit"
            . ($periodless ? ', without a period (a ' . $plan->type . ' plan)' : ($endMoves ? ', its first period to ' . $end->format('d/m/Y') : ', the same period'))
            . ($setupFee->isPositive() ? ", its setup fee {$setupFee->toScale(2)} {$currency} included" : '')
            . ($quantity > 1 ? " (× {$quantity})" : '') . '. '
            . 'Fix the line\'s price on the invoice, or cancel the invoice and issue it again.';
        // As the upgrade's notice (tellOfKeptPeriods): once it commits; a mail that fails is reported, stops nothing.
        DB::afterCommit(function () use ($body) {
            try {
                NotificationHelper::sendSystemEmailNotification('Plan changed on an unpaid pro rata order: its order line keeps the old price, needs staff attention', e($body));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * The new plan's setup fee for one unit, net, as its order would charge it: the catalog's and its options' for the
     * plan's cycle, less a first-cycle coupon and the group discount (OrderPricing::line, which also takes a fixed coupon
     * off the price first). A free plan, or one without a price in the service's currency: none.
     */
    private static function setupFee(Service $service): string
    {
        $plan = $service->plan;
        if ($plan === null || $plan->type === 'free' || !$plan->hasPriceIn($service->currency_code)) {
            return '0.00';
        }
        $row = $plan->price($service->currency_code);
        $price = BigDecimal::of(number_format((float) $row->price, 2, '.', ''));
        $setupFee = BigDecimal::of(number_format((float) $row->setup_fee, 2, '.', ''));
        $options = BigDecimal::zero();
        foreach ($service->configs as $config) {
            if ($config->configValue) {
                $optionPrice = $config->configValue->price(null, $plan->billing_period, $plan->billing_unit, $service->currency_code);
                $option = number_format((float) $optionPrice->price, 2, '.', '');
                $price = $price->plus($option);
                $options = $options->plus($option);
                $setupFee = $setupFee->plus(number_format((float) $optionPrice->setup_fee, 2, '.', ''));
            }
        }
        $discount = null;
        $coupon = $service->coupon;
        if ($coupon !== null && $service->coupon_expired_at === null && $coupon->requirementHeldBy($service->client)) {
            $discount = OrderPricing::discount($coupon, (int) $service->product_id, $plan, $service->currency_code);
        }

        return OrderPricing::line(['price' => (string) $price, 'setup_fee' => (string) $setupFee, 'options' => (string) $options],
            $discount, $service->client, $service->product)->netSetupFee;
    }
}
