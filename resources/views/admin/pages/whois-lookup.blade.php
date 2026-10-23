{{-- WHOIS for staff (WhoisLookup): the registry's answer, and the domain here when it is ours. --}}
<x-filament-panels::page>
    <form wire:submit="lookup" class="flex gap-2 max-w-2xl">
        <input type="search" wire:model="query" maxlength="255" placeholder="example.com" autofocus
            class="flex-grow rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 px-3 py-2" />
        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('domains.shop.lookup') }}</x-filament::button>
    </form>

    @if ($error)
    <p class="text-danger-600">{{ $error }}</p>
    @endif

    @if ($answer)
    @php($ours = $this->ours())
    <x-filament::section>
        <x-slot name="heading">{{ $fqdn }}</x-slot>
        @if ($ours)
        <p class="mb-3 text-sm">{{ __('Ours') }}:
            <a class="underline text-primary-600" href="{{ \App\Admin\Resources\DomainResource::getUrl('view', ['record' => $ours]) }}">{{ $ours->fqdn }}</a>
            ({{ $ours->status?->value ?? $ours->status }}, {{ $ours->client?->name }})</p>
        @endif
        @if ($answer['status'] === 'not_found')
        <p class="text-success-600">{{ __('domains.shop.whois_free') }}</p>
        @elseif ($answer['status'] === 'not_listed')
        <p>{{ __('domains.shop.whois_not_listed') }}</p>
        @elseif ($answer['status'] === 'unsupported')
        <p>{{ __('domains.shop.whois_unsupported') }}</p>
        @elseif ($answer['status'] === 'error' || $answer['status'] === 'invalid')
        <p>{{ __('domains.shop.whois_error') }}</p>
        @elseif ($answer['record'])
        @php($r = $answer['record'])
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-1 text-sm">
            <dt class="text-gray-500">{{ __('domains.shop.registrar') }}</dt><dd class="sm:col-span-2">{{ $r['registrar'] ?? '—' }}</dd>
            <dt class="text-gray-500">{{ __('domains.shop.registered') }}</dt><dd class="sm:col-span-2">{{ $r['registered'] ?? '—' }}</dd>
            <dt class="text-gray-500">{{ __('domains.shop.expires') }}</dt><dd class="sm:col-span-2">{{ $r['expires'] ?? '—' }}</dd>
            <dt class="text-gray-500">{{ __('domains.shop.changed') }}</dt><dd class="sm:col-span-2">{{ $r['changed'] ?? '—' }}</dd>
            <dt class="text-gray-500">{{ __('domains.shop.status') }}</dt><dd class="sm:col-span-2">{{ implode(', ', (array) ($r['status'] ?? [])) ?: '—' }}</dd>
            <dt class="text-gray-500">{{ __('domains.shop.nameservers') }}</dt><dd class="sm:col-span-2 font-mono">{{ implode(', ', (array) ($r['nameservers'] ?? [])) ?: '—' }}</dd>
            <dt class="text-gray-500">DNSSEC</dt><dd class="sm:col-span-2">{{ ($r['dnssec'] ?? null) === null ? '—' : ($r['dnssec'] ? __('domains.portal.on') : __('domains.portal.off')) }}</dd>
        </dl>
        @endif
        @if ($answer['text'])
        <details class="mt-3"><summary class="cursor-pointer text-sm text-gray-500">{{ __('Raw WHOIS') }}</summary>
            <pre class="text-xs whitespace-pre-wrap break-all mt-2">{{ $answer['text'] }}</pre></details>
        @endif
    </x-filament::section>
    @endif
</x-filament-panels::page>
