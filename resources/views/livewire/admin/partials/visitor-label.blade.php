{{--
    What a visitor's chat says about itself, for the staff (docs/livechat.md §3 Α, §4): all of it typed by the visitor or
    sent by a launcher, so plain escaped text, never a link, a mailto: or an attribute. The badge comes from the chat,
    never from the name (F24).
--}}
<div class="flex flex-col gap-0.5 text-xs text-gray-600 dark:text-gray-300">
    <div class="flex flex-wrap items-center gap-1">
        <x-filament::badge size="sm" color="info">{{ __('Visitor') }}</x-filament::badge>
        @if ($chat->client_id !== null)
        <span>{{ __('started anonymously') }}</span>
        @endif
        @if ($chat->visitor_name !== null || $chat->visitor_email !== null)
        <span>{{ $chat->visitor_name ?? '—' }}@if ($chat->visitor_email !== null) · {{ __('unverified') }}: {{ $chat->visitor_email }}@endif</span>
        @endif
    </div>
    @if ($chat->src !== null || $chat->page !== null)
    <span>{{ __('Started from :where, :time', ['where' => ($chat->src ?? '') . ($chat->page ?? ''), 'time' => $chat->created_at?->format('H:i')]) }}</span>
    @endif
</div>
