<?php

namespace App\Services\Service;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use App\Services\Payment\ItemPaymentMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MyCS\BillingPeriod\BillingPeriod;
use Throwable;

/**
 * Staff's «Add addon» (docs/addons.md, step 3): an addon fastened to a running service, billed one of two ways. An addon
 * always shares its parent's due date (owner 2026-11-08): there is no «own date».
 * - prorated (the default, owner 2026-11-04): invoiced now for the days up to the parent's next due date (counted back by
 *   the addon's own cycle when that is shorter), then renewed with it, on the same invoice when due the same day;
 * - no invoice: started now, due on the date a prorated one would end (its parent's, counted back by its own cycle when
 *   that is shorter), free or paid some other way. No other date: there is no field for one (REV-KISS-5).
 * Invoiced, it waits pending until paid; paid, it starts and its creation is a task for staff on the parent's machine
 * (RenewServiceService). Started without an invoice, the task is made at once.
 */
final class AddAddon
{
    public const PRORATED = 'prorated';

    public const NO_INVOICE = 'no_invoice';

    /** Why no addon can be added to this service now, or null. */
    public static function refusal(Service $parent): ?string
    {
        return match (true) {
            $parent->parent_service_id !== null => __('services.addons.add.refused_addon'),
            !in_array($parent->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true) => __('services.addons.add.refused_status'),
            $parent->cancellation()->exists() => __('services.addons.add.refused_cancelling'),
            default => null,
        };
    }

    /**
     * The addon products that can be added to it: addons, not retired, that apply to it, with a recurring or free plan
     * priced in its currency.
     *
     * @return Collection<int, Product>
     */
    public static function products(Service $parent): Collection
    {
        return Product::query()->where('is_addon', true)->where('retired', false)->with('plans.prices')->orderBy('name')->get()
            ->filter(fn (Product $product) => $product->appliesTo($parent) && self::plans($product, $parent)->isNotEmpty())->values();
    }

    /** @return Collection<int, Plan> its plans an addon of $parent can be on */
    public static function plans(Product $product, Service $parent): Collection
    {
        return $product->plans->filter(fn (Plan $plan) => in_array($plan->type, ['recurring', 'free'], true) && $plan->hasPriceIn($parent->currency_code))->values();
    }

    /**
     * Where a prorated first period ends: the parent's next due date, counted back by the addon's cycle while that is still
     * ahead (a monthly addon of a yearly service renews monthly on the service's day). Null: nothing to prorate to (no due
     * date ahead).
     */
    public static function alignedEnd(Service $parent, Plan $plan, ?CarbonInterface $today = null): ?CarbonImmutable
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();
        if ($parent->expires_at === null || $plan->type !== 'recurring') {
            return null;
        }
        $due = CarbonImmutable::parse($parent->expires_at)->startOfDay();
        if (!$due->greaterThan($today)) {
            return null;
        }
        $count = max(1, (int) $plan->billing_period);
        // Counted back from the parent's date itself, k cycles at once: a 31st stays the 31st where the month has one, never
        // drifting to the 28th step by step (code review 2 of #637).
        // On its billing day too: 28 February on the 31st steps back to 31 January (sweep CRON-BILLING-3).
        $anchor = $parent->billingDay($due);
        $end = $due;
        for ($k = 1; ($back = BillingPeriod::start($due, $plan->billing_unit, $count * $k, $anchor))->greaterThan($today); $k++) {
            $end = $back;
        }

        return $end;
    }

    /**
     * What it would bill, per unit: [period start, period end, amount]; none for «no invoice» or a free plan.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}|null
     */
    public static function quote(Service $parent, Plan $plan, string $price, string $start): ?array
    {
        $today = CarbonImmutable::today();
        if ($start === self::NO_INVOICE || $plan->type !== 'recurring') {
            return null;
        }
        $end = self::alignedEnd($parent, $plan) ?? throw new DisplayException(__('services.addons.add.no_due_date'));
        $full = self::fullStart($parent, $plan, $end);

        return [$today, $end, self::plusSetupFee(BillingPeriod::remaining($price, $full, $end, $today), $plan, $parent->currency_code)];
    }

    /** Start of the whole cycle of $plan that ends on $end, on its parent's billing day (sweep CRON-BILLING-3). */
    public static function fullStart(Service $parent, Plan $plan, CarbonInterface $end): CarbonImmutable
    {
        $due = $parent->expires_at !== null ? CarbonImmutable::parse($parent->expires_at)->startOfDay() : $end;

        return BillingPeriod::start($end, $plan->billing_unit, max(1, (int) $plan->billing_period), $parent->billingDay($due));
    }

    /** Its first invoice charges the plan's setup fee too, on the same line, as an order's first invoice does (code review 5). */
    private static function plusSetupFee(string $amount, Plan $plan, ?string $currency): string
    {
        return (string) BigDecimal::of($amount)->plus(self::setupFee($plan, $currency))->toScale(2, RoundingMode::HalfUp);
    }

    /** Its plan's setup fee in $currency, per unit, as its first line adds it. */
    private static function setupFee(Plan $plan, ?string $currency): string
    {
        $fee = $plan->prices->firstWhere('currency_code', $currency)->setup_fee ?? null;

        return (string) BigDecimal::of(blank($fee) ? '0' : (string) $fee)->toScale(2, RoundingMode::HalfUp);
    }

    /** Its price per unit for one cycle as the catalog and the account have it (group discount), for the form. */
    public static function catalogPrice(Service $parent, Product $product, Plan $plan): string
    {
        $probe = new Service(['client_id' => $parent->client_id, 'currency_code' => $parent->currency_code, 'product_id' => $product->id,
            'plan_id' => $plan->id, 'quantity' => 1, 'price' => 0]);
        $probe->setRelation('plan', $plan)->setRelation('product', $product)->setRelation('client', $parent->client);

        return $plan->type === 'free' ? '0.00' : (string) $probe->calculatePrice(applyCoupon: false);
    }

    /**
     * Adds it. $price per unit and cycle (null: the catalog's).
     *
     * @return array{service: Service, invoice: Invoice|null}
     */
    public function handle(Service $parent, Product $product, Plan $plan, int $quantity, ?string $price, bool $keepPrice, string $start): array
    {
        if (!in_array($start, [self::PRORATED, self::NO_INVOICE], true)) {
            throw new DisplayException(__('services.addons.add.start_unknown'));
        }
        $result = DB::transaction(function () use ($parent, $product, $plan, $quantity, $price, $keepPrice, $start) {
            // Under the parent's lock, read again: a payment, the cron or a cancellation may have changed it.
            $parent = Service::query()->whereKey($parent->id)->lockForUpdate()->firstOrFail();
            if (($refused = self::refusal($parent)) !== null) {
                throw new DisplayException($refused);
            }
            // Locked: two staff cannot both take the last unit (as an order, PlaceOrderService).
            $product = Product::query()->whereKey($product->id)->with('plans.prices')->lockForUpdate()->firstOrFail();
            if (!$product->is_addon || $product->retired || !$product->appliesTo($parent)) {
                throw new DisplayException(__('services.addons.add.not_for_this', ['addon' => $product->name]));
            }
            $plan = self::plans($product, $parent)->firstWhere('id', $plan->id) ?? throw new DisplayException(__('services.addons.add.plan_unpriced'));
            if ($quantity < 1 || ($quantity > 1 && ($product->allow_quantity ?? 'disabled') === 'disabled')) {
                throw new DisplayException(__('services.addons.add.quantity'));
            }
            // Its stock as an order takes it, given back when it ends (ADV-637-3).
            if ($product->stock !== null) {
                if ((int) $product->stock < $quantity) {
                    throw new DisplayException(__('services.addons.add.stock', ['stock' => (int) $product->stock]));
                }
                $product->decrement('stock', $quantity);
            }
            $catalog = self::catalogPrice($parent, $product, $plan);
            // Cents, rounded half up: a price typed with more decimals is not an error page (code review 4 of #637).
            $unit = $price === null || trim($price) === '' ? $catalog : (string) BigDecimal::of(trim($price))->toScale(2, RoundingMode::HalfUp);
            if (BigDecimal::of($unit)->isNegative()) {
                throw new DisplayException(__('services.addons.add.price_negative'));
            }
            $quote = self::quote($parent, $plan, $unit, $start);
            $noInvoice = $quote === null || $start === self::NO_INVOICE;
            // No invoice, recurring: due when a prorated one would be, so on its parent's day (owner 2026-11-08, REV-KISS-5).
            $due = $noInvoice && $plan->type === 'recurring'
                ? (self::alignedEnd($parent, $plan) ?? throw new DisplayException(__('services.addons.add.no_due_date'))) : null;

            $addon = new Service([
                'client_id' => $parent->client_id, 'currency_code' => $parent->currency_code, 'parent_service_id' => $parent->id,
                'product_id' => $product->id, 'plan_id' => $plan->id, 'quantity' => $quantity, 'price' => $unit,
                'price_locked' => $keepPrice || BigDecimal::of($unit)->compareTo(BigDecimal::of($catalog)) !== 0,
                'payment_gateway_id' => $parent->payment_gateway_id, 'no_auto_charge' => (bool) $parent->no_auto_charge,
                // Started under a suspended parent: suspended with it, back with it (AddonCascade).
                'status' => !$noInvoice ? Service::STATUS_PENDING : ($parent->status === Service::STATUS_SUSPENDED ? Service::STATUS_SUSPENDED : Service::STATUS_ACTIVE),
                'suspend_reason' => $noInvoice && $parent->status === Service::STATUS_SUSPENDED ? Service::SUSPEND_REASON_PARENT : null,
                'expires_at' => $due,
            ]);
            // Prorated to its parent's date: it renews on its parent's day (a 31st stays a 31st through short months).
            // Its effective day, not only a stored one (a 31st with none stored: code review 1 of #637); its payment keeps it
            // (RenewServiceService).
            if ($due !== null || $start === self::PRORATED) {
                $addon->forceFill(['billing_day' => $parent->billingDay(CarbonImmutable::parse($parent->expires_at))]);
            }
            $addon->save();

            if ($noInvoice) {
                $id = $addon->id;
                // Started: its creation is staff's task on the parent's machine, once this commits.
                DB::afterCommit(function () use ($id) {
                    try {
                        ($made = Service::query()->find($id)) !== null ? ExtensionHelper::createServer($made) : null;
                    } catch (Throwable $e) {
                        report($e);
                    }
                });

                return ['service' => $addon, 'invoice' => null];
            }

            [$from, $until, $amount] = $quote;
            $own = ItemPaymentMethod::apart($addon); // as its renewals will be (RenewalBilling::ownGateway; code review 6)
            $invoice = new Invoice(['client_id' => $addon->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => $from,
                'currency_code' => $addon->currency_code] + ($own !== null ? ['preferred_gateway_id' => $own] : []));
            // Finished (numbered, mailed, paid by credit) once its line is on it, as a renewal's (RenewalBilling).
            $invoice->finalize_later = true;
            $invoice->forceFill(['finish_pending_at' => now()]);
            $invoice->save();
            $invoice->items()->create([
                'reference_id' => $addon->id,
                'reference_type' => Service::class,
                'price' => $amount,
                'quantity' => $quantity,
                'description' => $addon->describeLine($from, $until) . RenewalBilling::groupNote($addon->fresh() ?? $addon),
                'period_start' => $from,
                'period_end' => $until,
                'payment_gateway_id' => ItemPaymentMethod::live($addon),
                // Its setup fee, apart from its period's price: never given back as unused days (sweep 2).
                'setup_fee' => BigDecimal::of($fee = self::setupFee($plan, $addon->currency_code))->isPositive() ? $fee : null,
            ]);

            return ['service' => $addon, 'invoice' => $invoice];
        });

        if ($result['invoice'] !== null) {
            (new RenewalBilling)->finishRenewalInvoices([(int) $result['invoice']->id]);
            $result['invoice']->refresh();
        }

        return $result;
    }
}
