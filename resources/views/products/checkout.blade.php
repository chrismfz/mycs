<div class="container mt-14 flex flex-col md:grid md:grid-cols-4 gap-6">
    <div class="flex flex-col gap-4 w-full col-span-3">
        <h1 class="text-3xl font-bold">{{ $product->name }}</h1>
        <div class="flex flex-row w-full gap-4">
            @if ($product->image)
                <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="max-w-40">
            @endif
            <div class="max-h-28 overflow-y-auto w-full">
                <article class="prose dark:prose-invert prose-sm">
                    {!! $product->description !!}
                </article>
            </div>
        </div>
        @if ($product->availablePlans()->count() > 1)
            <x-form.select wire:model.live="plan_id" name="plan_id" :label="__('product.select_plan')">
                @foreach ($product->availablePlans() as $availablePlan)
                    <option value="{{ $availablePlan->id }}">
                        {{-- Its cycle in the customer's language (WHMCS's plans are named «Annually», …). --}}
                        {{ $availablePlan->label() }} -
                        {{ $availablePlan->price()->formatted->price }}
                        @if ($availablePlan->price()->has_setup_fee)
                            + {{ $availablePlan->price()->formatted->setup_fee }} {{ __('product.setup_fee') }}
                        @endif
                    </option>
                @endforeach
            </x-form.select>
        @endif

        @foreach ($product->configOptions as $configOption)
            @php
                $showPriceTag = $configOption->children->filter(fn ($value) => !$value->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->is_free)->count() > 0;
            @endphp
            <x-form.configoption :config="$configOption" :name="'configOptions.' . $configOption->id" :showPriceTag="$showPriceTag" :plan="$plan">
                @if ($configOption->type == 'select')
                    @foreach ($configOption->children as $configOptionValue)
                        <option value="{{ $configOptionValue->id }}">
                            {{ $configOptionValue->name }}
                            {{ ($showPriceTag && $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->available) ? ' - ' . $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit) : '' }}
                        </option>
                    @endforeach
                @elseif($configOption->type == 'radio')
                    @foreach ($configOption->children as $configOptionValue)
                        <div class="flex items-center gap-2">
                            <input type="radio" id="{{ $configOptionValue->id }}" name="{{ $configOption->id }}"
                                wire:model.live="configOptions.{{ $configOption->id }}"
                                value="{{ $configOptionValue->id }}" />
                            <label for="{{ $configOptionValue->id }}">
                                {{ $configOptionValue->name }}
                                {{ ($showPriceTag && $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit)->available) ? ' - ' . $configOptionValue->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit) : '' }}
                            </label>
                        </div>
                    @endforeach
                @endif
            </x-form.configoption>
        @endforeach
        @foreach ($this->getCheckoutConfig() as $configOption)
            @php $configOption = (object) $configOption; @endphp
            <x-form.configoption :config="$configOption" :name="'checkoutConfig.' . $configOption->name">
                @if ($configOption->type == 'select')
                    @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                        <option value="{{ $configOptionValue }}">
                            {{ $configOptionValueName }}
                        </option>
                    @endforeach
                @elseif($configOption->type == 'radio')
                    @foreach ($configOption->options as $configOptionValue => $configOptionValueName)
                        <div class="flex items-center gap-2">
                            <input type="radio" id="{{ $configOptionValue }}" name="{{ $configOption->name }}"
                                wire:model.live="checkoutConfig.{{ $configOption->name }}"
                                value="{{ $configOptionValue }}" />
                            <label for="{{ $configOptionValue }}">
                                {{ $configOptionValueName }}
                            </label>
                        </div>
                    @endforeach
                @endif
            </x-form.configoption>
        @endforeach

        {{-- The hosting's domain (owner 2026-10-22, as WHMCS asks before the cart): free → register it with the hosting;
             taken → transfer it here, or keep it where it is and point it to us. --}}
        @if ($this->asksDomain())
        @php
            $domainCurrency = \App\Models\Currency::query()->find($domainOffer['currency'] ?? \App\Livewire\DomainShop\Search::currency());
            $domainPrice = fn (?string $amount, ?int $years = 1) => $amount === null || $domainCurrency === null ? ''
                : (($years ?? 1) > 1 ? trans_choice('domains.shop.per_years', $years, ['price' => (new \App\Classes\Price(['price' => $amount, 'currency' => $domainCurrency]))->formatted->price, 'years' => $years])
                    : __('domains.shop.per_year', ['price' => (new \App\Classes\Price(['price' => $amount, 'currency' => $domainCurrency]))->formatted->price]));
        @endphp
        <div class="flex flex-col gap-3 rounded-md border border-neutral p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-base/70">{{ __('domains.checkout.lead') }}</p>
                <x-button.secondary type="button" wire:click="checkDomain" wire:loading.attr="disabled" wire:target="checkDomain" class="w-fit!">
                    {{ __('domains.transfer.check') }}
                </x-button.secondary>
            </div>
            @if ($domainOffer)
                @php
                    $free = $domainOffer['status'] === \App\Services\Domains\DomainSearch::FREE;
                    $taken = $domainOffer['status'] === \App\Services\Domains\DomainSearch::TAKEN;
                @endphp
                @if ($free && $domainOffer['register'] !== null)
                    <p class="text-sm font-semibold text-success">{{ __('domains.checkout.free', ['domain' => $domainOffer['fqdn']]) }}</p>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="radio" wire:model.live="domainAction" value="register" class="mt-1">
                        <span>{{ __('domains.checkout.register', ['domain' => $domainOffer['fqdn']]) }} <strong>{{ $domainPrice($domainOffer['register'], $domainOffer['years']) }}</strong></span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="radio" wire:model.live="domainAction" value="" class="mt-1">
                        <span>{{ __('domains.checkout.hosting_only') }}</span>
                    </label>
                @elseif ($taken && $domainOffer['transfer'] !== null)
                    <p class="text-sm">{{ __('domains.checkout.taken', ['domain' => $domainOffer['fqdn']]) }}</p>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="radio" wire:model.live="domainAction" value="" class="mt-1">
                        <span>{{ __('domains.checkout.keep') }}</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="radio" wire:model.live="domainAction" value="transfer" class="mt-1">
                        <span>{{ __('domains.checkout.transfer', ['domain' => $domainOffer['fqdn']]) }} <strong>{{ $domainPrice($domainOffer['transfer']) }}</strong></span>
                    </label>
                    @if ($domainAction === 'transfer')
                        <x-form.input name="domainEpp" wire:model="domainEpp" :label="__('domains.checkout.epp')" :placeholder="__('domains.transfer.epp_placeholder')" />
                    @endif
                @elseif ($taken)
                    <p class="text-sm">{{ __('domains.checkout.taken_keep', ['domain' => $domainOffer['fqdn']]) }}</p>
                @else
                    <p class="text-sm text-base/70">{{ __('domains.checkout.unknown') }}</p>
                @endif
            @endif
        </div>
        @endif
    </div>
    <div class="flex flex-col gap-2 w-full col-span-1 bg-background-secondary p-3 rounded-md h-fit">
        <h2 class="text-2xl font-semibold  mb-2">
            {{ __('product.order_summary') }}
        </h2>
        @if ($total->total_tax > 0)
            <div class="font-semibold flex justify-between">
                <h4>{{ __('invoices.subtotal') }}:</h4> {{ $total->format($total->subtotal) }}
            </div>
            <div class="font-semibold flex justify-between">
                <h4>{{ \App\Classes\Settings::tax()->name }} ({{ \App\Classes\Settings::tax()->rate }}%):</h4> {{ $total->formatted->total_tax }}
            </div>
        @endif
        <div class="text-lg font-semibold flex justify-between">
            <h4>{{ __('product.total_today') }}:</h4> {{ $total }}
        </div>
        @if ($prorataUntil !== null)
            <p class="text-sm text-base/70 mt-1">
                {{ __('product.prorata_first_invoice', ['amount' => $total->format($prorataFirst), 'date' => $prorataUntil]) }}
            </p>
        @endif
        @if ($total->setup_fee > 0 && $plan->type == 'recurring')
            <div class="text- font-semibold flex justify-between ">
                <h4>{{ __('product.then_after_x', ['time' => $plan->billing_period . ' ' . trans_choice(__('services.billing_cycles.' . $plan->billing_unit), $plan->billing_period)]) }}:
                </h4> {{ $total->format($total->price) }}
            </div>
        @endif
        @if (($product->stock > 0 || !$product->stock) && $product->price()->available)
            <div>
                <x-button.primary wire:click="checkout" wire:loading.attr="disabled">
                    <x-loading target="checkout" />
                    <div wire:loading.remove wire:target="checkout">
                        {{ __('product.checkout') }}
                    </div>
                </x-button.primary>
            </div>
        @endif
    </div>
</div>
