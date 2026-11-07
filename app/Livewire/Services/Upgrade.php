<?php

namespace App\Livewire\Services;

use App\Classes\Price;
use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Services\Order\OrderPricing;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use App\Services\ServiceUpgrade\UpgradeQuote;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use MyCS\Pricing\LinePricer;

/**
 * @property-read UpgradeQuote $quote
 * @property-read Price $totalToday
 * @property-read Price $credit
 */
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

    /**
     * Due today, with tax: what the invoice it makes charges (Δ1). A downgrade is 0.00 (as WHMCS): what it gives back, if
     * anything, is the quote's credit.
     */
    #[Computed]
    public function totalToday(): Price
    {
        return new Price(['price' => (float) $this->quote->dueNow, 'currency' => $this->service->currency]);
    }

    /** The days left of the period, what is due or credited now, as staff see it (UpgradeQuote). */
    #[Computed]
    public function quote(): UpgradeQuote
    {
        return UpgradeQuote::for($this->priced());
    }

    /** What a downgrade gives back as credit now, with tax (0.00 where the settings give none). */
    #[Computed]
    public function credit(): Price
    {
        return new Price(['price' => (float) $this->quote->credit, 'currency' => $this->service->currency]);
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
                    ?? $this->choosableValues($option)->first()?->id,
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
                    Rule::in($this->choosableValues($option)->pluck('id')->toArray()),
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

        $chosen = collect($this->upgradableOptions())->mapWithKeys(fn ($option) => [$option->id => $this->optionValue($option)])
            ->filter(fn ($value) => $value !== null)->toArray();
        try {
            $placed = PlaceUpgrade::handle($this->service, $this->upgradeProduct, $chosen, ServiceUpgrade::SOURCE_CLIENT);
        } catch (\Exception $e) {
            if ($e instanceof DisplayException) {
                return $this->notify($e->getMessage(), 'error');
            }
            report($e);

            return $this->notify(__('An error occurred while processing your order. Please try again later.'));
        }

        switch ($placed->outcome) {
            case PlaceUpgrade::NOT_UPGRADABLE:
                $this->notify(__('This service is not upgradable.'), 'error', true);

                return $this->redirect(route('services.show', $this->service), true);
            case PlaceUpgrade::INVALID:
                return $this->notify(__('Invalid upgrade.'), 'error');
            case PlaceUpgrade::UNCHANGED:
                return $this->notify(__('You have not changed anything. Please select a different product or change the configuration options.'), 'error');
            case PlaceUpgrade::NEEDS_STAFF:
                return $this->notify(__('This change needs our staff: please contact us.'), 'error');
            case PlaceUpgrade::NOT_NOW:
                return $this->notify(__('This service cannot be upgraded right now.'), 'error');
            case PlaceUpgrade::APPLIED:
                $this->notify($placed->creditCents > 0
                    ? __('The upgrade has been completed. We\'ve added the remaining amount to your account balance.')
                    : __('The upgrade has been completed.'), 'success', true);

                return $this->redirect(route('services.show', $this->service), true);
        }

        $this->notify(__('The upgrade has been added to your cart. Please complete the payment to proceed.'), 'success', true);

        return $this->redirect(route('invoices.show', $placed->invoice));
    }

    public function render()
    {
        return view('services.upgrade')->layoutData([
            'title' => __('navigation.upgrade'),
            'sidebar' => true,
        ]);
    }

    /** The plan the service moves to (PlaceUpgrade::targetPlan). */
    private function targetPlan(Service $service): ?Plan
    {
        return PlaceUpgrade::targetPlan($service, $this->upgradeProduct);
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

        return $this->choosableValues($option)->contains('id', (int) $given) ? (int) $given : null;
    }

    /**
     * The values an upgrade may move an option to: those a new order may choose, and the one the service has (a hidden
     * value, WHMCS's «Hide», is kept but never newly taken: CLIENT-PORTAL-4).
     *
     * @return Collection<int, ConfigOption>
     */
    public function choosableValues(ConfigOption $option): Collection
    {
        $held = (int) $this->service->configs->firstWhere('config_option_id', $option->id)?->config_value_id;

        return $option->children->filter(fn (ConfigOption $value) => !$value->getAttribute('hidden') || (int) $value->id === $held)->values()->toBase();
    }
}
