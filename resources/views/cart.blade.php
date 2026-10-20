<div class="container mt-14">
    <div class="flex flex-col md:grid md:grid-cols-4 gap-8">
        <div class="flex flex-col col-span-3 gap-4">
            @if (Cart::items()->count() === 0)
            {{-- Not a dead end (shop polish of 2026-10-20): the way back to the products. --}}
            <div class="flex flex-col items-start gap-3 bg-background-secondary border border-neutral p-6 rounded-lg">
                <h1 class="text-2xl font-semibold">{{ __('product.empty_cart') }}</h1>
                <p class="text-base/70">{{ __('product.empty_cart_text') }}</p>
                @php($first = \App\Models\Category::query()->inMenu()->first())
                <a href="{{ $first ? route('category.show', ['category' => $first->slug]) : route('home') }}" wire:navigate>
                    <x-button.primary class="w-fit">{{ __('product.continue_shopping') }}</x-button.primary>
                </a>
            </div>
            @endif
            @foreach (Cart::items() as $item)
            <div class="flex flex-row justify-between w-full bg-background-secondary p-3 rounded-md border border-neutral">
                <div class="flex flex-col gap-1">
                    <h2 class="text-2xl font-semibold">
                        {{ $item->title() }}
                    </h2>
                    @if ($item->isDomain())
                    {{-- A domain (D8b): its years, and a transfer's EPP code. --}}
                    @php($tld = \App\Models\DomainTld::query()->with('prices')->find($item->domain['tld_id']))
                    @php($terms = $tld ? app(\App\Services\Domains\Ordering\DomainOffer::class)->years($tld, $item->domain['operation'], Cart::get()->currency_code) : collect())
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        @if ($terms->count() > 1)
                        <label>{{ __('domains.order.years') }}
                            <select class="bg-background border border-neutral rounded px-2 py-1 ml-1" wire:change="updateDomainYears({{ $item->id }}, $event.target.value)">
                                @foreach ($terms as $y)
                                <option value="{{ $y }}" @selected($y === (int) $item->domain['years'])>{{ trans_choice(':count year|:count years', $y, ['count' => $y]) }}</option>
                                @endforeach
                            </select>
                        </label>
                        @endif
                        @if ($item->domain['operation'] === 'transfer')
                        <label class="flex flex-wrap items-center gap-1 w-full sm:w-auto">{{ __('domains.order.epp_code') }}
                            <input type="text" wire:model.blur="epp.{{ $item->id }}" autocomplete="off" maxlength="255"
                                placeholder="{{ filled($item->epp_code) ? __('domains.order.epp_saved') : '' }}" class="w-full sm:w-72 bg-background border border-neutral rounded px-2 py-1 font-mono" />
                        </label>
                        <p class="text-xs text-base/60 w-full">{{ __('domains.order.epp_help') }}</p>
                        @endif
                    </div>
                    @else
                    @if ($item->plan && filled($item->plan->cycle()))
                    <p class="text-sm text-base/70">{{ __('product.billed', ['cycle' => $item->plan->type === 'recurring' ? __('product.per_cycle', ['cycle' => $item->plan->cycle()]) : $item->plan->cycle()]) }}</p>
                    @endif
                    <p class="text-sm">
                        @foreach ($item->config_options as $option)
                        {{ $option['option_name'] }}: {{ $option['value_name'] }}<br>
                        @endforeach
                    </p>
                    @endif
                </div>
                <div class="flex flex-col justify-between items-end gap-4">
                    <h3 class="text-xl font-semibold p-1">
                        {{ $item->price->format($item->lineTotal()) }} @if ($item->quantity > 1)
                        ({{ __('product.each', ['price' => $item->price]) }})
                        @endif
                    </h3>
                    <div class="flex flex-row gap-2">
                        @if (!$item->isDomain() && $item->product->allow_quantity == 'combined')
                        <div class="flex flex-row gap-1 items-center mr-4">
                            <x-button.secondary
                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity - 1 }})"
                                class="h-full !w-fit">
                                -
                            </x-button.secondary>
                            <x-form.input class="h-10 text-center" disabled divClass="!mt-0 !w-14" value="{{ $item->quantity }}" name="quantity" />
                            <x-button.secondary
                                wire:click="updateQuantity({{ $item->id }}, {{ $item->quantity + 1 }});"
                                class="h-full !w-fit">
                                +
                            </x-button.secondary>
                        </div>
                        @endif
                        @if (!$item->isDomain() && ($item->product->plans->count() > 1 || $item->product->configOptions->isNotEmpty() || !empty($item->checkout_config)))
                        <a href="{{ route('products.checkout', [$item->product->category, $item->product, 'edit' => $item->id]) }}"
                            wire:navigate>
                            <x-button.primary class="h-fit w-fit">
                                {{ __('product.edit') }}
                            </x-button.primary>
                        </a>
                        @endif
                        {{-- A quiet way out of the cart, not the page's loudest button (UI polish of 2026-10-20). --}}
                        <button type="button" wire:click="removeProduct({{ $item->id }})" wire:loading.attr="disabled" wire:target="removeProduct({{ $item->id }})"
                            class="h-fit text-sm text-base/60 hover:text-error underline-offset-2 hover:underline">
                            <x-loading target="removeProduct({{ $item->id }})" />
                            <span wire:loading.remove wire:target="removeProduct({{ $item->id }})">{{ __('product.remove') }}</span>
                        </button>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        <div class="flex flex-col gap-4">
            @if (Cart::items()->count() > 0)
            <div class="flex flex-col gap-2 w-full col-span-1 bg-background-secondary p-3 rounded-md border border-neutral">
                <h2 class="text-2xl font-semibold mb-3">
                    {{ __('product.order_summary') }}
                </h2>
                @if (Cart::items()->contains(fn ($item) => $item->isDomain()) && auth()->check())
                {{-- Who owns the domains (D8b): the primary contact unless another is chosen. --}}
                <label class="text-sm flex flex-col gap-1">{{ __('domains.order.registrant') }}
                    <select wire:model="registrant" class="bg-background border border-neutral rounded px-2 py-1">
                        @foreach ($this->registrantOptions() as $contact)
                        <option value="{{ $contact->id }}">{{ $contact->displayName() }}@if (!$contact->isCompleteFor('domain')) ({{ __('domains.order.incomplete') }})@endif</option>
                        @endforeach
                    </select>
                    <a href="{{ route('account.contacts') }}" class="text-xs underline" wire:navigate>{{ __('domains.order.manage_contacts') }}</a>
                </label>
                @endif
                <div class="font-semibold flex items-end gap-2">
                    @if(!$coupon)
                    {{-- Behind a link: most orders have none (UI polish of 2026-10-20); open when it was refused. --}}
                    <div class="w-full" x-data="{ open: {{ $errors->has('coupon') ? 'true' : 'false' }} }">
                        <button type="button" x-show="!open" x-on:click="open = true" class="text-sm font-normal text-secondary hover:underline">
                            {{ __('product.have_coupon') }}
                        </button>
                        <div x-show="open" x-cloak class="flex items-end gap-2">
                            <x-form.input wire:model="coupon" name="coupon" :label="__('product.coupon')" />
                            <x-button.primary wire:click="applyCoupon" class="h-fit !w-fit mb-0.5" wire:loading.attr="disabled">
                                <x-loading target="applyCoupon" />
                                <div wire:loading.remove wire:target="applyCoupon">
                                    {{ __('product.apply') }}
                                </div>
                            </x-button.primary>
                        </div>
                    </div>
                    @else
                    <div class="flex justify-between items-center w-full">
                        <h4 class="text-center w-full">{{ $coupon->code }}</h4>
                        <x-button.secondary wire:click="removeCoupon" class="h-fit !w-fit">
                            {{ __('product.remove') }}
                        </x-button.secondary>
                    </div>
                    @endif
                </div>
                <div class="font-semibold flex justify-between">
                    <h4>{{ __('invoices.subtotal') }}:</h4> {{ $total->format($total->subtotal) }}
                </div>
                @if ($total->tax > 0)
                <div class="font-semibold flex justify-between">
                    <h4>{{ \App\Classes\Settings::tax()->name }} ({{ \App\Classes\Settings::tax()->rate }}%):</h4> {{ $total->format($total->tax) }}
                </div>
                @endif
                <div class="text-lg font-semibold flex justify-between mt-1">
                    <h4>{{ __('invoices.total') }}:</h4> {{ $total->format($total->total) }}
                </div>

                <div class="flex flex-col gap-2 w-full col-span-1">
                    <x-form.textarea wire:model="notes" name="notes" :label="__('Notes')"
                        :placeholder="__('Anything we should know about this order (optional)')" />
                    @if(config('settings.tos'))
                    <x-form.checkbox wire:model="tos" name="tos">
                        {{ __('product.tos') }}
                        <a href="{{ config('settings.tos') }}" target="_blank" class="text-primary hover:text-primary/80">
                            {{ __('product.tos_link') }}
                        </a>
                    </x-form.checkbox>
                    @endif

                    <div class="flex flex-row justify-end gap-2">
                        <x-button.primary wire:click="checkout" class="h-fit" wire:loading.attr="disabled">
                            <x-loading target="checkout" />
                            <div wire:loading.remove wire:target="checkout">
                                {{ __('product.checkout') }}
                            </div>
                        </x-button.primary>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
