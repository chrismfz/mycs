{{-- A mail service's setup for staff (its admin page): the DNS its domain needs, checked, and its mailboxes. --}}
<div class="space-y-4">
@if (!$domain)
    <p class="text-sm text-gray-500">No mail domain on its server for this service (Utilities → Mail Domains maps one).</p>
@else
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm">
            <span class="font-medium">{{ $domain->domain }}</span> on {{ $domain->server?->name }} ·
            <span @class(['text-success-600 dark:text-success-400' => $check['ok'], 'text-danger-600 dark:text-danger-400' => !$check['ok']])>
                {{ $check['ok'] ? 'DNS ready for mail' : 'DNS needs attention' }}
            </span>
            · checked {{ \Illuminate\Support\Carbon::createFromTimestamp($check['checked_at'])->diffForHumans() }}
        </p>
        <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-path" wire:click="recheck" wire:loading.attr="disabled">Check again</x-filament::button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1 pe-3 font-medium"></th>
                    <th class="py-1 pe-3 font-medium">Record</th>
                    <th class="py-1 pe-3 font-medium">Name</th>
                    <th class="py-1 pe-3 font-medium">Found</th>
                    <th class="py-1 font-medium">Expected</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($check['rows'] as $row)
                <tr class="border-t border-gray-100 dark:border-white/10 align-top">
                    <td class="py-1.5 pe-3">
                        <x-filament::badge size="sm" :color="match ($row['status']) { 'ok' => 'success', 'fail' => 'danger', default => 'warning' }">
                            {{ $row['status'] === 'ok' ? '✓' : ($row['status'] === 'fail' ? '✗' : '!') }}
                        </x-filament::badge>
                    </td>
                    <td class="py-1.5 pe-3 whitespace-nowrap">
                        {{ strtoupper($row['key']) }} <span class="text-xs text-gray-500">{{ $row['type'] }}{{ $row['required'] ? '' : ' · advised' }}</span>
                        @if ($row['note'])<div class="text-xs text-warning-600 dark:text-warning-400">{{ __('services.mailcow.notes.' . $row['note'], [], 'en') }}</div>@endif
                    </td>
                    <td class="py-1.5 pe-3 font-mono text-xs break-all">{{ $row['name'] }}</td>
                    <td class="py-1.5 pe-3 font-mono text-xs break-all">{{ $row['found'] ? implode(' · ', $row['found']) : '—' }}</td>
                    <td class="py-1.5 font-mono text-xs break-all text-gray-500">{{ $row['status'] === 'ok' ? '' : $row['expected'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div>
        <p class="text-sm font-medium">
            Mailboxes: {{ $domain->mailboxes }} / {{ $domain->mailbox_limit ?: '∞' }} · aliases {{ $domain->aliases }} ·
            {{ \Illuminate\Support\Number::fileSize($domain->used_bytes, 1) }} of {{ $domain->quota_bytes ? \Illuminate\Support\Number::fileSize($domain->quota_bytes) : '∞' }}
            <span class="text-xs text-gray-500">· read {{ $domain->last_seen_at?->diffForHumans() }}</span>
        </p>
        <div class="mt-2">@include('admin.mailcow.mailboxes', ['mailboxes' => $mailboxes])</div>
    </div>
@endif
</div>
