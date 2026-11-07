<x-filament-panels::page>
    {{-- Read only (docs/livechat.md §6α): no polling, no form. Every body through ChatText::render, the one raw output (§3 Α). --}}
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <x-filament::badge size="sm" :color="match ($liveChat->status) { 'waiting' => 'warning', 'active' => 'success', default => 'gray' }">{{ __(ucfirst($liveChat->status)) }}</x-filament::badge>
            <span class="text-gray-700 dark:text-gray-200">{{ $liveChat->department?->name ?? '—' }}</span>
            <span class="text-gray-500 dark:text-gray-400">· {{ __('Operator') }}: {{ $liveChat->operator?->name ?? '—' }}</span>
            <span class="text-gray-500 dark:text-gray-400">· {{ __('Started by') }}: {{ $liveChat->user?->name ?? '—' }}</span>
            <span class="text-gray-500 dark:text-gray-400">· {{ __('Started') }}: {{ $liveChat->created_at?->format('d/m/Y H:i') }}</span>
            @if ($liveChat->closed_at)
            <span class="text-gray-500 dark:text-gray-400">· {{ __('Closed') }}: {{ $liveChat->closed_at->format('d/m/Y H:i') }}</span>
            @endif
        </div>

        <div class="flex flex-col gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
            @forelse ($messages as $message)
            @php $client = $message->author_type === \App\Models\LiveChatMessage::CLIENT; @endphp
            @if ($message->author_type === \App\Models\LiveChatMessage::SYSTEM)
            {{-- A note only the staff ever see (F20), e.g. «Taken over by…». --}}
            <p class="text-center text-xs text-gray-500 dark:text-gray-400">{!! \App\Support\ChatText::render($message->body) !!} · {{ $message->created_at?->format('d/m/Y H:i') }}</p>
            @continue
            @endif
            <div @class(['flex flex-col', 'items-start' => $client, 'items-end' => !$client])>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $client ? ($message->user?->name ?? __('Customer')) : ($message->staff?->name ?? __('System')) }} · {{ $message->created_at?->format('d/m/Y H:i') }}
                </div>
                <div @class([
                    'mt-0.5 max-w-[85%] rounded-lg px-3 py-2 text-sm break-words',
                    'bg-gray-100 text-gray-900 dark:bg-white/5 dark:text-gray-100' => $client,
                    'bg-primary-50 text-gray-900 dark:bg-primary-500/10 dark:text-gray-100' => !$client,
                ])>{!! \App\Support\ChatText::render($message->body) !!}</div>
            </div>
            @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No messages') }}</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
