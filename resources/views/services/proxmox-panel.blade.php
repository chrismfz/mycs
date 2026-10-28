{{-- A VPS's own panel (owner 2026-10-28, Proxmox's client area): its state, resources, addresses, graphs and power.
     Read again every 5 seconds for a little while after an action (the cluster does it meanwhile: review of #502). --}}
<div @if ($pending) wire:poll.5s @endif>
@if ($vm)
@php($bytes = function ($value) {
    $value = (float) $value;
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return ($unit === 'B' ? (int) $value : number_format($value, 1, str_starts_with(app()->getLocale(), 'el') ? ',' : '.', '')) . ' ' . $unit;
        }
        $value /= 1024;
    }
})
@php($running = $vm['status'] === 'running')
{{-- Only running and stopped are told as such and powered; any other (the node unsure, a migration) is neither. --}}
@php($known = in_array($vm['status'], ['running', 'stopped'], true))
@php($series = fn (string $key, float $scale = 1) => array_map(fn ($p) => is_numeric($p[$key] ?? null) ? $p[$key] * $scale : null, $vm['graph']))
@php($memory = array_map(fn ($p) => is_numeric($p['mem'] ?? null) && is_numeric($p['maxmem'] ?? null) && $p['maxmem'] > 0 ? 100 * $p['mem'] / $p['maxmem'] : null, $vm['graph']))
@php($graphs = [
    ['label' => __('services.proxmox.graph.cpu'), 'lines' => [$series('cpu', 100)], 'max' => 100.0, 'unit' => '%'],
    ['label' => __('services.proxmox.graph.memory'), 'lines' => [$memory], 'max' => 100.0, 'unit' => '%'],
    ['label' => __('services.proxmox.graph.network'), 'lines' => [$series('netin'), $series('netout')], 'max' => null, 'unit' => 'bytes', 'legend' => [__('services.proxmox.in'), __('services.proxmox.out')]],
    ['label' => __('services.proxmox.graph.disk'), 'lines' => [$series('diskread'), $series('diskwrite')], 'max' => null, 'unit' => 'bytes', 'legend' => [__('services.proxmox.read'), __('services.proxmox.write')]],
])
<div class="bg-background-secondary rounded-lg p-4 mt-2">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h4 class="text-lg font-semibold">
            {{ __('services.proxmox.title') }}
            <span class="text-xs font-medium rounded px-2 py-0.5 ms-2 {{ $running ? 'bg-success/20 text-success' : 'bg-neutral text-base/70' }}">
                {{ __('services.proxmox.status.' . ($vm['status'] === null ? 'gone' : ($known ? $vm['status'] : 'unknown'))) }}
            </span>
        </h4>
        @if ($pending)
        <p class="text-sm text-base/70 w-full sm:w-auto sm:order-last">{{ __('services.proxmox.pending') }}</p>
        @endif
        @if ($canPower && $known)
        <div class="flex flex-wrap gap-2">
            @foreach (($running ? ['shutdown', 'reboot', 'stop'] : ['start']) as $action)
            <button type="button" wire:click="power('{{ $action }}')" wire:loading.attr="disabled"
                wire:confirm="{{ __('services.proxmox.confirm.' . $action) }}"
                class="text-sm rounded-md px-3 py-1 border border-neutral hover:bg-neutral {{ $action === 'stop' ? 'text-error' : '' }}">
                {{ __('services.proxmox.power.' . $action) }}
            </button>
            @endforeach
        </div>
        @elseif ($service->status === 'suspended')
        <p class="text-sm text-warning">{{ __('services.proxmox.suspended') }}</p>
        @endif
    </div>

    <dl class="grid sm:grid-cols-4 gap-4 mt-3 text-sm">
        <div><dt class="text-base/50">{{ __('services.proxmox.cpu') }}</dt>
            <dd class="font-semibold">{{ $running ? round($vm['cpu'] * 100) . '%' : '—' }} <span class="text-base/50 font-normal">· {{ trans_choice('services.proxmox.cores', $vm['cpus'], ['count' => $vm['cpus']]) }}</span></dd></div>
        <div><dt class="text-base/50">{{ __('services.proxmox.memory') }}</dt>
            <dd class="font-semibold">{{ $running ? $bytes($vm['memory']) : '—' }} <span class="text-base/50 font-normal">/ {{ $bytes($vm['max_memory']) }}</span></dd></div>
        <div><dt class="text-base/50">{{ __('services.proxmox.disk') }}</dt><dd class="font-semibold">{{ $bytes($vm['max_disk']) }}</dd></div>
        <div><dt class="text-base/50">{{ __('services.proxmox.uptime') }}</dt>
            <dd class="font-semibold">{{ $running && $vm['uptime'] > 0 ? \Carbon\CarbonInterval::seconds($vm['uptime'])->cascade()->forHumans(['short' => true, 'parts' => 2]) : '—' }}</dd></div>
    </dl>
    @if ($vm['ips'] || $vm['macs'])
    <dl class="grid sm:grid-cols-2 gap-4 mt-3 text-sm">
        @if ($vm['ips'])<div><dt class="text-base/50">{{ __('services.proxmox.ips') }}</dt><dd class="font-mono text-xs break-all select-all">{{ implode(' · ', $vm['ips']) }}</dd></div>@endif
        @if ($vm['macs'])<div><dt class="text-base/50">{{ __('services.proxmox.macs') }}</dt><dd class="font-mono text-xs break-all">{{ implode(' · ', $vm['macs']) }}</dd></div>@endif
    </dl>
    @endif

    <div class="flex items-center justify-between mt-5">
        <h5 class="text-sm font-semibold">{{ __('services.proxmox.graphs') }}</h5>
        <div class="flex gap-1 text-xs">
            @foreach (\MyCS\Proxmox\ProxmoxClient::TIMEFRAMES as $frame)
            <button type="button" wire:click="$set('timeframe', '{{ $frame }}')" class="rounded px-2 py-0.5 {{ $timeframe === $frame ? 'bg-primary text-white' : 'hover:bg-neutral' }}">
                {{ __('services.proxmox.timeframe.' . $frame) }}
            </button>
            @endforeach
        </div>
    </div>
    <div class="grid sm:grid-cols-2 gap-4 mt-2">
        @foreach ($graphs as $graph)
        @php($peak = max([0, ...array_merge(...array_map(fn ($l) => array_values(array_filter($l, fn ($v) => $v !== null)), $graph['lines']))]))
        @php($top = $graph['max'] ?? $peak)
        <div>
            <div class="flex justify-between text-xs text-base/60">
                <span class="font-semibold text-base">{{ $graph['label'] }}</span>
                <span>{{ __('services.proxmox.peak') }} {{ $graph['unit'] === '%' ? round($peak) . '%' : $bytes($peak) . '/s' }}</span>
            </div>
            <svg viewBox="0 0 300 60" preserveAspectRatio="none" class="w-full h-16 mt-1 rounded bg-neutral/40" role="img" aria-label="{{ $graph['label'] }}">
                @foreach ($graph['lines'] as $i => $line)
                <polyline fill="none" stroke-width="1.5" vector-effect="non-scaling-stroke" class="{{ $i === 0 ? 'stroke-primary' : 'stroke-warning' }}"
                    points="{{ \App\Support\Sparkline::points($line, 300, 60, $top > 0 ? (float) $top : null) }}" />
                @endforeach
            </svg>
            @isset($graph['legend'])
            <div class="flex gap-3 text-xs text-base/60 mt-1">
                <span><span class="inline-block w-2 h-2 rounded-full bg-primary"></span> {{ $graph['legend'][0] }}</span>
                <span><span class="inline-block w-2 h-2 rounded-full bg-warning"></span> {{ $graph['legend'][1] }}</span>
            </div>
            @endisset
        </div>
        @endforeach
    </div>
</div>
@else
<div class="bg-background-secondary rounded-lg p-4 mt-2 text-sm text-base/70">{{ __('services.proxmox.unavailable') }}</div>
@endif
</div>
