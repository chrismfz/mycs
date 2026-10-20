{{--
    A domain transfer (owner 2026-10-20): one name with its EPP code, or many, a line each; «Add to cart» stays here so
    more can follow; the steps.
--}}
@php($money = fn (?string $amount) => $amount === null || $currency === null ? '' : (new \App\Classes\Price(['price' => $amount, 'currency' => $currency]))->formatted->price)
@php($canTransfer = \App\Services\Domains\Ordering\DomainOffer::allowed('transfer'))
<div class="container mt-14 mb-16 max-w-3xl mx-auto space-y-6">
    <div class="text-center space-y-2">
        <h1 class="text-3xl font-semibold">{{ __('domains.transfer.title') }}</h1>
        <p class="text-base/60">{{ __('domains.transfer.lead') }}</p>
    </div>

    <div role="tablist" class="flex gap-2 border-b border-neutral">
        @foreach (['one' => __('domains.transfer.mode_one'), 'bulk' => __('domains.transfer.mode_bulk')] as $key => $label)
        <button type="button" role="tab" aria-selected="{{ $mode === $key ? 'true' : 'false' }}" wire:click="$set('mode', '{{ $key }}')"
            @class(['px-4 py-2 -mb-px border-b-2 text-sm font-semibold', 'border-secondary text-secondary' => $mode === $key, 'border-transparent text-base/60 hover:text-base' => $mode !== $key])>
            {{ $label }}
        </button>
        @endforeach
    </div>

    @if ($mode === 'bulk')
    <form wire:submit="addMany" class="bg-background-secondary border border-neutral rounded-lg p-6 space-y-3">
        <label for="lines" class="block font-semibold">{{ __('domains.transfer.bulk_label') }}</label>
        <p class="text-sm text-base/60">{{ __('domains.transfer.bulk_help', ['count' => \App\Livewire\DomainShop\Transfer::BULK_MAX]) }}</p>
        <textarea id="lines" wire:model="lines" rows="8" spellcheck="false"
            placeholder="mybusiness.gr ABCD-1234&#10;myshop.com x9Y8z7&#10;example.eu"
            class="w-full font-mono text-sm bg-background border border-neutral rounded-lg px-3 py-2"></textarea>
        <div class="flex justify-end">
            <x-button.primary class="!w-fit" type="submit" wire:loading.attr="disabled" :disabled="!$canTransfer">{{ __('domains.transfer.add_all') }}</x-button.primary>
        </div>
        <div wire:loading wire:target="addMany" class="text-base/60 text-sm">{{ __('domains.shop.searching') }}</div>
        @if ($error)
        <p class="text-error text-sm">{{ $error }}</p>
        @endif
        @if ($bulk !== [])
        <ul class="text-sm space-y-1" wire:loading.remove wire:target="addMany">
            @foreach ($bulk as $name => $done)
            <li>
                <span class="font-mono">{{ $name }}</span>:
                @if ($done === true)
                <span class="text-success">✓ {{ __('domains.transfer.added_short') }}</span>
                @else
                <span class="text-error">{{ $done }}</span>
                @endif
            </li>
            @endforeach
        </ul>
        @endif
    </form>
    @else
    <form wire:submit="check" class="bg-background-secondary border border-neutral rounded-lg p-6 space-y-3">
        <div class="grid sm:grid-cols-2 gap-3">
            <label class="flex flex-col gap-1 text-sm font-semibold">{{ __('domains.transfer.domain') }}
                <input type="text" wire:model="query" autofocus maxlength="100" placeholder="{{ __('domains.transfer.placeholder') }}"
                    class="font-normal bg-background border border-neutral rounded-lg px-3 py-2 text-base" />
            </label>
            <label class="flex flex-col gap-1 text-sm font-semibold">{{ __('domains.order.epp_code') }}
                <input type="text" wire:model="epp" maxlength="255" autocomplete="off" placeholder="{{ __('domains.transfer.epp_placeholder') }}"
                    class="font-normal font-mono bg-background border border-neutral rounded-lg px-3 py-2 text-base" />
            </label>
        </div>
        <p class="text-xs text-base/60">{{ __('domains.order.epp_help') }}</p>
        <div class="flex justify-end">
            <x-button.secondary class="!w-fit" type="submit" wire:loading.attr="disabled">{{ __('domains.transfer.check') }}</x-button.secondary>
        </div>
        <div wire:loading wire:target="check" class="text-base/60 text-sm">{{ __('domains.shop.searching') }}</div>
        @if ($error)
        <p class="text-error text-sm">{{ $error }}</p>
        @endif

        @if ($result)
        @php($status = $result['status'])
        @php($transferable = $status === \App\Services\Domains\DomainSearch::TAKEN && $result['transferable'] && $canTransfer && $result['transfer_price'] !== null)
        <div wire:loading.remove wire:target="check" class="border-t border-neutral pt-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <p class="text-xl font-semibold">{{ $result['fqdn'] }}</p>
                @if ($transferable)
                <p class="text-success text-sm">✓ {{ __('domains.transfer.can') }}</p>
                @elseif ($status === \App\Services\Domains\DomainSearch::TAKEN)
                <p class="text-warning text-sm">{{ __('domains.transfer.cannot') }}
                    <a href="{{ route('contact') }}" wire:navigate class="underline">{{ __('navigation.contact') }}</a></p>
                @elseif ($status === \App\Services\Domains\DomainSearch::FREE)
                <p class="text-base/60 text-sm">{{ __('domains.transfer.not_registered') }}</p>
                @else
                <p class="text-base/60 text-sm">{{ __('domains.shop.unknown') }}</p>
                @endif
            </div>
            @if ($transferable)
            <div class="flex items-center gap-4 sm:text-right">
                <div>
                    <p class="font-semibold">{{ ($result['transfer_years'] ?? 1) > 1 ? trans_choice('domains.shop.per_years', $result['transfer_years'], ['price' => $money($result['transfer_price']), 'years' => $result['transfer_years']]) : __('domains.shop.per_year', ['price' => $money($result['transfer_price'])]) }}</p>
                    <p class="text-xs text-base/60">{{ __('domains.transfer.includes') }}</p>
                </div>
                <x-button.primary class="!w-fit" wire:click="transfer" wire:loading.attr="disabled">{{ __('product.add_to_cart') }}</x-button.primary>
            </div>
            @elseif ($status === \App\Services\Domains\DomainSearch::FREE && \App\Services\Domains\Ordering\DomainOffer::allowed('register'))
            <x-button.primary class="!w-fit" wire:click="register" wire:loading.attr="disabled">{{ __('domains.transfer.register_it') }}</x-button.primary>
            @endif
        </div>
        @endif
    </form>
    @endif

    @if ($inCart->isNotEmpty())
    {{-- What is already on its way: more can be added, then one order. --}}
    <div class="bg-background-secondary border border-secondary/40 rounded-lg p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="text-sm">
            <p class="font-semibold">{{ trans_choice('domains.transfer.in_cart', $inCart->count(), ['count' => $inCart->count()]) }}</p>
            <p class="text-base/60 break-all">{{ $inCart->implode(', ') }}</p>
        </div>
        <a href="{{ route('cart') }}" wire:navigate class="shrink-0"><x-button.primary class="!w-fit">{{ __('domains.transfer.to_cart') }}</x-button.primary></a>
    </div>
    @endif

    <div class="bg-background-secondary border border-neutral rounded-lg p-6">
        <h2 class="text-lg font-semibold mb-3">{{ __('domains.transfer.how') }}</h2>
        <ol class="list-decimal pl-5 space-y-2 text-base/80">
            <li>{{ __('domains.transfer.step_unlock') }}</li>
            <li>{{ __('domains.transfer.step_code') }}</li>
            <li>{{ __('domains.transfer.step_order') }}</li>
            <li>{{ __('domains.transfer.step_done') }}</li>
        </ol>
        <p class="mt-4 text-sm text-base/60">{{ __('domains.transfer.search_instead') }}
            <a href="{{ route('domain.search') }}" wire:navigate class="underline">{{ __('navigation.domain_search') }}</a></p>
    </div>
</div>
