<?php

namespace App\Livewire\Products;

use App\Classes\Cart;
use App\Classes\Price;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Livewire\DomainShop\Search;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Plan;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Lookup\VisitorKey;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Order\OrderPricing;
use App\Support\CurrentClient;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use MyCS\Pricing\LinePricer;

class Checkout extends Component
{
    public $product;

    public Category $category;

    public Plan $plan;

    #[Url(keep: true, as: 'plan')]
    public $plan_id;

    // Don't allow the user to change the total via hacks
    #[Locked]
    public $total;

    /** A pro rata product's first invoice (PR D): what it charges, and up to which day; null otherwise. */
    public ?string $prorataFirst = null;

    public ?string $prorataUntil = null;

    public $setup_fee;

    #[Url(keep: true, as: 'options')]
    public $configOptions = [];

    #[Url(keep: true, as: 'config')]
    public $checkoutConfig = [];

    #[Url(as: 'edit'), Locked]
    public $cartProductKey = null;

    /**
     * The hosting's domain, checked (owner 2026-10-22, as WHMCS asks before the cart): free → register it with the
     * hosting, taken → transfer it here or keep it where it is. Null until «Check».
     *
     * @var array{fqdn: string, status: string, years: int|null, register: string|null, transfer: string|null}|null
     */
    #[Locked]
    public ?array $domainOffer = null;

    /** What to do with it: '' (only the hosting), 'register' or 'transfer'. */
    public string $domainAction = '';

    /** A transfer's EPP code (also asked in the cart). */
    public string $domainEpp = '';

    public function mount($product)
    {
        // A hidden product is ordered by its link; a retired one (hidden, out of stock) is not found.
        $this->product = $this->category->products()->where('slug', $product)->reachable()->firstOrFail();
        if ($this->product->stock === 0 || !$this->product->price()->available) {
            return $this->redirect(route('products.show', ['category' => $this->category, 'product' => $this->product]), true);
        }

        // Is there a existing item in the cart?
        if (Cart::get()->items->where('id', $this->cartProductKey)->isNotEmpty() && Cart::get()->items->where('id', $this->cartProductKey)->first()?->product?->id === $this->product->id) {
            $item = Cart::get()->items->where('id', $this->cartProductKey)->first();
            // Get the item from the cart
            $this->plan = $item->plan;
            $this->plan_id = $this->plan->id;
            $this->configOptions = array_column($item->config_options, 'value', 'option_id');
            // Update config options for checkbox types
            foreach ($this->product->configOptions->where('type', 'checkbox') as $option) {
                $this->configOptions[$option->id] = isset($this->configOptions[$option->id]) ? true : false;
            }
            $this->checkoutConfig = (array) $item->checkout_config;
        } else {
            // Set the first plan as default
            $this->plan = $this->plan_id ? $this->product->plans->findOrFail($this->plan_id) : $this->product->plans->first();
            $this->plan_id = $this->plan->id;

            foreach ($this->getCheckoutConfig() as $config) {
                if (in_array($config['type'], ['select', 'radio'])) {
                    $this->checkoutConfig[$config['name']] = $this->checkoutConfig[$config['name']] ?? $config['default'] ?? array_key_first($config['options']);
                } else {
                    $this->checkoutConfig[$config['name']] = $this->checkoutConfig[$config['name']] ?? $config['default'] ?? null;
                }
            }
        }

        // Prepare the config options. Also done for an item that is already in the cart, because the
        // product may have gained, lost or changed options since it was added.
        $this->configOptions = $this->product->configOptions->mapWithKeys(function ($option) {
            if (in_array($option->type, ['text', 'number'])) {
                return [$option->id => $this->configOptions[$option->id] ?? null];
            }
            if ($option->type === 'checkbox') {
                return [$option->id => isset($this->configOptions[$option->id]) && in_array($this->configOptions[$option->id], [true, 'true'], true) ? true : false];
            }

            $value = $this->configOptions[$option->id] ?? null;

            return [$option->id => $option->children->contains('id', $value) ? $value : $option->children->first()->id];
        })->toArray();

        // Update the pricing
        $this->updatePricing();

        // As there is only one plan, config options and checkout config, we can directly call the checkout method to avoid confusion
        // This is only done when the user is not editing the cart item
        if ($this->product->plans->count() === 1 && empty($this->configOptions) && empty($this->checkoutConfig)) {
            $this->checkout();
        }
    }

    /** What the choice costs, priced as the cart and checkout price it (Σ7, `OrderPricing`). */
    public function updatePricing()
    {
        $currency = session('currency', config('settings.default_currency'));
        $chosen = collect($this->configOptions)->map(fn ($value, $optionId) => ['option_id' => (int) $optionId, 'value' => $value])->values()->all();
        $catalog = OrderPricing::catalog($this->product, $this->plan, $chosen, $currency);
        if ($catalog === null) {
            $this->total = new Price((object) ['price' => null, 'setup_fee' => null, 'currency' => null]);

            return;
        }
        $client = Auth::guard('web')->check() ? CurrentClient::model() : null;
        $line = OrderPricing::line($catalog, null, $client, $this->product);
        // A pro rata product (PR D): the first invoice is for the days up to its day, as the cart and checkout charge it.
        $first = OrderPricing::firstPeriod($this->product, $this->plan, now());
        $this->prorataUntil = $first === null ? null : $first['end']->translatedFormat(__('services.invoice_period_date_format'));
        $this->prorataFirst = $first === null ? null : LinePricer::invoiceLine(OrderPricing::firstPaymentNet($line, $this->product, $this->plan, now()), 1,
            OrderPricing::taxRate($client, $this->product))['total'];
        // A free plan with nothing chosen that costs: «Free», as the plan list says.
        $this->total = $this->plan->type === 'free' && !BigDecimal::of($line->firstPayment())->isPositive()
            ? new Price(['currency' => Currency::query()->find($currency)], free: true)
            : Price::fromLine($line, Currency::query()->find($currency));
    }

    // On change of the plan, update the config options
    public function updatedPlanId($value)
    {
        $this->plan = Plan::findOrFail($value);
        $this->updatePricing();
    }

    // On change of the config options, update the pricing
    public function updatedConfigOptions()
    {
        $this->updatePricing();
    }

    /** Another domain typed: the check was of the one before. */
    public function updatedCheckoutConfig($value, $key)
    {
        if ($key === 'domain' && $this->domainOffer !== null && $this->domainOffer['fqdn'] !== mb_strtolower(trim((string) $value))) {
            $this->reset(['domainOffer', 'domainAction', 'domainEpp']);
        }
    }

    /** Whether this product asks a domain (its server's checkout field) and the shop sells domains. */
    public function asksDomain(): bool
    {
        return config('settings.domain_billing_enabled', true) && (DomainOffer::allowed('register') || DomainOffer::allowed('transfer'))
            && collect($this->getCheckoutConfig())->contains(fn ($config) => ($config['name'] ?? null) === 'domain');
    }

    /** «Check»: is the hosting's domain free (register it with us) or taken (transfer it, or keep it)? */
    public function checkDomain(): void
    {
        $this->reset(['domainOffer', 'domainAction', 'domainEpp']);
        $this->resetErrorBag('checkoutConfig.domain');
        $typed = mb_substr(trim((string) ($this->checkoutConfig['domain'] ?? '')), 0, 255);
        if ($typed === '' || !$this->asksDomain()) {
            return;
        }
        // The search's limit (a check is a search of the visitor's): never a way around it.
        $key = 'domain-search-hits:' . VisitorKey::of(request());
        if (RateLimiter::tooManyAttempts($key, Search::LIMIT)) {
            $this->addError('checkoutConfig.domain', __('domains.shop.too_many'));

            return;
        }
        RateLimiter::hit($key, 600);
        $currency = Search::currency();
        $offer = app(DomainOffer::class);
        $tld = $offer->tldFor($typed, $currency);
        if ($tld === null) {
            return; // an ending we do not sell: the hosting alone, with the domain they have
        }
        try {
            // The name checked as the shop sells it: registered, or (registrations off) transferred, never premium or ours.
            $operation = DomainOffer::allowed('register') ? 'register' : 'transfer';
            [$fqdn, $tld] = $offer->check($typed, $operation, (int) ($offer->years($tld, $operation, $currency)->first() ?? 1), $currency, availability: false);
        } catch (DisplayException) {
            return; // not one we can sell (registrations off, premium, ours already): the hosting alone
        }
        $years = $tld->shortestYears('register', $currency);
        $this->domainOffer = [
            'fqdn' => $fqdn,
            'status' => app(DomainSearch::class)->statuses([$fqdn => $tld])[$fqdn] ?? DomainSearch::UNKNOWN,
            'years' => $years,
            'register' => $years === null || !DomainOffer::allowed('register') ? null : $tld->priceFor('register', $years, $currency),
            'transfer' => DomainOffer::allowed('transfer') && $tld->registrarCan('transfer') ? $tld->priceFor('transfer', 1, $currency) : null,
        ];
        // A free name is registered with the hosting unless unticked; a taken one is kept where it is unless asked.
        $this->domainAction = $this->domainOffer['status'] === DomainSearch::FREE && $this->domainOffer['register'] !== null ? 'register' : '';
    }

    public function getCheckoutConfig()
    {
        return once(fn () => ExtensionHelper::getCheckoutConfig($this->product, $this->checkoutConfig));
    }

    public function rules()
    {
        $availablePlanIds = $this->product->availablePlans()->pluck('id')->toArray();

        $rules = [
            'plan_id' => [
                'required',
                Rule::in($availablePlanIds),
            ],
        ];
        foreach ($this->product->configOptions as $option) {
            if (in_array($option->type, ['text', 'number'])) {
                $rules["configOptions.{$option->id}"] = ['required'];
            } elseif ($option->type === 'checkbox') {
                // No validation needed for checkbox
            } else {
                $rules["configOptions.{$option->id}"] = [
                    'required',
                    Rule::in($option->children->pluck('id')->toArray()),
                ];
            }
        }
        foreach ($this->getCheckoutConfig() as $key => $config) {
            $validationRules = [];
            if ($config['required'] ?? false) {
                $validationRules[] = 'required';
            }
            if (isset($config['type'])) {
                switch ($config['type']) {
                    case 'text':
                    case 'number':
                        $validationRules[] = 'string';
                        break;
                    case 'select':
                    case 'radio':
                        $validationRules[] = 'in:' . implode(',', array_keys($config['options']));
                        break;
                    case 'checkbox':
                        $validationRules[] = 'nullable';
                        $validationRules[] = 'boolean';
                        break;
                }
            }
            if (isset($config['validation'])) {
                if (is_array($config['validation'])) {
                    $validationRules = array_merge($validationRules, $config['validation']);
                } else {
                    // Is validation seperated by |?
                    $validationRules = array_merge($validationRules, explode('|', $config['validation']));
                }
            }
            if (count($validationRules) > 0) {
                $rules["checkoutConfig.{$config['name']}"] = $validationRules;
            }
        }

        return $rules;
    }

    public function attributes()
    {
        $messages = [];
        foreach ($this->product->configOptions as $option) {
            $messages["configOptions.{$option->id}"] = $option->name;
        }
        foreach ($this->getCheckoutConfig() as $key => $config) {
            $messages["checkoutConfig.{$config['name']}"] = $config['label'] ?? $config['name'];
        }

        return $messages;
    }

    public function checkout()
    {
        // Do the checkout
        // First we validate the plans
        $this->validate(attributes: $this->attributes());

        // Has this product quantity = no?
        if ($this->product->allow_quantity == 'disabled') {
            // Check if the product is already in the cart
            $item = Cart::get()->items()->where('product_id', $this->product->id)->when($this->cartProductKey, function ($query) {
                $query->where('id', '!=', $this->cartProductKey);
            })->get();

            if ($item->isNotEmpty()) {
                $this->notify(__('This product is already in your cart and cannot be added again.'), 'error');

                return;
            }
        }

        // Change configOptions so they also contain the name of the option (resulting in less database calls = faster speeds)
        $configOptions = $this->product->configOptions->map(function ($option) {
            if ($option->type == 'checkbox') {
                return (object) [
                    'option_id' => $option->id,
                    'option_name' => $option->name,
                    'option_type' => $option->type,
                    'option_env_variable' => $option->env_variable,
                    'value' => isset($this->configOptions[$option->id]) && in_array($this->configOptions[$option->id], [true, 'true'], true) ? $option->children->first()->id : null,
                    'value_name' => isset($this->configOptions[$option->id]) && in_array($this->configOptions[$option->id], [true, 'true'], true) ? 'Yes' : 'No',
                ];
            }
            if (in_array($option->type, ['text', 'number'])) {
                return (object) [
                    'option_id' => $option->id,
                    'option_name' => $option->name,
                    'option_type' => $option->type,
                    'option_env_variable' => $option->env_variable,
                    'value' => $this->configOptions[$option->id],
                    'value_name' => $this->configOptions[$option->id],
                ];
            }

            return (object) [
                'option_id' => $option->id,
                'option_name' => $option->name,
                'option_type' => $option->type,
                'option_env_variable' => $option->env_variable,
                'value' => $this->configOptions[$option->id],
                'value_name' => $option->children->where('id', $this->configOptions[$option->id])->first()->name,
            ];
        });

        // Ensure checkout config has only the allowed keys and values
        $checkoutConfig = [];
        foreach ($this->getCheckoutConfig() as $config) {
            $checkoutConfig[$config['name']] = $this->checkoutConfig[$config['name']] ?? null;
        }

        // Its domain first: refused (taken meanwhile, the cart full), the hosting waits here with why.
        if (in_array($this->domainAction, ['register', 'transfer'], true) && $this->domainOffer !== null && $this->asksDomain()
            && $this->domainOffer['fqdn'] === mb_strtolower(trim((string) ($checkoutConfig['domain'] ?? '')))) {
            try {
                $id = Cart::addDomain($this->domainOffer['fqdn'], $this->domainAction);
                if ($this->domainAction === 'transfer' && trim($this->domainEpp) !== '' && ($item = CartItem::query()->find($id)) !== null) {
                    $item->epp_code = mb_substr(trim($this->domainEpp), 0, 255);
                    $item->save();
                }
            } catch (DisplayException $e) {
                $this->addError('checkoutConfig.domain', $e->getMessage());

                return;
            }
        }

        Cart::add($this->product, $this->plan, $configOptions, $checkoutConfig, key: $this->cartProductKey);

        $this->dispatch('cartUpdated');

        return $this->redirect(route('cart'), true);
    }

    public function render()
    {
        return view('products.checkout')->layoutData([
            'title' => $this->product->name,
            'image' => $this->product->image ? Storage::url($this->product->image) : null,
        ]);
    }
}
