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
 * the same save, to the product's pro rata day; its setup fee as billed. Told also when only the first period's end would
 * differ. Also in the invoice's activity (ChangeOpenLine::AUDIT_EVENT, kept_because «order_line»).
 */
final class ProrataOrderLineNotice
{
    /**
     * @param  InvoiceItem  $line  the order's own open line, as refreshOpenPeriods kept it
     * @param  int|null  $fromPlanId  the plan before the change
     * @param  bool  $priceSet  staff set the recurring amount in the same save: the new plan's stub is priced at it
     */
    public static function tell(Service $service, InvoiceItem $line, ?int $fromPlanId, bool $priceSet): void
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
        if ($first !== null) {
            $stub = Prorata::firstPrice($recurring, $start, (string) $plan->billing_unit, (int) $plan->billing_period,
                (int) $product->prorata_day, $product->prorata_next_after_day);
            $end = $first['end'];
        } else {
            // The new plan is not pro rata (another cycle the product does not prorate): a whole first period.
            $stub = $recurring;
            $end = $service->periodEnd($start);
        }
        $setupFee = BigDecimal::of($billed->setup_fee === null ? '0' : (string) $billed->setup_fee);
        $newPrice = BigDecimal::of($stub)->plus($setupFee)->toScale(2, RoundingMode::HalfUp);
        $oldPrice = BigDecimal::of((string) $billed->price)->toScale(2, RoundingMode::HalfUp);
        $endMoves = $end !== null && $end->toDateString() !== $billed->period_end->toDateString();
        if ($newPrice->isEqualTo($oldPrice) && !$endMoves) {
            return; // the same stub: nothing to fix
        }

        $wouldBe = clone $billed;
        $wouldBe->price = (string) $newPrice;
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
            'period' => $dates, 'new_period_end' => $endMoves ? $end->toDateString() : null,
        ]);
        $quantity = max(1, (int) $billed->quantity);
        $body = "The plan of service #{$service->id} was changed" . ($from !== null ? " from «{$from->name}»" : '') . " to «{$plan->name}» while its order is unpaid. "
            . "Its order line on invoice #{$billed->invoice_id} keeps the old plan's pro rata price: {$oldPrice} {$currency} net (before VAT) per unit, period {$dates}"
            . ($setupFee->isPositive() ? ", its setup fee {$setupFee->toScale(2)} {$currency} included" : '') . '. '
            . "At the new plan it would be {$newPrice} {$currency} net per unit"
            . ($endMoves ? ', its first period to ' . $end->format('d/m/Y') : ', the same period')
            . ($setupFee->isPositive() ? ', the setup fee as billed' : '')
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
}
