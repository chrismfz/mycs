{{-- Utilities → Machines → one machine, as redfish:sync last read its BMC. Every value is the BMC's text: escaped. --}}
@php
    /** @var \App\Models\RedfishMachine $row */
    /** @var \MyCS\Redfish\Machine|null $machine */
    $num = fn (?float $value, int $decimals = 0) => $value === null ? null : rtrim(rtrim(number_format($value, max($decimals, 1), '.', ''), '0'), '.');
    $watts = fn (?float $value) => $value === null ? null : number_format($value, 0, '.', '') . ' W';
    $color = fn (?string $health) => \App\Admin\Resources\RedfishMachineResource::healthColor($health);
    $card = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10';
    $muted = 'text-gray-500 dark:text-gray-400';
    $summary = $machine === null ? [] : array_filter([
        'Machine' => trim(($machine->manufacturer ?? '') . ' ' . ($machine->model ?? '')) ?: null,
        'Serial number' => $machine->serial,
        'SKU / service tag' => $machine->sku,
        'Asset tag' => $machine->assetTag,
        'Hostname (as its OS told the BMC)' => $machine->hostname,
        'Processors' => $machine->cpuCount === null && $machine->cpuModel === null ? null : trim(($machine->cpuCount !== null ? $machine->cpuCount . ' × ' : '') . ($machine->cpuModel ?? '')),
        'Memory' => $machine->memoryGiB === null ? null : $num($machine->memoryGiB, 1) . ' GiB',
        'BIOS' => $machine->biosVersion,
        'Power now' => $watts($machine->watts),
        'Power, last ' . ($machine->wattsIntervalMinutes ?? '?') . ' min (min / average / max)' => $machine->wattsAverage === null && $machine->wattsMax === null ? null
            : implode(' / ', array_map(fn (?float $v) => $watts($v) ?? '—', [$machine->wattsMin, $machine->wattsAverage, $machine->wattsMax])),
        'Air in' => $machine->inletCelsius() === null ? null : $num($machine->inletCelsius(), 1) . ' °C',
    ], fn ($value) => $value !== null && $value !== '');
    // Each part's table: its title, its rows, and its columns as [heading, fn(row) => text].
    $tables = $machine === null ? [] : [
        ['Fans', $machine->fans, [['Fan', fn ($r) => $r['name']], ['Speed', fn ($r) => $r['rpm'] !== null ? $num($r['rpm']) . ' RPM' : ($r['percent'] !== null ? $num($r['percent']) . ' %' : null)]]],
        ['Temperatures', $machine->temperatures, [['Sensor', fn ($r) => $r['name']], ['Now', fn ($r) => $num($r['celsius'], 1) . ' °C'],
            ['Warning / critical at', fn ($r) => $r['warning'] === null && $r['critical'] === null ? null : ($num($r['warning']) ?? '—') . ' / ' . ($num($r['critical']) ?? '—') . ' °C']]],
        ['Power supplies', $machine->powerSupplies, [['Supply', fn ($r) => $r['name']], ['Model', fn ($r) => $r['model']], ['Draws', fn ($r) => $watts($r['watts'])], ['Rated', fn ($r) => $watts($r['capacity'])]]],
        ['Processors', $machine->processors, [['Socket', fn ($r) => $r['name']], ['Model', fn ($r) => $r['model']],
            ['Cores / threads', fn ($r) => $r['cores'] === null ? null : $r['cores'] . ($r['threads'] !== null ? ' / ' . $r['threads'] : '')]]],
        ['Memory', $machine->memory, [['Slot', fn ($r) => $r['name']], ['Size', fn ($r) => $r['gib'] === null ? null : $num($r['gib'], 1) . ' GiB'],
            ['Type', fn ($r) => trim(($r['type'] ?? '') . ($r['mhz'] !== null ? ' ' . $r['mhz'] . ' MHz' : '')) ?: null]]],
        ['Drives', $machine->drives, [['Drive', fn ($r) => $r['name']], ['Model', fn ($r) => $r['model']], ['Serial', fn ($r) => $r['serial']],
            ['Size', fn ($r) => $r['gb'] === null ? null : ($r['gb'] >= 1000 ? $num($r['gb'] / 1000, 2) . ' TB' : $num($r['gb']) . ' GB')],
            ['Kind', fn ($r) => trim(($r['media'] ?? '') . ' ' . ($r['protocol'] ?? '')) ?: null],
            ['Life left', fn ($r) => $r['life'] === null ? null : $num($r['life'], 1) . ' %'],
            ['Failure predicted', fn ($r) => $r['failing'] ? 'YES' : null]]],
    ];
@endphp
<div class="space-y-4 text-sm">
    <div class="{{ $card }} space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <span class="{{ $muted }}">Given to</span>
            @forelse ($given['services'] as $service)
                <a href="{{ \App\Admin\Resources\ServiceResource::getUrl('edit', ['record' => $service['id']]) }}"><x-filament::badge color="info">{{ $service['label'] }}</x-filament::badge></a>
            @empty
                <x-filament::badge :color="$given['own'] ? 'gray' : 'success'">{{ $given['own'] ? 'Nobody: our own machine (Max Accounts 0)' : 'Nobody: free' }}</x-filament::badge>
            @endforelse
            @if (filled($location))
                <span class="{{ $muted }}">· {{ $location }}</span>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($machine !== null)
                <x-filament::badge :color="$machine->isOn() === null ? 'gray' : ($machine->isOn() ? 'success' : 'danger')">Power: {{ $machine->powerState ?? 'not said' }}</x-filament::badge>
                <x-filament::badge :color="$color($machine->health)">Health: {{ $machine->health ?? 'not said' }}</x-filament::badge>
                @if ($machine->systems > 1)
                    <x-filament::badge color="warning">{{ $machine->systems }} systems behind this BMC: the first is shown</x-filament::badge>
                @endif
            @endif
            <span class="{{ $muted }}">{{ $row->read_at === null ? 'Never read yet.' : 'Read ' . $row->read_at->diffForHumans() . ' (' . $row->read_at->format('Y-m-d H:i') . ').' }}</span>
        </div>
        @if ($row->error !== null)
            <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                The last read failed {{ $row->failed_at?->diffForHumans() }}: {{ $row->error }}@if ($machine !== null) What is below is the read before it.@endif
            </div>
        @endif
        @if ($row->attentionLines() !== [])
            <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                <p class="font-medium">Look at</p>
                <ul class="list-disc ps-5">
                    @foreach ($row->attentionLines() as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($machine !== null && $machine->partsRead && !$machine->partsComplete)
            <div class="rounded-lg bg-warning-50 p-3 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">The BMC was too slow to read every part in time: some lists below are short. The hourly read waits longer.</div>
        @endif
    </div>

    @if ($summary !== [])
        <div class="{{ $card }}">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-3">
                @foreach ($summary as $label => $value)
                    <dt class="{{ $muted }}">{{ $label }}</dt>
                    <dd class="font-medium text-gray-950 dark:text-white sm:col-span-2">{{ $value }}</dd>
                @endforeach
            </dl>
        </div>
    @endif

    @foreach ($tables as [$title, $rows, $columns])
        <div class="{{ $card }}">
            <h3 class="mb-2 font-semibold text-gray-950 dark:text-white">{{ $title }} @if ($rows !== [])<span class="{{ $muted }} font-normal">({{ count($rows) }})</span>@endif</h3>
            @if ($rows === [])
                <p class="{{ $muted }}">{{ $title === 'Drives' ? 'The BMC lists none: disks on a controller it does not see (onboard SATA, software RAID) are not shown.' : 'The BMC lists none.' }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-start">
                        <thead>
                            <tr class="{{ $muted }} text-xs">
                                @foreach ($columns as [$heading])
                                    <th class="py-1 pe-4 text-start font-medium">{{ $heading }}</th>
                                @endforeach
                                <th class="py-1 text-start font-medium">Health</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($rows as $part)
                                <tr>
                                    @foreach ($columns as [, $value])
                                        <td class="py-1 pe-4 text-gray-950 dark:text-white">{{ $value($part) ?? '—' }}</td>
                                    @endforeach
                                    <td class="py-1"><x-filament::badge :color="$color($part['health'])">{{ $part['health'] ?? 'not said' }}</x-filament::badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
    <p class="text-xs {{ $muted }}">The last read only is kept: history, graphs and alerts are the monitoring's.</p>
</div>
