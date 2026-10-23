@php
    $size = fn (?int $bytes) => $bytes === null ? '—' : ($bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024, 0) . ' KB');
    $attachments = $this->attachments();
@endphp
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Logs only: invoices, payments, tickets and their messages are never deleted here. Each log keeps entries for its retention (Settings → Cronjob → Data Retention), and the daily cron deletes the older ones; 0 keeps them for ever.') }}
    </p>

    <x-filament::section :heading="__('Logs')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pe-4">{{ __('Log') }}</th>
                        <th class="py-2 pe-4 text-right">{{ __('Entries') }}</th>
                        <th class="py-2 pe-4 text-right">{{ __('Size') }}</th>
                        <th class="py-2 pe-4">{{ __('Oldest') }}</th>
                        <th class="py-2 pe-4">{{ __('Kept') }}</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->logs() as $key => $log)
                        <tr wire:key="log-{{ $key }}">
                            <td class="py-2 pe-4">
                                <div class="font-medium text-gray-950 dark:text-white">{{ $log['label'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $log['description'] }}</div>
                            </td>
                            <td class="py-2 pe-4 text-right tabular-nums">{{ $log['estimated'] ? '≈ ' : '' }}{{ number_format($log['rows']) }}</td>
                            <td class="py-2 pe-4 text-right tabular-nums">{{ $size($log['bytes']) }}</td>
                            <td class="py-2 pe-4 tabular-nums">{{ $log['oldest'] ? \Illuminate\Support\Str::limit($log['oldest'], 10, '') : '—' }}</td>
                            <td class="py-2 pe-4">{{ $log['days'] > 0 ? trans_choice(':count day|:count days', $log['days']) : __('For ever') }}</td>
                            <td class="py-2 text-right">{{ ($this->deleteBeforeAction)(['key' => $key]) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('Ticket attachments')">
        <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
            <p>
                {{ __('Attachments of closed tickets: :count, :size on disk.', ['count' => number_format($attachments['count']), 'size' => $size($attachments['bytes'])]) }}
            </p>
            {{ $this->deleteAttachmentsAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
