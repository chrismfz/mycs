<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2">
        @if ($this->setup)
        {{-- A gateway's own form to save a method (a card's «Add», or the chooser below). --}}
        <x-modal :title="__('account.setup_payment_method')" open>
            <div class="mt-8">
                {{ $this->setup }}
            </div>
            <x-slot name="closeTrigger">
                <div class="flex gap-4">
                    <button wire:confirm="{{ __('account.cancel_setup_confirm') }}" wire:click="cancelSetup" wire:loading.attr="disabled"
                        wire:target="cancelSetup" class="text-primary-100" aria-label="{{ __('account.cancel') }}">
                        <x-ri-close-fill class="size-6" />
                    </button>
                </div>
            </x-slot>
        </x-modal>
        @elseif($setupModalVisible)
        {{-- «?setup=1»: with several gateways that save methods, which one. --}}
        <x-modal :title="__('account.payment_methods')" open="true">
            <x-slot name="closeTrigger">
                <div class="flex gap-4">
                    <button wire:click="$set('setupModalVisible', false)" class="text-primary-100" aria-label="{{ __('account.cancel') }}">
                        <x-ri-close-fill class="size-6" />
                    </button>
                </div>
            </x-slot>
            @if(count($this->gateways) > 1)
            <x-form.select name="gateway" :label="__('account.input.payment_gateway')" wire:model.live="gateway"
                required>
                @foreach($this->gateways as $gateway)
                <option value="{{ $gateway->id }}">{{ $gateway->name }}</option>
                @endforeach
            </x-form.select>
            @elseif(count($this->gateways) === 0)
            <p class="text-sm text-red-500">{{ __('account.no_payment_gateways_available') }}</p>
            @endif
            <x-button.primary class="w-full mt-4" wire:click="createBillingAgreement" wire:loading.attr="disabled">
                <x-loading target="createBillingAgreement" />
                <div wire:loading.remove wire:target="createBillingAgreement">
                    {{ __('account.setup_payment_method') }}
                </div>
            </x-button.primary>
        </x-modal>
        @endif

        <div class="mb-4">
            <h3 class="text-2xl font-bold">{{ __('account.saved_payment_methods') }}</h3>
            <p class="text-sm text-base/70">{{ __('account.saved_payment_methods_description') }}</p>
        </div>

        @if($billingAgreements->count() > 0)
        {{-- The account's switch (PR 1's words): on or off, and what it does. --}}
        <div class="bg-background-secondary border border-neutral rounded-lg p-4 mb-6">
            <x-form.toggle :label="__('account.auto_charge') . ' · ' . ($autoCharge ? __('account.methods_cards.on') : __('account.methods_cards.off'))" wire:model.live="autoCharge" />
            <p class="text-sm text-base/70 mt-2">{{ __('account.auto_charge_description') }}</p>
        </div>
        @endif

        {{--
            One card per provider (docs/pay-flow-ux.md, PR 6a), as the invoice groups them: its saved methods, a radio for the
            account's automatic payments (makeDefault, with its guards), «Add» where the gateway saves methods, and a line
            where it saves none (paid from the invoice).
        --}}
        @if ($providers !== [])
        <fieldset class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
            <legend class="sr-only">{{ __('account.methods_cards.automatic_legend') }}</legend>
            @foreach ($providers as $card)
            @php($gateway = $card['gateway'])
            <section class="bg-background border border-neutral rounded-lg overflow-hidden" wire:key="provider-{{ $gateway?->id ?? 'other' }}"
                aria-labelledby="provider-{{ $gateway?->id ?? 'other' }}">
                <div class="flex items-center gap-3 px-4 py-3 bg-background-secondary border-b border-neutral">
                    @if ($gateway?->meta?->icon)
                    <img src="{{ $gateway->meta->icon }}" alt="" class="size-8 object-contain shrink-0" />
                    @else
                    <x-ri-secure-payment-line class="size-6 text-primary shrink-0" aria-hidden="true" />
                    @endif
                    <h2 id="provider-{{ $gateway?->id ?? 'other' }}" class="text-base font-semibold">{{ $gateway?->name ?? __('account.methods_cards.other') }}</h2>
                </div>

                @foreach ($card['saved'] as $agreement)
                @php($usable = (bool) $agreement->gateway?->enabled)
                @php($marked = (int) $defaultAgreementId === (int) $agreement->id)
                <div class="px-4 py-3 border-b border-neutral last:border-b-0 space-y-2 {{ $marked && $usable ? 'bg-primary/10 shadow-[inset_3px_0_0_hsl(var(--color-primary))]' : '' }}" wire:key="method-{{ $agreement->ulid }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            @include('invoices.partials.method-icon', ['type' => $agreement->type])
                            <div class="min-w-0">
                                <div class="font-semibold truncate">{{ $agreement->name }}</div>
                                @if($agreement->expiry)
                                <div class="text-sm text-base/60">
                                    {{ __('account.expires', ['date' => \Carbon\Carbon::parse($agreement->expiry)->format('m/Y')]) }}
                                </div>
                                @endif
                                @if(!$usable)
                                <div class="text-xs text-base/60 mt-1">{{ __('account.payment_method_unavailable') }}</div>
                                @elseif(!$marked && isset($ownWayMethods[$agreement->id]))
                                {{-- Another gateway's: it charges the items paid with that gateway (owner 2026-11-01). --}}
                                <div class="text-xs text-base/60 mt-1">{{ __('account.pays_own_way', ['gateway' => $ownWayMethods[$agreement->id]]) }}</div>
                                @endif
                            </div>
                        </div>
                        {{-- The texts as JS strings (Js::from): a name with an apostrophe (a PayPal email) broke the handler (payment sweep). --}}
                        <button type="button" class="shrink-0 min-h-[36px] px-2.5 rounded-md border border-neutral text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-500/10 cursor-pointer"
                            aria-label="{{ __('account.methods_cards.remove', ['name' => $agreement->name]) }}"
                            x-on:click="$store.confirmation.confirm({
                                title: {{ \Illuminate\Support\Js::from(__('account.remove_payment_method')) }},
                                message: {{ \Illuminate\Support\Js::from(__('account.remove_payment_method_confirm', ['name' => $agreement->name])) }},
                                confirmText: {{ \Illuminate\Support\Js::from(__('account.confirm')) }},
                                cancelText: {{ \Illuminate\Support\Js::from(__('account.cancel')) }},
                                callback: () => $wire.removePaymentMethod({{ \Illuminate\Support\Js::from($agreement->ulid) }})
                            })">
                            <x-ri-delete-bin-line class="size-4 sm:hidden" aria-hidden="true" />
                            <span class="hidden sm:inline">{{ __('account.methods_cards.remove_short') }}</span>
                        </button>
                    </div>
                    @if ($usable)
                    <label class="flex items-center gap-2 min-h-[32px] cursor-pointer">
                        {{-- Disabled while a pick is on its way: a second one sent meanwhile would supersede it, and the radios could
                             show one method while the account pays with another (review of 6a). --}}
                        <input type="radio" name="automatic-method" value="{{ $agreement->ulid }}" wire:model.live="automaticMethod"
                            wire:loading.attr="disabled" wire:target="automaticMethod"
                            class="form-radio text-primary focus:ring-primary">
                        <span class="text-sm font-semibold {{ $marked ? 'text-primary' : 'text-base/70' }}">
                            {{ $marked ? __('account.default_payment_method') : __('account.make_default_payment_method') }}
                            <span class="sr-only">: {{ $agreement->name }}</span>
                        </span>
                    </label>
                    @endif
                </div>
                @endforeach

                @if ($card['saved'] === [] && $card['canAdd'])
                <p class="px-4 pt-3 text-sm text-base/60">{{ __('account.methods_cards.none_saved') }}</p>
                @elseif ($card['saved'] === [])
                <p class="px-4 py-3 text-sm text-base/60">{{ __('account.methods_cards.saves_nothing') }}</p>
                @endif
                @if ($card['canAdd'])
                <div class="p-3">
                    <button type="button" wire:click="addMethod({{ (int) $gateway->id }})" wire:loading.attr="disabled" wire:target="addMethod"
                        class="w-full min-h-[40px] rounded-md border border-dashed border-primary text-primary text-sm font-semibold hover:bg-primary/5 cursor-pointer disabled:opacity-50">
                        <x-ri-add-line class="size-4 inline" aria-hidden="true" />
                        {{ __('account.add_payment_method') }}
                    </button>
                </div>
                @endif
            </section>
            @endforeach
        </fieldset>
        @else
        <div class="bg-background-secondary border border-neutral p-6 rounded-lg text-center">
            <x-ri-bank-card-line class="size-12 text-base/30 mx-auto mb-3" />
            <p class="text-base/70 mb-4">{{ __('account.no_saved_payment_methods') }}</p>
        </div>
        @endif

        {{--
            How each service and domain is paid (docs/pay-flow-ux.md, PR 6b): the same choice as on its own page
            (updatedWays → ItemPaymentMethod::set, its open invoice following), and what the cron does with it.
        --}}
        @if ($items !== null && $items->total() > 0)
        <section class="mt-8" aria-labelledby="ways-title">
            <h3 id="ways-title" class="text-lg font-bold">{{ __('account.ways.title') }}</h3>
            <p class="text-sm text-base/70 mb-3">{{ __('account.ways.lead') }}</p>
            <div class="border border-neutral rounded-lg overflow-hidden" wire:loading.class="opacity-60" wire:target="ways">
                <div class="hidden md:grid grid-cols-[minmax(0,1.2fr)_minmax(0,1.3fr)_minmax(0,1fr)] gap-4 px-4 py-2 bg-background-secondary text-xs font-semibold uppercase tracking-wide text-base/60" aria-hidden="true">
                    <span>{{ __('account.ways.service') }}</span>
                    <span>{{ __('account.ways.paid_with') }}</span>
                    <span>{{ __('account.ways.automatic') }}</span>
                </div>
                @foreach ($itemRows as $row)
                @php($item = $row['item'])
                @php($name = $item instanceof \App\Models\Service ? $item->label : $item->fqdn)
                <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1.3fr)_minmax(0,1fr)] gap-2 md:gap-4 md:items-center px-4 py-3 border-t border-neutral first:border-t-0 md:first:border-t" wire:key="way-{{ $row['key'] }}">
                    <div class="min-w-0">
                        <a href="{{ $item instanceof \App\Models\Service ? route('services.show', $item) : route('domains.show', $item) }}" wire:navigate
                            class="font-semibold hover:underline break-words">{{ $name }}</a>
                        @if ($item->expires_at)
                        <div class="text-xs text-base/60">{{ __('account.ways.renews', ['date' => $item->expires_at->format('d/m/Y')]) }}</div>
                        @endif
                    </div>
                    <div class="min-w-0">
                        @if ($row['mayChange'])
                        <label for="way-{{ $row['key'] }}" class="md:sr-only text-xs font-semibold text-base/60">{{ __('account.ways.paid_with') }}</label>
                        {{-- Disabled while a change is on its way, as the radios above (review of 6a). --}}
                        <select id="way-{{ $row['key'] }}" wire:model.live="ways.{{ $row['key'] }}" wire:loading.attr="disabled" wire:target="ways"
                            aria-label="{{ __('account.ways.paid_with_for', ['name' => $name]) }}"
                            class="w-full min-h-[40px] rounded-md bg-background-secondary border border-neutral px-2 text-sm">
                            {{-- Each way says whether a saved method charges it or it is paid from the invoice (owner 2026-11-01). --}}
                            <option value="">{{ \App\Services\Payment\ItemPaymentMethod::optionLabel($item, null) }}</option>
                            @foreach ($row['options'] as $gateway)
                            <option value="{{ $gateway->id }}">{{ \App\Services\Payment\ItemPaymentMethod::optionLabel($item, $gateway) }}</option>
                            @endforeach
                            @if ($row['kept'])
                            <option value="{{ $item->payment_gateway_id }}">{{ \App\Services\Payment\ItemPaymentMethod::optionLabel($item, $item->paymentGateway) }}</option>
                            @endif
                        </select>
                        @else
                        <span class="text-sm">{{ \App\Services\Payment\ItemPaymentMethod::optionLabel($item, $item->paymentGateway) }}</span>
                        @endif
                    </div>
                    <div class="text-sm text-base/70">
                        <span class="md:sr-only text-xs font-semibold text-base/60">{{ __('account.ways.automatic') }}:</span>
                        {{ $row['status'] }}
                    </div>
                </div>
                @endforeach
            </div>
            <div class="mt-2">{{ $items->links(data: ['scrollTo' => '#ways-title']) }}</div>
        </section>
        @endif

        <h3 class="text-lg font-bold pb-3 mt-8">{{ __('account.recent_transactions') }}</h3>
        @foreach ($transactions as $transaction)
        <a href="{{ route('invoices.show', $transaction->invoice) }}" wire:navigate>
            <div
                class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-1 rounded-lg mb-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="bg-secondary/10 p-2 rounded-lg">
                            <x-ri-bill-line class="size-5 text-secondary" />
                        </div>
                        <span class="font-medium">
                            {{ $transaction->transaction_id ? __('account.transaction_ref', ['id' => $transaction->transaction_id]) : __('account.transaction_ref_none') }}
                        </span>
                        <span class="text-base/50 font-semibold">
                            <x-ri-circle-fill class="size-1 text-base/20" />
                        </span>
                        <span class="text-base text-sm">
                            @if ($transaction->is_credit_transaction)
                            {{ __('account.transaction_from_credit', ['amount' => $transaction->formattedAmount]) }}
                            @else
                            {{ __('account.transaction_with', ['amount' => $transaction->formattedAmount, 'gateway' => $transaction->gateway?->name ?? '—']) }}
                            @endif
                        </span>
                    </div>
                    <div class="pr-2">
                        <span class="text-sm text-base/70 mr-1">
                            @if($transaction->status === \App\Enums\InvoiceTransactionStatus::Succeeded)
                            <span
                                class="inline-flex items-center px-1 py-0.5 pr-1.5 rounded-full text-xs bg-green-100 text-green-800">
                                <x-ri-check-line class="size-3 mr-1" />
                                {{ __('invoices.transaction_statuses.succeeded') }}
                            </span>
                            @elseif($transaction->status === \App\Enums\InvoiceTransactionStatus::Processing)
                            <span
                                class="inline-flex items-center px-1 py-0.5 pr-1.5 rounded-full text-xs bg-yellow-100 text-yellow-800">
                                <x-ri-loader-5-fill class="size-3 mr-1 fill-yellow-600 animate-spin" />
                                {{ __('invoices.transaction_statuses.processing') }}
                            </span>
                            @elseif($transaction->status === \App\Enums\InvoiceTransactionStatus::Failed)
                            <span
                                class="inline-flex items-center px-1 py-0.5 pr-1.5 rounded-full text-xs bg-red-100 text-red-800">
                                <x-ri-close-line class="size-3 mr-1" />
                                {{ __('invoices.transaction_statuses.failed') }}
                            </span>
                            @endif
                        </span>
                        {{ $transaction->created_at->format('d M Y H:i') }}
                    </div>
                </div>
            </div>
        </a>
        @endforeach
        {{ $transactions->links() }}
    </div>
</div>
