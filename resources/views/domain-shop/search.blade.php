@php($money = fn (?string $amount) => $amount === null || $currency === null ? '' : (new \App\Classes\Price(['price' => $amount, 'currency' => $currency]))->formatted->price)
<div class="container mt-14 max-w-3xl mx-auto space-y-6">
    <div class="text-center space-y-2">
        <h1 class="text-3xl font-semibold">{{ __('domains.shop.title') }}</h1>
        <p class="text-base/60">{{ __('domains.shop.lead') }}</p>
    </div>

    <form wire:submit="search" class="flex gap-2">
        <input type="search" wire:model="query" autofocus maxlength="100" placeholder="{{ __('domains.shop.placeholder') }}"
            class="flex-grow bg-background-secondary border border-neutral rounded-lg px-4 py-3 text-lg" />
        <x-button.primary class="!w-fit px-6" type="submit" wire:loading.attr="disabled">{{ __('domains.shop.search') }}</x-button.primary>
    </form>

    <div wire:loading wire:target="search" class="text-center text-base/60">{{ __('domains.shop.searching') }}</div>

    @if ($error)
    <p class="text-center text-error">{{ $error }}</p>
    @endif

    @if ($results)
    <div wire:loading.remove wire:target="search" class="space-y-3">
        @if ($unsold)
        <p class="text-sm text-base/60">{{ __('domains.shop.unsold', ['tld' => $unsold]) }}</p>
        @endif
        @foreach ($results as $i => $row)
        <div class="bg-background-secondary border border-neutral rounded-lg flex flex-col sm:flex-row sm:items-center justify-between gap-2 {{ $i === 0 ? 'p-6' : 'p-4' }}">
            <div>
                <p class="{{ $i === 0 ? 'text-xl font-semibold' : 'font-medium' }}">{{ $row['fqdn'] }}</p>
                @if ($row['status'] === \App\Services\Domains\DomainSearch::FREE)
                <p class="text-success text-sm">✓ {{ __('domains.shop.free') }}</p>
                @elseif ($row['status'] === \App\Services\Domains\DomainSearch::TAKEN)
                <p class="text-base/60 text-sm">{{ __('domains.shop.taken') }}
                    · <a href="{{ route('domain.whois', ['domain' => $row['fqdn']]) }}" wire:navigate class="underline">{{ __('domains.shop.who_has_it') }}</a></p>
                @elseif ($row['status'] === \App\Services\Domains\DomainSearch::PREMIUM)
                <p class="text-warning text-sm">{{ __('domains.shop.premium') }}</p>
                @else
                <p class="text-base/60 text-sm">{{ __('domains.shop.unknown') }}</p>
                @endif
            </div>
            @if ($row['status'] === \App\Services\Domains\DomainSearch::FREE)
            <div class="flex items-center gap-4 sm:text-right">
                <div>
                    <p class="font-semibold">{{ __('domains.shop.per_year', ['price' => $money($row['price'])]) }}</p>
                    @if ($row['renew'])
                    <p class="text-xs text-base/60">{{ __('domains.shop.then', ['price' => $money($row['renew'])]) }}</p>
                    @endif
                </div>
                <x-button.primary class="!w-fit" wire:click="add('{{ $row['fqdn'] }}')" wire:loading.attr="disabled">{{ __('domains.order.add') }}</x-button.primary>
            </div>
            @elseif ($row['status'] === \App\Services\Domains\DomainSearch::TAKEN)
            <button type="button" class="text-sm underline" wire:click="transfer('{{ $row['fqdn'] }}')">{{ __('domains.order.transfer_it') }}</button>
            @endif
        </div>
        @endforeach
        <p class="text-xs text-base/50 text-center">{{ __('domains.shop.prices_note') }}
            <a href="{{ route('domain.pricing') }}" wire:navigate class="underline">{{ __('domains.shop.all_prices') }}</a></p>
    </div>
    @endif
</div>
