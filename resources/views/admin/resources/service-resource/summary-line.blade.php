{{-- A service's usage and hosted domains on one line (ServiceResource::summaryLine), as WHMCS's above its product. --}}
<div x-data="{ all: false }" class="flex flex-wrap items-center gap-x-5 gap-y-1 rounded-xl bg-white px-4 py-2 text-sm text-gray-700 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
    @if ($disk)
    <span><strong class="text-gray-950 dark:text-white">Disk</strong> {{ $disk }}</span>
    @if ($diskPercent !== null)
    <span class="inline-block h-1.5 w-24 rounded bg-gray-200 dark:bg-white/10" aria-hidden="true">
        <span @class(['block h-1.5 rounded', 'bg-danger-500' => $diskPercent >= 90, 'bg-warning-500' => $diskPercent >= 75 && $diskPercent < 90, 'bg-primary-600' => $diskPercent < 75]) style="width: {{ max(1, min(100, (int) $diskPercent)) }}%"></span>
    </span>
    @endif
    @endif
    @if ($bandwidth)
    <span><strong class="text-gray-950 dark:text-white">Bandwidth</strong> {{ $bandwidth }}</span>
    @endif
    @if ($accounts)
    <span><strong class="text-gray-950 dark:text-white">{{ $accounts[0] }}</strong> {{ $accounts[1] }}</span>
    @endif
    @if ($ip)
    <span @class(['text-danger-600 dark:text-danger-400' => $ipDiffers]) @if ($ipDiffers) title="Not the dedicated IP set here ({{ $dedicatedIp }})." @endif>
        <strong class="text-gray-950 dark:text-white">IP</strong> {{ $ip }}@if ($ipDiffers) ⚠@endif
    </span>
    @endif
    @if ($hosted !== [])
    <span class="min-w-0">
        <strong class="text-gray-950 dark:text-white">Hosts</strong>
        <span x-show="!all">{{ implode(', ', array_slice($hosted, 0, 2)) }}@if (count($hosted) > 2) <button type="button" x-on:click="all = true" class="text-primary-600 hover:underline">+{{ count($hosted) - 2 }}</button>@endif</span>
        <span x-show="all" x-cloak>{{ implode(', ', $hosted) }}</span>
    </span>
    @endif
    @if ($readAt)
    <span class="ms-auto text-xs text-gray-500 dark:text-gray-400">Updated {{ $readAt }}</span>
    @endif
</div>
