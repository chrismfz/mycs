{{-- A Redfish machine as its BMC says it now (EditServer «Hardware»). Every value is the BMC's text: escaped. --}}
@php
    /** @var \MyCS\Redfish\Machine|null $machine */
    $watts = fn (?float $value) => $value === null ? null : number_format($value, 0, '.', '') . ' W';
    $rows = $machine === null ? [] : array_filter([
        'Machine' => trim(($machine->manufacturer ?? '') . ' ' . ($machine->model ?? '')) ?: null,
        'Serial number' => $machine->serial,
        'SKU / service tag' => $machine->sku,
        'Asset tag' => $machine->assetTag,
        'Hostname (as its OS told the BMC)' => $machine->hostname,
        'Processors' => $machine->cpuCount === null && $machine->cpuModel === null ? null : trim(($machine->cpuCount !== null ? $machine->cpuCount . ' × ' : '') . ($machine->cpuModel ?? '')),
        'Memory' => $machine->memoryGiB === null ? null : rtrim(rtrim(number_format($machine->memoryGiB, 1, '.', ''), '0'), '.') . ' GiB',
        'BIOS' => $machine->biosVersion,
        'Power now' => $watts($machine->watts),
        'Power, last ' . ($machine->wattsIntervalMinutes ?? '?') . ' min (min / average / max)' => $machine->wattsAverage === null && $machine->wattsMax === null ? null
            : implode(' / ', array_map(fn (?float $v) => $watts($v) ?? '—', [$machine->wattsMin, $machine->wattsAverage, $machine->wattsMax])),
    ], fn ($value) => $value !== null && $value !== '');
    $healthColor = ['OK' => 'success', 'Warning' => 'warning', 'Critical' => 'danger'][$machine?->health] ?? 'gray';
@endphp
<div class="space-y-4 text-sm">
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-gray-500 dark:text-gray-400">Given to</span>
        @forelse ($services as $service)
            <x-filament::badge color="info">{{ $service['label'] }}</x-filament::badge>
        @empty
            <x-filament::badge color="gray">{{ $own ? 'Nobody: our own machine (Max Accounts 0)' : 'Nobody: free' }}</x-filament::badge>
        @endforelse
        @if (filled($location))
            <span class="text-gray-500 dark:text-gray-400">· {{ $location }}</span>
        @endif
    </div>

    @if ($error !== null)
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">The BMC was not read: {{ $error }}</div>
    @else
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::badge :color="$machine->isOn() === null ? 'gray' : ($machine->isOn() ? 'success' : 'danger')">Power: {{ $machine->powerState ?? 'not said' }}</x-filament::badge>
            <x-filament::badge :color="$healthColor">Health: {{ $machine->health ?? 'not said' }}</x-filament::badge>
            @if ($machine->systems > 1)
                <x-filament::badge color="warning">{{ $machine->systems }} systems behind this BMC: the first is shown</x-filament::badge>
            @endif
        </div>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-3">
            @foreach ($rows as $label => $value)
                <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="font-medium text-gray-950 dark:text-white sm:col-span-2">{{ $value }}</dd>
            @endforeach
        </dl>
        <p class="text-xs text-gray-500 dark:text-gray-400">Read from the BMC just now. Nothing is kept: history, graphs and alerts are the monitoring's.</p>
    @endif
</div>
