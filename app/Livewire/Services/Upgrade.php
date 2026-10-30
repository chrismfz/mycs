<?php

namespace App\Livewire\Services;

use App\Classes\Price;
use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Order\OrderPricing;
use App\Services\ServiceUpgrade\DowngradeCredit;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use MyCS\Pricing\LinePricer;

class Upgrade extends Component
{
    public Service $service;

    public $upgrade;

    public Product $upgradeProduct;

    public int $step = 1;

    public $configOptions = [];

    public function mount()
    {
        $this->authorize('update', $this->service); // `manageproducts` (docs/accounts.md, PR 2b)

        if (!$this->service->upgradable) {
            $this->notify(__('This service is not upgradable.'), 'error', true);

            return $this->redirect(route('services.show', $this->service), true);
        }
        $this->upgradeProduct = $this->service->product;
        $this->upgrade = $this->service->product->id;
        $this->totalToday();

        // We only have upgradable config options if the product has any
        if ($this->service->productUpgrades()->count() === 0) {
            $this->nextStep();
        }
    }

    #[Computed]
    public function totalToday()
    {
        // With tax: what the invoice it makes charges (Δ1).
        return $this->priced()->grossPrice();
    }

    /**
     * What it renews at after the change, with tax, and what it renews at now: shown before the customer confirms, as
     * the new price can be more than it had (an options-only change priced from the catalog: #551's code review).
     *
     * @return array{now: Price, after: Price}
     */
    #[Computed]
    public function renewal(): array
    {
        $upgrade = $this->priced();
        $service = $this->service;
        $quantity = max(1, (int) $service->quantity);
        $price = fn (string $net, Product $product) => new Price(['price' => (float) LinePricer::invoiceLine($net, $quantity,
            OrderPricing::taxRate($service->client, $product))['total'], 'currency' => $service->currency]);

        return ['now' => $price(number_format((float) $service->price, 2, '.', ''), $service->product),
            'after' => $price($upgrade->newRecurringPrice(), $this->upgradeProduct)];
    }

    /** The upgrade as chosen on the page, not saved: what it charges and renews at. */
    private function priced(): ServiceUpgrade
    {
        $upgrade = new ServiceUpgrade(['service_id' => $this->service->id, 'product_id' => $this->upgradeProduct->id]);
        $upgrade->setRelation('service', $this->service);
        $upgrade->setRelation('product', $this->upgradeProduct);

        // Initialize empty configs collection
        $configs = collect();

        // The values the charge will take (optionValue): what is shown is what is charged (R2 of #86).
        foreach ($this->upgradableOptions() as $option) {
            $value = $this->optionValue($option);
            if ($value === null) {
                continue;
            }

            $configs->push(new ServiceConfig([
                'config_option_id' => $option->id,
                'config_value_id' => $value,
            ]));
        }

        $upgrade->setRelation('configs', $configs);

        return $upgrade;
    }

    // When upgrade changes, update the upgradeProduct
    public function updatedUpgrade($upgrade)
    {
        // Check if the upgrade is valid
        if (!$this->service->productUpgrades()->contains($upgrade) && $upgrade != $this->service->product_id) {
            $this->notify(__('Invalid upgrade.'), 'error');

            return;
        }
        $this->upgradeProduct = Product::findOrFail($upgrade);
    }

    public function nextStep()
    {
        $currentConfigOptions = $this->service->configs->pluck('config_value_id', 'config_option_id')->toArray();
        $this->configOptions = collect($this->upgradableOptions())->mapWithKeys(function ($option) use ($currentConfigOptions) {
            // A checkbox starts as the service has it (N2 of #86: it started on, and was charged unless unticked).
            if ($option->type === 'checkbox') {
                return [$option->id => isset($currentConfigOptions[$option->id])];
            }

            return [
                $option->id => $currentConfigOptions[$option->id]
                    ?? $this->configOptions[$option->id]
                    ?? $option->children->first()?->id,
            ];
        })->toArray();

        $this->step++;
    }

    public function rules()
    {
        $rules = [
            'upgradeProduct.id' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (!$this->targetPlan($this->service)) {
                        $fail(__('Invalid upgrade.'));
                    }
                },
            ],

        ];
        foreach ($this->upgradableOptions() as $option) {
            if (in_array($option->type, ['text', 'number'])) {
                $rules["configOptions.{$option->id}"] = ['required'];
            } elseif ($option->type === 'checkbox') {
                // On or off; the value it gives is its own (AUTH-5: any option's id was taken, a foreign product's too).
                $rules["configOptions.{$option->id}"] = ['nullable', Rule::in([true, false, 1, 0, '1', '0', 'true', 'false', ...$option->children->pluck('id')->all()])];
            } else {
                $rules["configOptions.{$option->id}"] = [
                    'required',
                    Rule::in($option->children->pluck('id')->toArray()),
                ];
            }
        }

        return $rules;
    }

    public function doUpgrade()
    {
        $this->authorize('update', $this->service);
        if (!$this->service->upgradable) {
            $this->notify(__('This service is not upgradable.'), 'error', true);

            return $this->redirect(route('services.show', $this->service), true);
        }

        $this->validate();

        // An option the service has is not taken off here (R1 of #86): an upgrade only sets values, so the option would
        // stay, and stay billed, while the customer is told it is done. Staff remove it (and decide on credit).
        $has = $this->service->configs->pluck('config_value_id', 'config_option_id');
        foreach ($this->upgradableOptions() as $option) {
            if ($option->type === 'checkbox' && $has->has($option->id) && $this->optionValue($option) === null) {
                $this->notify(__('An option you have cannot be removed here: please contact us.'), 'error');

                return;
            }
        }

        DB::beginTransaction();
        try {
            $service = Service::where('id', $this->service->id)->lockForUpdate()->first();

            // Validate upgradable again after locking the service
            if ($service->upgrade()->where('status', ServiceUpgrade::STATUS_PENDING)->count() != 0) {
                DB::rollBack();
                $this->notify(__('This service is not upgradable.'), 'error', true);

                return $this->redirect(route('services.show', $this->service), true);
            }

            $upgradePlan = $this->targetPlan($service);
            if ($upgradePlan === null) {
                DB::rollBack();
                $this->notify(__('Invalid upgrade.'), 'error');

                return;
            }

            // The old config options must be in upgradableConfigOptions
            $serviceConfigOptions = $service->configs->pluck('config_value_id', 'config_option_id')->toArray();
            $configOptions = collect($serviceConfigOptions)->filter(function ($value, $key) use ($serviceConfigOptions) {
                return isset($serviceConfigOptions[$key]) && $this->upgradeProduct->upgradableConfigOptions->contains('id', $key);
            })->toArray();

            // If the product did not change and the config options are the same, present the user with a message
            $chosen = collect($this->upgradableOptions())->mapWithKeys(fn ($option) => [$option->id => $this->optionValue($option)])
                ->filter(fn ($value) => $value !== null)->toArray();
            if ($this->upgradeProduct->id === $service->product_id && $chosen == $configOptions) {
                // No changes made, clean rollback and exit safely
                DB::rollBack();
                $this->notify(__('You have not changed anything. Please select a different product or change the configuration options.'), 'error');

                return;
            }

            $upgrade = new ServiceUpgrade([
                'service_id' => $service->id,
                'product_id' => $this->upgradeProduct->id,
                'plan_id' => $upgradePlan->id,
            ]);
            $upgrade->save();

            if ($this->configOptions) {
                foreach ($this->upgradableOptions() as $option) {
                    $value = $this->optionValue($option);
                    if ($value === null) {
                        continue;
                    }
                    $upgrade->configs()->create([
                        'config_option_id' => $option->id,
                        'config_value_id' => $value,
                    ]);
                }
            }
            // A change the catalog cannot price in the service's currency: staff's (ADV-551-1).
            if ($upgrade->needsStaff()) {
                DB::rollBack();
                $this->notify(__('This change needs our staff: please contact us.'), 'error');

                return;
            }

            $price = $upgrade->calculatePrice();

            if ($price->price <= 0) {
                // The credit as priced, before the service changes.
                $gross = $upgrade->grossPrice();
                // Never more than what is left of what was paid, less what earlier downgrades gave back (DowngradeCredit),
                // read before the change: a first-cycle coupon or an offer did not pay the renewal price (P-1, #326).
                // From what was paid, less what the days left cost on the new plan (the customer keeps them), read
                // before the change (DowngradeCredit: P-1, #326).
                $credit = DowngradeCredit::credit($service, CarbonImmutable::now(),
                    BigDecimal::of(number_format(abs((float) $gross->price), 2, '.', '')), BigDecimal::of($upgrade->newPriceForDaysLeft()));
                $until = $service->expires_at;
                // Refused when the service stopped running meanwhile: no credit for a downgrade that did not happen.
                if (!(new ServiceUpgradeService)->handle($upgrade)) {
                    DB::rollBack();
                    $this->notify(__('This service cannot be upgraded right now.'), 'error');

                    return;
                }

                // Credit only where credits are on (AUTH-5): «Credits Enabled» off means no account credit at all.
                if (!config('settings.credits_on_downgrade', true) || !config('settings.credits_enabled', false)) {
                    DB::commit();
                    $this->notify(__('The upgrade has been completed.'), 'success', true);

                    return $this->redirect(route('services.show', $service), true);
                }

                // The difference of a downgrade becomes account credit, through the ledger (docs/money-trail.md §2),
                // with its tax: the unused period was paid with tax, and credit pays invoices with tax (Δ1).
                $cents = RecordPaymentService::toCents((string) $credit);
                if ($cents > 0) {
                    (new CreditLedger)->add((int) $service->client_id, $price->currency->code, $cents, MoneyTrail::CAUSE_DOWNGRADE, MoneyTrail::SOURCE_SYSTEM);
                    // Kept with the downgrade: a later one in the same days gives back only what is left (F2 of #326).
                    $upgrade->forceFill(['credit_cents' => $cents, 'credit_until' => $until])->save();
                }

                DB::commit();

                if ($price->price < 0) {
                    $this->notify(__('The upgrade has been completed. We\'ve added the remaining amount to your account balance.'), 'success', true);
                } else {
                    $this->notify(__('The upgrade has been completed.'), 'success', true);
                }

                return $this->redirect(route('services.show', $service), true);
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
                'description' => 'Upgrade ' . $this->service->product->name . ' to ' . $this->upgradeProduct->name,
                'price' => $price->price,
                'quantity' => 1,
                'reference_id' => $upgrade->id,
                'reference_type' => ServiceUpgrade::class,
            ]);

            DB::commit();

            $this->notify(__('The upgrade has been added to your cart. Please complete the payment to proceed.'), 'success', true);

            return $this->redirect(route('invoices.show', $invoice));

        } catch (\Exception $e) {
            // Rollback the transaction safely on failure
            DB::rollBack();

            if ($e instanceof DisplayException) {
                return $this->notify($e->getMessage(), 'error');
            } else {
                report($e);
                $this->notify(__('An error occurred while processing your order. Please try again later.'));
            }
        }
    }

    public function render()
    {
        return view('services.upgrade')->layoutData([
            'title' => __('navigation.upgrade'),
            'sidebar' => true,
        ]);
    }

    /**
     * The plan the service moves to: its own when the product stays (an options upgrade does not move it to another plan
     * of the same cycle), else the new product's for its cycle, priced in its currency (SVC-14 review F1: a plan priced
     * only in another currency was taken, and priced as free).
     */
    private function targetPlan(Service $service): ?Plan
    {
        if ((int) $this->upgradeProduct->id === (int) $service->product_id) {
            return $service->plan;
        }

        return $this->upgradeProduct->availablePlans($service->currency_code)
            ->where('billing_period', $service->plan->billing_period)
            ->where('billing_unit', $service->plan->billing_unit)
            ->first();
    }

    /**
     * The options the upgrade offers: the product's upgradable ones that have values. Text and number options have none
     * (their text is a property of the service, set at order): left out (R3 of #86: they broke the page).
     */
    private function upgradableOptions(): iterable
    {
        return $this->upgradeProduct->upgradableConfigOptions->reject(fn ($option) => in_array($option->type, ['text', 'number'], true));
    }

    /**
     * The value an option of the upgrade gets (AUTH-5): one of its own children only. A checkbox on is its value, off is
     * none; text and number options have no value (their text is a property of the service, set at order).
     */
    private function optionValue(ConfigOption $option): ?int
    {
        $given = $this->configOptions[$option->id] ?? null;
        if (in_array($option->type, ['text', 'number'], true) || $given === null || $given === '') {
            return null;
        }
        if ($option->type === 'checkbox') {
            return in_array($given, [true, 1, '1', 'true'], true) || $option->children->contains('id', (int) $given)
                ? $option->children->first()?->id
                : null;
        }

        return $option->children->contains('id', (int) $given) ? (int) $given : null;
    }
}
