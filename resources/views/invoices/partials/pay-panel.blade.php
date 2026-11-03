{{--
    The ways to pay by provider (docs/pay-flow-ux.md, PR 3 and 4), on the invoice's page and on a Mass Pay group's: each
    gateway once, its saved methods inside it, its one-time payment after them. The values are the old window's ('credit',
    a saved method's ulid, 'gateway-{id}'), so each page's processPayment and its guards are the same. A gateway's own form
    still opens in the window.

    Given by the page: $payAmount, $payGroups (PayOptions::byProvider), $payLabel; and, where they apply, $credit (the
    balance), $automaticMethodId (the method marked), $renewalsChoice ('toggle', 'already' or null), $payNote (a line under
    the choices) and $showManage (the link to the payment methods).
--}}
@php
    $credit ??= null;
    $automaticMethodId ??= null;
    $renewalsChoice ??= null;
    $payNote ??= null;
    $showManage ??= false;
    $row = fn (bool $chosen) => 'flex items-center gap-3 px-3 py-3 min-h-[44px] cursor-pointer transition-colors ' . ($chosen ? 'bg-primary/10 shadow-[inset_3px_0_0_hsl(var(--color-primary))]' : 'hover:bg-background-secondary');
@endphp
<div class="bg-background border border-neutral rounded-lg p-4 space-y-3" wire:loading.class="opacity-60 pointer-events-none" wire:target="selectedMethod,processPayment">
    <div class="flex items-baseline justify-between gap-2">
        <h2 id="pay-title" class="text-base font-semibold">{{ __('invoices.pay_panel.title') }}</h2>
        <span class="text-lg font-bold whitespace-nowrap">{{ $payAmount }}</span>
    </div>

    <fieldset class="space-y-3">
        <legend class="sr-only">{{ __('invoices.pay_panel.ways') }}</legend>

        @if ($credit)
        <label class="border border-dashed border-neutral rounded-lg {{ $row($selectedMethod === 'credit') }}">
            <input type="radio" name="pay-way" value="credit" wire:model.live="selectedMethod" class="form-radio text-primary focus:ring-primary">
            <x-ri-copper-coin-line class="size-6 text-primary shrink-0" aria-hidden="true" />
            <span class="min-w-0">
                <span class="block font-medium">{{ __('invoices.account_credits') }}</span>
                <span class="block text-sm text-base/60">{{ __('invoices.available_credits', ['amount' => $credit->formattedAmount]) }}</span>
            </span>
        </label>
        @endif

        @foreach ($payGroups as $group)
        @php($gateway = $group['gateway'])
        @if ($group['saved'] === [])
        {{-- A provider with nothing saved: one row, its one-time payment. --}}
        <label class="border border-neutral rounded-lg {{ $row($selectedMethod === 'gateway-' . $gateway->id) }}" wire:key="pay-gateway-{{ $gateway->id }}">
            <input type="radio" name="pay-way" value="gateway-{{ $gateway->id }}" wire:model.live="selectedMethod" class="form-radio text-primary focus:ring-primary">
            @if ($gateway->meta?->icon)
            <img src="{{ $gateway->meta->icon }}" alt="" class="size-8 object-contain shrink-0" />
            @else
            <x-ri-secure-payment-line class="size-6 text-primary shrink-0" aria-hidden="true" />
            @endif
            <span class="min-w-0">
                <span class="block font-medium">{{ $gateway->name }}</span>
                <span class="block text-sm text-base/60">{{ __('invoices.one_time_payment') }}</span>
            </span>
        </label>
        @else
        <div role="group" aria-labelledby="pay-group-{{ $gateway->id }}" class="border border-neutral rounded-lg overflow-hidden" wire:key="pay-group-{{ $gateway->id }}">
            <div class="flex items-center gap-2 px-3 py-2 bg-background-secondary border-b border-neutral">
                @if ($gateway->meta?->icon)
                <img src="{{ $gateway->meta->icon }}" alt="" class="size-5 object-contain" />
                @endif
                <span id="pay-group-{{ $gateway->id }}" class="text-sm font-semibold">{{ $gateway->name }}</span>
            </div>
            @foreach ($group['saved'] as $method)
            <label class="border-b border-neutral last:border-b-0 {{ $row($selectedMethod === $method->ulid) }}" wire:key="pay-saved-{{ $method->ulid }}">
                <input type="radio" name="pay-way" value="{{ $method->ulid }}" wire:model.live="selectedMethod" class="form-radio text-primary focus:ring-primary">
                @include('invoices.partials.method-icon', ['type' => $method->type])
                <span class="min-w-0 grow">
                    <span class="block font-medium truncate">{{ $method->name }}</span>
                    <span class="block text-sm text-base/60">
                        {{ __('invoices.pay_panel.saved') }}
                        @if ($method->expiry)
                        · {{ __('account.expires', ['date' => \Carbon\Carbon::parse($method->expiry)->format('m/Y')]) }}
                        @endif
                    </span>
                </span>
                @if ((int) $method->id === $automaticMethodId)
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-success/20 text-success whitespace-nowrap">{{ __('invoices.pay_panel.automatic') }}</span>
                @endif
            </label>
            @endforeach
            @if ($group['oneTime'])
            <label class="{{ $row($selectedMethod === 'gateway-' . $gateway->id) }}" wire:key="pay-gateway-{{ $gateway->id }}">
                <input type="radio" name="pay-way" value="gateway-{{ $gateway->id }}" wire:model.live="selectedMethod" class="form-radio text-primary focus:ring-primary">
                <x-ri-add-line class="size-6 text-base/60 shrink-0" aria-hidden="true" />
                <span class="min-w-0">
                    <span class="block font-medium">{{ __('invoices.pay_panel.once') }}</span>
                    <span class="block text-sm text-base/60">{{ __('invoices.pay_panel.once_hint') }}</span>
                </span>
            </label>
            @endif
        </div>
        @endif
        @endforeach
    </fieldset>

    @if ($payNote)
    <p class="text-sm text-base/70">{{ $payNote }}</p>
    @endif

    @if ($renewalsChoice === 'toggle')
    <div>
        <x-form.toggle :label="__('invoices.use_for_recurring')" wire:model.live="setAsDefault" />
        <p class="text-sm text-base/70 mt-1">{{ __('invoices.use_for_recurring_hint') }}</p>
    </div>
    @elseif ($renewalsChoice === 'already')
    <p class="text-sm text-base/70">{{ __('invoices.pay_panel.renewals_already') }}</p>
    @endif

    <x-button.primary class="w-full" wire:click="processPayment" wire:loading.attr="disabled" :disabled="!$selectedMethod">
        <x-loading target="processPayment" />
        <span wire:loading.remove wire:target="processPayment">{{ $payLabel }}</span>
    </x-button.primary>

    @if ($showManage)
    <a href="{{ route('account.payment-methods') }}" wire:navigate class="block text-center text-sm text-primary hover:underline">{{ __('invoices.pay_panel.manage') }}</a>
    @endif
</div>
