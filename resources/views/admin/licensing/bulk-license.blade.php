{{-- A bulk license for staff, on its service's admin page (docs/zonecloud-bulk-license.md §7.2, mockup approved 2026-11-13). --}}
<div class="space-y-4 text-sm">
    {{-- At a glance --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
        <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3">
            <div class="text-xs text-gray-500">On the list</div>
            <div class="text-lg font-medium">{{ $counts['listed'] }} <span class="text-xs text-gray-500">/ {{ $counts['max'] }}</span></div>
        </div>
        <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3">
            <div class="text-xs text-gray-500">Checked, last 24h</div>
            <div class="text-lg font-medium text-success-600 dark:text-success-400">{{ $counts['day'] }}</div>
        </div>
        <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3">
            <div class="text-xs text-gray-500">Silent {{ \App\Livewire\Services\BulkLicense::SILENT_DAYS }}+ days</div>
            <div @class(['text-lg font-medium', 'text-warning-600 dark:text-warning-400' => $counts['silent'] > 0])>{{ $counts['silent'] }}</div>
        </div>
        <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3">
            <div class="text-xs text-gray-500">Never checked</div>
            <div class="text-lg font-medium">{{ $counts['never'] }}</div>
        </div>
    </div>

    {{-- This period and its prices --}}
    <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3 space-y-2">
        <div class="flex flex-wrap justify-between gap-2">
            <span class="font-medium">
                @if ($period)
                    This period {{ $period['start']->format('d/m/Y') }} – {{ $period['end']->copy()->subDay()->format('d/m/Y') }}
                @else
                    No paid period running
                @endif
            </span>
            <span class="text-gray-500">
                @if ($tiers['error'])
                    <span class="text-danger-600 dark:text-danger-400">Prices cannot be read: {{ $tiers['error'] }}</span>
                @else
                    {{ $tiers['line'] }} ({{ $tiers['own'] ? 'this service\'s own' : 'the product\'s' }})
                @endif
            </span>
        </div>
        @if ($period)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            <div><div class="text-xs text-gray-500">Distinct IPs so far</div>{{ $period['ips'] }}</div>
            <div><div class="text-xs text-gray-500">Billed in advance</div>{{ $period['billed'] }}</div>
            <div><div class="text-xs text-gray-500">At {{ $period['ips'] }} IPs</div>{{ $period['estimate'] }}</div>
            <div><div class="text-xs text-gray-500">Next invoice</div>
                @if ((float) $period['correction'] > 0)
                    <span class="text-warning-600 dark:text-warning-400">+{{ $period['correction'] }} correction</span>
                @elseif ((float) $period['correction'] < 0)
                    {{ $period['correction'] }} as credit
                @else
                    no correction
                @endif
            </div>
        </div>
        <p class="text-xs text-gray-500">Net amounts, as the list stands now: the period closes at its end, at its distinct IPs.</p>
        @endif
        @if ($moving['joining'] || $moving['joined'])
        <p class="text-gray-600 dark:text-gray-300">Moving in: {{ $moving['joining'] }} joining{{ $moving['next'] ? ' (next ' . $moving['next']->format('d/m/Y') . ')' : '' }}, {{ $moving['joined'] }} joined</p>
        @endif
    </div>

    {{-- The list --}}
    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search IP or name"
            class="flex-1 min-w-[12rem] rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
        @foreach (['all' => 'All', 'silent' => 'Silent', 'never' => 'Never checked', 'removed' => 'Removed (' . $counts['removed'] . ')'] as $key => $label)
        <x-filament::button size="xs" :color="$filter === $key ? 'primary' : 'gray'" wire:click="$set('filter', '{{ $key }}')">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1 pe-3 font-medium">IP</th>
                    <th class="py-1 pe-3 font-medium">Name</th>
                    <th class="py-1 pe-3 font-medium">Since</th>
                    @if ($filter === 'removed')<th class="py-1 pe-3 font-medium">Removed</th>@endif
                    <th class="py-1 font-medium">Last good check</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="py-1.5 pe-3 font-mono text-xs">{{ $row->ip }}</td>
                    <td class="py-1.5 pe-3">{{ $row->label ?? '—' }}</td>
                    <td class="py-1.5 pe-3 whitespace-nowrap">{{ $row->added_at->format('d/m/Y') }}</td>
                    @if ($filter === 'removed')<td class="py-1.5 pe-3 whitespace-nowrap">{{ $row->removed_at?->format('d/m/Y') }}</td>@endif
                    <td class="py-1.5">
                        @if ($row->last_ok_at === null)
                            <x-filament::badge size="sm" color="gray">never</x-filament::badge>
                        @else
                            <x-filament::badge size="sm" :color="$row->removed_at === null && $row->last_ok_at->lt($silentSince) ? 'warning' : 'success'" :tooltip="$row->last_ok_at->toDateTimeString()">{{ $row->last_ok_at->diffForHumans() }}</x-filament::badge>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="py-3 text-gray-500">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}

    {{-- Its changes, by whom --}}
    <div>
        <div class="font-medium mb-1">History</div>
        <table class="w-full">
            <tbody>
                @forelse ($history as $change)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="py-1.5 pe-3 whitespace-nowrap text-gray-500">{{ $change['at']->format('d/m/Y H:i') }}</td>
                    <td class="py-1.5 pe-3">
                        <x-filament::badge size="sm" :color="$change['added'] ? 'success' : 'gray'" class="inline-flex">{{ $change['added'] ? 'added' : 'removed' }}</x-filament::badge>
                        <span class="font-mono text-xs">{{ $change['ip'] }}</span> {{ $change['label'] }}
                    </td>
                    <td class="py-1.5 text-gray-500 whitespace-nowrap">{{ $change['by'] }}</td>
                </tr>
                @empty
                <tr><td class="py-2 text-gray-500">No changes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Closed periods --}}
    @if ($periods->isNotEmpty())
    <div>
        <div class="font-medium mb-1">Closed periods</div>
        <table class="w-full">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1 pe-3 font-medium">Period</th>
                    <th class="py-1 pe-3 font-medium">IPs</th>
                    <th class="py-1 pe-3 font-medium">Billed</th>
                    <th class="py-1 pe-3 font-medium">Actual</th>
                    <th class="py-1 font-medium">Correction</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($periods as $closed)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="py-1.5 pe-3 whitespace-nowrap">{{ $closed->period_start->format('d/m/Y') }} – {{ $closed->period_end->copy()->subDay()->format('d/m/Y') }}</td>
                    <td class="py-1.5 pe-3">{{ $closed->ips }}</td>
                    <td class="py-1.5 pe-3">{{ $closed->billed }}</td>
                    <td class="py-1.5 pe-3">{{ $closed->actual }}</td>
                    <td class="py-1.5">
                        @if ((float) $closed->adjustment > 0)
                            +{{ $closed->adjustment }}{{ $closed->adjustmentItem ? ' · invoice #' . $closed->adjustmentItem->invoice_id : ' · on the next invoice' }}
                        @elseif ((float) $closed->adjustment < 0)
                            {{ $closed->adjustment }} · credit
                        @else
                            —
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
