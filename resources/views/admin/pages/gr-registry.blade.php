{{-- Our account at the .gr registry (GrRegistry): read from what is kept; only «Read now» and the check ask the registry. --}}
<x-filament-panels::page>
    @if ($connections === [])
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('domains.gr_registry.no_connection') }}</p>
    </x-filament::section>
    @endif

    {{-- 1. The account --}}
    @foreach ($connections as $connection)
    <x-filament::section>
        <x-slot name="heading">{{ __('domains.gr_registry.account') }} · {{ $connection['label'] }}@unless ($connection['active']) ({{ __('domains.gr_registry.inactive') }})@endunless</x-slot>
        @if ($canManage)
        <x-slot name="headerEnd">
            <x-filament::button size="sm" color="gray" wire:click="readBalance({{ $connection['id'] }})" wire:loading.attr="disabled">{{ __('domains.gr_registry.read_now') }}</x-filament::button>
        </x-slot>
        @endif
        @php
            $b = $connection['balance'];
            $date = fn ($value) => blank($value) ? null : \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
        @endphp
        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-2 text-sm">
            <div><dt class="text-gray-500">{{ __('domains.gr_registry.balance') }}</dt>
                <dd class="text-lg font-semibold tabular-nums">{{ isset($b['amount']) ? number_format((float) $b['amount'], 2, ',', '.') . ' ' . ($b['currency'] ?? 'EUR') : '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('domains.gr_registry.charges') }}</dt>
                <dd @class(['font-semibold', 'text-success-600' => ($b['charges_allowed'] ?? null) === true, 'text-danger-600' => ($b['charges_allowed'] ?? null) === false])>
                    {{ ($b['charges_allowed'] ?? null) === null ? '—' : (($b['charges_allowed'] ?? false) ? __('domains.gr_registry.allowed') : __('domains.gr_registry.not_allowed')) }}</dd></div>
            <div><dt class="text-gray-500">{{ __('domains.gr_registry.suspends_on') }}</dt>
                <dd @class(['text-danger-600 font-semibold' => filled($b['suspends_on'] ?? null) || filled($b['suspended_on'] ?? null)])>
                    {{ $date($b['suspended_on'] ?? null) ? __('domains.gr_registry.suspended_since', ['date' => $date($b['suspended_on'])]) : ($date($b['suspends_on'] ?? null) ?? '—') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('domains.gr_registry.read_at') }}</dt>
                <dd>{{ blank($b['read_at'] ?? null) ? '—' : \Illuminate\Support\Carbon::parse($b['read_at'])->format('d/m/Y H:i') }}</dd></div>
        </dl>
        @if ($connection['problem'])
        <p class="mt-3 text-sm font-medium text-danger-600">⚠ {{ $connection['problem'] }}</p>
        @endif
    </x-filament::section>
    @endforeach

    {{-- 2. What needs a look --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('domains.gr_registry.to_check') }}</x-slot>
        <x-slot name="description">{{ __('domains.gr_registry.to_check_help') }}</x-slot>
        @if ($toCheck->isEmpty() && $changes->isEmpty())
        <p class="text-sm text-gray-500">{{ __('domains.gr_registry.nothing') }}</p>
        @endif
        @if ($changes->isNotEmpty())
        <h3 class="text-sm font-semibold mb-1">{{ __('domains.owner_change.open') }}</h3>
        <ul class="mb-4 divide-y divide-gray-100 dark:divide-white/5 text-sm">
            @foreach ($changes as $change)
            <li class="py-1.5 flex flex-wrap gap-x-3">
                <a class="text-primary-600 hover:underline" href="{{ \App\Admin\Resources\DomainResource::getUrl('view', ['record' => $change->domain_id]) }}">{{ $change->domain?->fqdn }}</a>
                <x-filament::badge size="sm" :color="in_array($change->status, ['unknown', 'refused'], true) ? 'danger' : 'warning'">{{ __('domains.owner_change.statuses.' . $change->status) }}</x-filament::badge>
                <span class="text-gray-500">{{ __('domains.owner_change.kind_' . $change->kind) }} · {{ $change->updated_at?->format('d/m/Y') }}</span>
            </li>
            @endforeach
        </ul>
        @endif
        @if ($toCheck->isNotEmpty())
        <h3 class="text-sm font-semibold mb-1">{{ __('domains.gr_registry.eett') }}</h3>
        <ul class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
            @foreach ($toCheck as $row)
            <li class="py-1.5">
                <a class="text-primary-600 hover:underline" href="{{ $row['url'] }}">{{ $row['domain']->fqdn }}</a>
                <span class="text-gray-500">· {{ $row['domain']->client?->name }}</span>
                <span class="block text-xs text-gray-600 dark:text-gray-400">
                    @foreach ($row['statuses'] as $status){{ $status['text'] }} <code class="text-gray-400">({{ $status['code'] }})</code>@if (!$loop->last) · @endif @endforeach
                </span>
            </li>
            @endforeach
        </ul>
        @endif
    </x-filament::section>

    {{-- 3. Names checked at the registry --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('domains.gr_registry.check') }}</x-slot>
        <x-slot name="description">{{ __('domains.gr_registry.check_help', ['count' => \App\Admin\Pages\GrRegistry::CHECK_MAX]) }}</x-slot>
        <form wire:submit="check" class="flex flex-col sm:flex-row gap-2 max-w-3xl">
            <textarea wire:model="names" rows="2" maxlength="2000" placeholder="mybusiness.gr athens.gr"
                class="flex-grow rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 px-3 py-2 text-sm"></textarea>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="check">{{ __('domains.transfer.check') }}</x-filament::button>
        </form>
        @if ($checkError)
        <p class="mt-2 text-sm text-danger-600">{{ $checkError }}</p>
        @endif
        @if ($checked !== [])
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/5 text-sm">
            @foreach ($checked as $row)
            <li class="py-1.5 flex flex-wrap items-center gap-x-3">
                <span class="font-medium">{{ $row['fqdn'] }}</span>
                @if ($row['restricted'])
                <x-filament::badge size="sm" color="warning">{{ __('domains.gr_registry.restricted') }}</x-filament::badge>
                @elseif ($row['available'])
                <x-filament::badge size="sm" color="success">{{ __('domains.gr_registry.free') }}</x-filament::badge>
                @else
                <x-filament::badge size="sm" color="gray">{{ __('domains.gr_registry.taken') }}</x-filament::badge>
                @endif
                @if ($row['reason'])<span class="text-gray-500">{{ $row['reason'] }}</span>@endif
            </li>
            @endforeach
        </ul>
        @endif
    </x-filament::section>

    {{-- 5. Our glue hosts --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('domains.gr_registry.hosts') }}</x-slot>
        <x-slot name="description">{{ __('domains.gr_registry.hosts_help') }}</x-slot>
        @if ($hosts->isEmpty())
        <p class="text-sm text-gray-500">{{ __('domains.gr_registry.hosts_none') }}</p>
        @else
        <ul class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
            @foreach ($hosts as $row)
            <li class="py-1.5 flex flex-wrap gap-x-3">
                <span class="font-medium">{{ $row['host'] }}</span>
                <span class="tabular-nums text-gray-600 dark:text-gray-400">{{ implode(', ', $row['addresses']) ?: '—' }}</span>
                <a class="text-primary-600 hover:underline" href="{{ $row['url'] }}">{{ $row['domain']->fqdn }}</a>
                @if ($row['read_at'])<span class="text-xs text-gray-400">{{ __('domains.gr_registry.read_at') }} {{ \Illuminate\Support\Carbon::parse($row['read_at'])->format('d/m/Y') }}</span>@endif
            </li>
            @endforeach
        </ul>
        @endif
    </x-filament::section>

    {{-- 4. What only grweb does --}}
    <x-filament::section>
        <x-slot name="heading">grweb</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">{{ __('domains.gr_registry.grweb_help') }}</p>
        <x-filament::button tag="a" :href="$grweb" target="_blank" rel="noopener noreferrer" color="gray" icon="heroicon-o-arrow-top-right-on-square">
            {{ __('domains.gr_registry.grweb_open') }}
        </x-filament::button>
    </x-filament::section>
</x-filament-panels::page>
