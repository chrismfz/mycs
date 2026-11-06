<?php

namespace App\Services\ServiceUpgrade;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use MyCS\Pricing\DifferenceDiscount;
use MyCS\Pricing\DifferenceDiscountRefused;
use Throwable;

/**
 * A change of package placed, by the customer (`Livewire\Services\Upgrade`) or by staff (the service's «Upgrade/Downgrade»),
 * priced as `UpgradeQuote` shows it, as WHMCS:
 * - an upgrade: an invoice for the difference over the days left to the expiry; applied once it is paid;
 * - a downgrade (or a change that costs nothing now): applied at once, 0.00 now; the new amount from the next renewal;
 *   credit for the days left only with «Credit on downgrade» and credits on.
 *
 * Under the service's lock: a second change while one is pending is refused.
 *
 * Staff may give a discount on an upgrade's difference (owner 2026-11-06, docs/decisions.md, SVC-14): a percentage, an
 * amount to pay (with tax), or all of it. Its invoice has the upgrade's line at the full difference and a negative
 * discount line under it; waived, there is no invoice and it is applied at once. The renewal price is not touched.
 */
final class PlaceUpgrade
{
    /** It is not upgradable now (a change pending meanwhile). */
    public const NOT_UPGRADABLE = 'not_upgradable';

    /** No plan of the new product for its cycle in its currency. */
    public const INVALID = 'invalid';

    /** The same product and options as it has. */
    public const UNCHANGED = 'unchanged';

    /** The catalog cannot price it in the service's currency (ADV-551-1). */
    public const NEEDS_STAFF = 'needs_staff';

    /** Refused when applied: the service stopped running meanwhile. */
    public const NOT_NOW = 'not_now';

    /** Applied now (a downgrade, or nothing to pay). */
    public const APPLIED = 'applied';

    /** An invoice made; applied when it is paid. */
    public const INVOICED = 'invoiced';

    /** Staff's discount not given: `refusal` is a DifferenceDiscount::REFUSED_* (nothing_due: a downgrade, nothing to pay). */
    public const DISCOUNT_REFUSED = 'discount_refused';

    private function __construct(
        public readonly string $outcome,
        public readonly ?ServiceUpgrade $upgrade = null,
        public readonly ?Invoice $invoice = null,
        /** The credit a downgrade gave, in cents. */
        public readonly int $creditCents = 0,
        /** It lowered the recurring amount (calculatePrice below zero). */
        public readonly bool $downgrade = false,
        /** Staff's discount, as given (null: none). */
        public readonly ?DifferenceDiscount $discount = null,
        /** Why the discount was refused (DISCOUNT_REFUSED). */
        public readonly ?string $refusal = null,
    ) {}

    /**
     * The plan the service moves to: its own when the product stays (an options upgrade does not move it to another plan
     * of the same cycle), else the new product's for its cycle, priced in its currency (SVC-14 review F1: a plan priced
     * only in another currency was taken, and priced as free).
     */
    public static function targetPlan(Service $service, Product $product): ?Plan
    {
        if ((int) $product->id === (int) $service->product_id) {
            return $service->plan;
        }
        if ($service->plan === null) {
            return null;
        }

        return $product->availablePlans($service->currency_code)
            ->where('billing_period', $service->plan->billing_period)
            ->where('billing_unit', $service->plan->billing_unit)
            ->first();
    }

    /**
     * The option values a change to $product sets when nobody chooses them, as the customer's page starts them
     * (`Livewire\Services\Upgrade::nextStep`): the value the service has, else the option's first one; a checkbox only
     * when the service has it. Staff's «Upgrade/Downgrade» charges these, as the customer would be (CR-1 of #638).
     *
     * @return array<int, int> option id => value id
     */
    public static function defaultChoices(Service $service, Product $product): array
    {
        $has = $service->configs->pluck('config_value_id', 'config_option_id');
        $chosen = [];
        foreach ($product->upgradableConfigOptions as $option) {
            if (in_array($option->type, ['text', 'number'], true)) {
                continue;
            }
            if ($option->type === 'checkbox') {
                $value = $has->has($option->id) ? $option->children->first()?->id : null;
            } elseif ($has->has($option->id)) {
                // One of its own values only (AUTH-5), as optionValue takes it.
                $value = $option->children->contains('id', (int) $has->get($option->id)) ? (int) $has->get($option->id) : null;
            } else {
                $value = $option->children->first()?->id;
            }
            if ($value !== null) {
                $chosen[(int) $option->id] = (int) $value;
            }
        }

        return $chosen;
    }

    /**
     * Place the change of $service to $product with the option values it sets ($chosen: option id => value id, of the
     * product's upgradable options). $discountMode (staff only): a DifferenceDiscount mode, with its $discountValue.
     *
     * @param  array<int, int>  $chosen
     */
    public static function handle(Service $service, Product $product, array $chosen, string $source, ?int $staffId = null,
        ?string $discountMode = null, string $discountValue = ''): self
    {
        // The customer's page never gives itself a discount.
        if ($discountMode !== null && $source !== ServiceUpgrade::SOURCE_STAFF) {
            throw new InvalidArgumentException('Only staff give a discount on an upgrade.');
        }
        DB::beginTransaction();
        try {
            $result = self::place($service, $product, $chosen, $source, $staffId, $discountMode, $discountValue);
            if (in_array($result->outcome, [self::APPLIED, self::INVOICED], true)) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return $result;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /** @param  array<int, int>  $chosen */
    private static function place(Service $service, Product $product, array $chosen, string $source, ?int $staffId,
        ?string $discountMode, string $discountValue): self
    {
        $service = Service::where('id', $service->id)->lockForUpdate()->first();

        // Validate upgradable again after locking the service
        if ($service->upgrade()->where('status', ServiceUpgrade::STATUS_PENDING)->count() != 0) {
            return new self(self::NOT_UPGRADABLE);
        }

        $upgradePlan = self::targetPlan($service, $product);
        if ($upgradePlan === null) {
            return new self(self::INVALID);
        }

        // The old config options must be in upgradableConfigOptions
        $serviceConfigOptions = $service->configs->pluck('config_value_id', 'config_option_id')->toArray();
        $configOptions = collect($serviceConfigOptions)->filter(function ($value, $key) use ($serviceConfigOptions, $product) {
            return isset($serviceConfigOptions[$key]) && $product->upgradableConfigOptions->contains('id', $key);
        })->toArray();

        // If the product did not change and the config options are the same, present the user with a message
        if ((int) $product->id === (int) $service->product_id && $chosen == $configOptions) {
            return new self(self::UNCHANGED);
        }

        $upgrade = new ServiceUpgrade([
            'service_id' => $service->id,
            'product_id' => $product->id,
            'plan_id' => $upgradePlan->id,
            'source' => $source,
            'staff_id' => $staffId,
        ]);
        $upgrade->save();

        foreach ($chosen as $option => $value) {
            $upgrade->configs()->create([
                'config_option_id' => $option,
                'config_value_id' => $value,
            ]);
        }
        // A change the catalog cannot price in the service's currency: staff's (ADV-551-1).
        if ($upgrade->needsStaff()) {
            return new self(self::NEEDS_STAFF);
        }

        $price = $upgrade->calculatePrice();

        // Staff's discount, priced now under the lock (the difference shrinks by the day): only on an upgrade, never more
        // than it. One that takes nothing off is no discount.
        $discount = null;
        if ($discountMode !== null) {
            try {
                $discount = UpgradeQuote::discount($upgrade, $discountMode, $discountValue);
            } catch (DifferenceDiscountRefused $e) {
                return new self(self::DISCOUNT_REFUSED, refusal: $e->reason);
            }
            if ($discount->none()) {
                $discount = null;
            } else {
                $upgrade->forceFill(['discount' => $discount->discountNet, 'discount_percent' => $discount->percent,
                    'discount_base' => $discount->fullNet])->save();
            }
        }

        // Nothing to charge: a downgrade, the same price, or an upgrade staff waived (no credit for that one: it gives
        // nothing back, it only charges nothing).
        if ($price->price <= 0 || $discount?->waived()) {
            // The credit as priced, before the service changes (UpgradeQuote::credit, what the page showed).
            $credit = $discount !== null ? '0.00' : UpgradeQuote::credit($upgrade, CarbonImmutable::now());
            $until = $service->expires_at;
            // Refused when the service stopped running meanwhile: no credit for a downgrade that did not happen.
            if (!(new ServiceUpgradeService)->handle($upgrade)) {
                return new self(self::NOT_NOW);
            }

            // The difference of a downgrade becomes account credit, through the ledger (docs/money-trail.md §2),
            // with its tax: the unused period was paid with tax, and credit pays invoices with tax (Δ1).
            $cents = RecordPaymentService::toCents($credit);
            if ($cents > 0) {
                (new CreditLedger)->add((int) $service->client_id, $price->currency->code, $cents, MoneyTrail::CAUSE_DOWNGRADE,
                    $source === ServiceUpgrade::SOURCE_STAFF ? MoneyTrail::SOURCE_STAFF : MoneyTrail::SOURCE_SYSTEM);
                // Kept with the downgrade: a later one in the same days gives back only what is left (F2 of #326).
                $upgrade->forceFill(['credit_cents' => $cents, 'credit_until' => $until])->save();
            }

            return new self(self::APPLIED, $upgrade, creditCents: $cents, downgrade: $price->price < 0, discount: $discount);
        }

        $invoice = new Invoice([
            'currency_code' => $service->currency_code,
            'status' => Invoice::STATUS_UNPAID,
            'due_at' => ServiceUpgrade::invoiceDueAt($service),
            'client_id' => $service->client_id,
        ]);
        $invoice->save();

        $upgrade->invoice_id = $invoice->id;
        $upgrade->save();

        $invoice->items()->create([
            'description' => 'Upgrade ' . $service->product->name . ' to ' . $product->name,
            'price' => $price->price,
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        if ($discount !== null) {
            // Under it, in the customer's language (as their PDF): the same reference, so its tax is the upgrade's line's
            // (LineTax) and it goes wherever that line goes.
            $invoice->items()->create([
                'description' => $discount->percent === null
                    ? __('invoices.upgrade_discount', [], NotificationHelper::localeFor($service->client))
                    : __('invoices.upgrade_discount_percent', ['percent' => $discount->percent], NotificationHelper::localeFor($service->client)),
                'price' => '-' . $discount->discountNet,
                'quantity' => 1,
                'kind' => InvoiceItem::KIND_DISCOUNT,
                'reference_id' => $upgrade->id,
                'reference_type' => ServiceUpgrade::class,
            ]);
            // The invoice charges what staff were shown, to the cent, or nothing is made.
            $total = (int) $invoice->items()->get()->sum(fn (InvoiceItem $line) => $line->totalCents());
            if ($total !== RecordPaymentService::toCents($discount->dueTotal)) {
                throw new LogicException("Upgrade #{$upgrade->id}: its invoice totals {$total} cents, the discount priced {$discount->dueTotal}.");
            }
        }

        return new self(self::INVOICED, $upgrade, $invoice, discount: $discount);
    }
}
