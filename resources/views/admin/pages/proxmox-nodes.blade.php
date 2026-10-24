{{-- Utilities → Proxmox Nodes (ProxmoxNodes): each cluster's nodes and their graphs, read live, kept a minute. --}}
@php
    use App\Admin\Pages\ProxmoxNodes;
    use Illuminate\Support\Number;
    $unmapped = $this->unmappedByNode();
@endphp
<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2">
        @foreach (ProxmoxNodes::TIMEFRAMES as $value => $label)
            <x-filament::button size="sm" :color="$timeframe === $value ? 'primary' : 'gray'" wire:click="$set('timeframe', '{{ $value }}')">{{ $label }}</x-filament::button>
        @endforeach
        <x-filament::link :href="App\Admin\Resources\ProxmoxGuestResource::getUrl()" class="ms-auto">Guests</x-filament::link>
    </div>

    @forelse ($this->clusters() as $cluster)
        <x-filament::section>
            <x-slot name="heading">{{ $cluster['name'] }}</x-slot>
            @if ($cluster['error'])
                <p class="text-danger-600">{{ $cluster['error'] }}</p>
            @endif
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                @foreach ($cluster['nodes'] as $row)
                    @php
                        $node = $row['node'];
                        $series = $row['series'];
                        $online = ($node['status'] ?? '') === 'online';
                    @endphp
                    <div class="rounded-lg border border-gray-200 dark:border-white/10 p-4 flex flex-col gap-3 min-w-0">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-semibold">{{ $node['node'] ?? '?' }}</span>
                            <x-filament::badge :color="$online ? 'success' : 'danger'">{{ $node['status'] ?? 'unknown' }}</x-filament::badge>
                            @if ($online)
                                <span class="text-sm text-gray-500">
                                    {{ (int) ($node['maxcpu'] ?? 0) }} CPU ·
                                    {{ Number::fileSize((int) ($node['mem'] ?? 0)) }} / {{ Number::fileSize((int) ($node['maxmem'] ?? 0)) }} RAM ·
                                    up {{ now()->subSeconds((int) ($node['uptime'] ?? 0))->diffForHumans(syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE, parts: 2) }}
                                </span>
                            @endif
                            @php $notMapped = $unmapped[$cluster['id'] . ':' . ($node['node'] ?? '')] ?? 0; @endphp
                            @if ($notMapped > 0)
                                <x-filament::badge color="warning">{{ $notMapped }} not mapped</x-filament::badge>
                            @endif
                        </div>
                        @if ($online)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach ([
                                    ['CPU', ['cpu' => '#3b82f6'], 100, '%'],
                                    ['Memory', ['memory' => '#8b5cf6'], 100, '%'],
                                    ['IO wait', ['iowait' => '#f59e0b'], null, '%'],
                                    ['Network in / out', ['netin' => '#10b981', 'netout' => '#ef4444'], null, 'B/s'],
                                ] as [$label, $lines, $max, $unit])
                                    @php
                                        $scale = $max ?? max(1e-9, ...array_map(fn ($key) => max(array_filter($series[$key], fn ($v) => $v !== null) ?: [0]), array_keys($lines)));
                                    @endphp
                                    <figure class="min-w-0">
                                        <figcaption class="text-xs text-gray-500 flex justify-between gap-2">
                                            <span>{{ $label }}</span>
                                            <span>
                                                @foreach ($lines as $key => $color)
                                                    @php $last = ProxmoxNodes::last($series[$key]); @endphp
                                                    <span style="color: {{ $color }}">{{ $last === null ? '—' : ($unit === 'B/s' ? Number::fileSize((int) $last) . '/s' : $last . '%') }}</span>
                                                @endforeach
                                            </span>
                                        </figcaption>
                                        <svg viewBox="0 0 300 60" preserveAspectRatio="none" class="w-full h-16 bg-gray-50 dark:bg-white/5 rounded" role="img" aria-label="{{ $label }}">
                                            @foreach ($lines as $key => $color)
                                                <polyline fill="none" stroke="{{ $color }}" stroke-width="1.5" vector-effect="non-scaling-stroke" points="{{ ProxmoxNodes::points($series[$key], (float) $scale) }}" />
                                            @endforeach
                                        </svg>
                                    </figure>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @empty
        <p class="text-sm text-gray-500">No Proxmox server yet: add one in Setup → Servers (type «Proxmox VE»).</p>
    @endforelse
</x-filament-panels::page>
