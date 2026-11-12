{{-- A bulk license (docs/zonecloud-bulk-license.md §7.1, the mockup approved 2026-11-12). --}}
<div class="space-y-3">
    <p class="text-sm text-base/60">{{ __('services.license.bulk_help') }}</p>

    {{-- At a glance --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
        <div class="bg-background-secondary rounded-lg p-3">
            <div class="text-xs text-base/60">{{ __('services.bulk_license.listed') }}</div>
            <div class="text-xl font-semibold">{{ $counts['listed'] }} <span class="text-xs text-base/50">/ {{ $counts['max'] }}</span></div>
        </div>
        <div class="bg-background-secondary rounded-lg p-3">
            <div class="text-xs text-base/60">{{ __('services.bulk_license.checked_day') }}</div>
            <div class="text-xl font-semibold text-success">{{ $counts['day'] }}</div>
        </div>
        <div class="bg-background-secondary rounded-lg p-3">
            <div class="text-xs text-base/60">{{ __('services.bulk_license.silent', ['days' => \App\Livewire\Services\BulkLicense::SILENT_DAYS]) }}</div>
            <div class="text-xl font-semibold {{ $counts['silent'] ? 'text-warning' : '' }}">{{ $counts['silent'] }}</div>
        </div>
        <div class="bg-background-secondary rounded-lg p-3">
            <div class="text-xs text-base/60">{{ __('services.bulk_license.never') }}</div>
            <div class="text-xl font-semibold">{{ $counts['never'] }}</div>
        </div>
    </div>

    {{-- This month: what was billed, and what it comes to as the list stands --}}
    @if ($period)
    <div class="border border-neutral rounded-lg p-3 text-sm flex flex-wrap gap-x-6 gap-y-1">
        <span class="font-semibold">{{ __('services.bulk_license.period', ['from' => $period['start']->translatedFormat('d M'), 'to' => $period['end']->copy()->subDay()->translatedFormat('d M Y')]) }}</span>
        <span>{{ trans_choice('services.bulk_license.period_ips', $period['ips'], ['count' => $period['ips']]) }}</span>
        <span>{{ __('services.bulk_license.billed', ['amount' => $period['billed']]) }}</span>
        <span>{{ __('services.bulk_license.estimate', ['amount' => $period['estimate']]) }}</span>
        @if ((float) $period['correction'] != 0)
        <span class="text-base/60">{{ __('services.bulk_license.correction', ['amount' => ((float) $period['correction'] > 0 ? '+' : '') . $period['correction']]) }}</span>
        @endif
        <span class="w-full text-xs text-base/50">{{ $tiers }} · {{ __('services.bulk_license.rule') }}</span>
    </div>
    @elseif ($tiers)
    <p class="text-xs text-base/50">{{ $tiers }} · {{ __('services.bulk_license.rule') }}</p>
    @endif

    {{-- Tools --}}
    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('services.bulk_license.search') }}"
            class="flex-1 min-w-[12rem] rounded-md bg-background-secondary border border-neutral px-3 py-1.5 text-sm">
        @foreach (['all', 'silent', 'never'] as $one)
        <button type="button" wire:click="$set('filter', '{{ $one }}')"
            class="text-xs px-3 py-1 rounded-md border {{ $filter === $one ? 'border-primary text-primary' : 'border-neutral text-base/70' }}">
            {{ __('services.bulk_license.filters.' . $one) }}
        </button>
        @endforeach
        <button type="button" wire:click="csv" class="text-xs px-3 py-1 rounded-md border border-neutral text-base/70">CSV</button>
    </div>

    {{-- Add: paste, see what it does, confirm --}}
    @if ($canManage)
    <div class="border border-neutral rounded-lg p-3 space-y-2">
        <label class="text-sm" for="bulk-add">{{ __('services.bulk_license.add_help') }}</label>
        <textarea id="bulk-add" wire:model.live.debounce.500ms="add" rows="3" class="w-full rounded-md bg-background-secondary border border-neutral px-3 py-2 font-mono text-sm"
            placeholder="198.51.100.7 web12"></textarea>
        @if ($preview)
            @if ($preview['error'])
            <p class="text-sm text-error">{{ $preview['error'] }}</p>
            @else
            <p class="text-sm">
                <span class="text-success">{{ trans_choice('services.bulk_license.preview_new', count($preview['added']), ['count' => count($preview['added'])]) }}</span>
                @if ($preview['kept']) · {{ trans_choice('services.bulk_license.preview_kept', count($preview['kept']), ['count' => count($preview['kept'])]) }} @endif
                @if ($preview['cost'] !== null) · {{ __('services.bulk_license.preview_cost', ['amount' => $preview['cost'], 'count' => $preview['count']]) }} @endif
            </p>
            @foreach ($preview['warnings'] as $warning)<p class="text-xs text-warning">{{ $warning }}</p>@endforeach
            @endif
        @endif
        <div class="flex gap-2">
            <button type="button" wire:click="previewAdd" class="text-sm px-3 py-1 rounded-md border border-neutral">{{ __('services.bulk_license.check') }}</button>
            @if ($preview && !$preview['error'] && $preview['added'])
            <button type="button" wire:click="confirmAdd" class="text-sm px-3 py-1 rounded-md bg-primary text-white">{{ __('services.bulk_license.add') }}</button>
            @endif
            <span class="text-xs text-base/50 self-center">{{ __('services.bulk_license.add_from_now') }}</span>
        </div>
    </div>
    @endif

    {{-- Remove selected --}}
    @if ($canManage && $selected)
    <div class="bg-primary/10 text-primary rounded-md px-3 py-2 text-sm flex flex-wrap items-center gap-2">
        {{ trans_choice('services.bulk_license.selected', count($selected), ['count' => count($selected)]) }}
        <button type="button" wire:click="removeSelected" wire:confirm="{{ __('services.bulk_license.remove_confirm') }}" class="underline">{{ __('services.bulk_license.remove_selected') }}</button>
        <span class="text-xs">{{ __('services.bulk_license.stops') }}</span>
    </div>
    @endif

    {{-- The list --}}
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-base/60">
                    @if ($canManage)<th class="py-1 pe-2 w-6"></th>@endif
                    <th class="py-1 pe-3 font-medium">IP</th>
                    <th class="py-1 pe-3 font-medium">{{ __('services.bulk_license.name') }}</th>
                    <th class="py-1 pe-3 font-medium">{{ __('services.bulk_license.since') }}</th>
                    <th class="py-1 pe-3 font-medium">{{ __('services.license.last_check') }}</th>
                    @if ($canManage)<th class="py-1"></th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                <tr class="border-t border-neutral align-middle" wire:key="bulk-ip-{{ $row->id }}">
                    @if ($canManage)<td class="py-2 pe-2"><input type="checkbox" wire:model.live="selected" value="{{ $row->id }}" aria-label="{{ $row->ip }}"></td>@endif
                    <td class="py-2 pe-3 font-mono text-xs whitespace-nowrap">{{ $row->ip }}</td>
                    <td class="py-2 pe-3">
                        @if ($editing === $row->id)
                        <input type="text" wire:model="editLabel" wire:keydown.enter="saveEdit" wire:keydown.escape="cancelEdit" maxlength="100"
                            class="rounded-md bg-background-secondary border border-neutral px-2 py-1 text-sm w-40">
                        <button type="button" wire:click="saveEdit" class="text-xs underline">{{ __('services.bulk_license.save') }}</button>
                        @else
                        <span class="{{ $row->label ? '' : 'text-base/40' }}">{{ $row->label ?? __('services.bulk_license.no_name') }}</span>
                        @if ($canManage)<button type="button" wire:click="startEdit({{ $row->id }})" class="text-xs text-base/50 ms-1" aria-label="{{ __('services.bulk_license.rename') }}">✎</button>@endif
                        @endif
                    </td>
                    <td class="py-2 pe-3 whitespace-nowrap">{{ $row->added_at->translatedFormat('d M Y') }}</td>
                    <td class="py-2 pe-3 whitespace-nowrap">
                        @if (!$row->last_ok_at)
                        <span class="text-xs px-2 py-0.5 rounded-md bg-background-secondary text-base/60">{{ __('services.bulk_license.never_short') }}</span>
                        @elseif ($row->last_ok_at->lt($silentSince))
                        <span class="text-xs px-2 py-0.5 rounded-md bg-warning/20 text-warning" title="{{ $row->last_ok_at->translatedFormat('d M Y H:i') }}">{{ $row->last_ok_at->diffForHumans() }}</span>
                        @else
                        <span class="text-xs px-2 py-0.5 rounded-md bg-success/20 text-success" title="{{ $row->last_ok_at->translatedFormat('d M Y H:i') }}">{{ $row->last_ok_at->diffForHumans() }}</span>
                        @endif
                    </td>
                    @if ($canManage)
                    <td class="py-2 whitespace-nowrap text-right">
                        @if ($replacing === $row->id)
                        <input type="text" wire:model="replaceIp" wire:keydown.enter="saveReplace" wire:keydown.escape="cancelEdit" placeholder="{{ __('services.bulk_license.new_ip') }}"
                            class="rounded-md bg-background-secondary border border-neutral px-2 py-1 text-xs font-mono w-36">
                        <button type="button" wire:click="saveReplace" class="text-xs underline">{{ __('services.bulk_license.save') }}</button>
                        @else
                        <button type="button" wire:click="startReplace({{ $row->id }})" class="text-xs underline text-primary">{{ __('services.bulk_license.replace') }}</button>
                        <button type="button" wire:click="remove({{ $row->id }})" wire:confirm="{{ __('services.bulk_license.remove_one_confirm', ['ip' => $row->ip]) }}" class="text-xs underline text-error ms-2">{{ __('services.bulk_license.remove') }}</button>
                        @endif
                    </td>
                    @endif
                </tr>
                @empty
                <tr><td colspan="6" class="py-3 text-base/50">{{ $counts['listed'] ? __('services.bulk_license.none_match') : __('services.license.bulk_no_ips') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}

    {{-- Recent changes --}}
    @if ($history->isNotEmpty())
    <details class="text-sm">
        <summary class="cursor-pointer text-base/60">{{ __('services.bulk_license.history') }}</summary>
        <ul class="mt-2 space-y-1">
            @foreach ($history as $event)
            <li class="text-base/70"><span class="text-base/50">{{ $event['at']->translatedFormat('d M Y H:i') }}</span>
                {{ $event['added'] ? __('services.bulk_license.history_added') : __('services.bulk_license.history_removed') }}
                <span class="font-mono text-xs">{{ $event['ip'] }}</span>@if ($event['label']) ({{ $event['label'] }})@endif</li>
            @endforeach
        </ul>
    </details>
    @endif
</div>
