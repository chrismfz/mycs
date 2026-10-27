<div @if ($chat?->isOpen()) wire:poll.4s.visible @endif class="flex flex-col gap-3">
    @if ($chat === null)
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This chat is not available to you.') }}</p>
    @else
    <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
            @if ($clientUrl)
            <a href="{{ $clientUrl }}" wire:navigate class="hover:underline">{{ $chat->client?->name ?? __('Customer') }}</a>
            @else
            {{ $chat->client?->name ?? __('Customer') }}
            @endif
        </h3>
        <x-filament::badge size="sm" :color="match ($chat->status) { 'waiting' => 'warning', 'active' => 'success', default => 'gray' }">{{ __(ucfirst($chat->status)) }}</x-filament::badge>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $chat->department?->name }}@if ($chat->operator) · {{ $chat->operator->name }}@endif</span>
        <span class="flex-1"></span>
        <x-filament::button size="xs" color="gray" wire:click="$dispatch('customer-chat-open', { chat: null })">{{ __('Back to the staff chat') }}</x-filament::button>
    </div>

    <div class="flex max-h-[60vh] min-h-64 flex-col gap-3 overflow-y-auto rounded-lg border border-gray-200 p-3 dark:border-white/10"
        x-data x-init="$el.scrollTop = $el.scrollHeight">
        @foreach ($messages as $message)
        @php $client = $message->author_type === \App\Models\LiveChatMessage::CLIENT; @endphp
        <div @class(['flex flex-col', 'items-start' => $client, 'items-end' => !$client])>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{-- Which login of the account writes (G11), not only the account. --}}
                {{ $client ? ($message->user?->name ?? __('Customer')) : ($message->staff?->name ?? __('System')) }} · {{ $message->created_at?->format('d/m H:i') }}
            </div>
            <div @class([
                'mt-0.5 max-w-[85%] rounded-lg px-3 py-2 text-sm break-words',
                'bg-gray-100 text-gray-900 dark:bg-white/5 dark:text-gray-100' => $client,
                'bg-primary-50 text-gray-900 dark:bg-primary-500/10 dark:text-gray-100' => !$client,
            ])>{!! \App\Support\ChatText::render($message->body) !!}</div>
        </div>
        @endforeach
    </div>

    @error('body') <p class="text-sm text-warning-600">{{ $message }}</p> @enderror
    @if ($chat->status === \App\Models\LiveChat::WAITING && $canReply)
    <x-filament::button wire:click="claim">{{ __('Take') }}</x-filament::button>
    @elseif ($canReply && \App\Support\LiveChats::orphaned($chat))
    <p class="text-xs text-warning-600">{{ __('The operator of this chat can no longer answer it.') }}</p>
    <div class="flex gap-2">
        <x-filament::button wire:click="claim">{{ __('Take over') }}</x-filament::button>
        <x-filament::button color="gray" wire:click="close" wire:confirm="{{ __('Close this chat?') }}">{{ __('Close chat') }}</x-filament::button>
    </div>
    @elseif ($chat->status === \App\Models\LiveChat::ACTIVE && $canReply && $chat->assigned_to === $me?->id)
    <form wire:submit="send" class="flex flex-col gap-1">
        <div class="flex items-end gap-2">
            <textarea wire:model="body" rows="2" maxlength="{{ \App\Support\ChatText::MAX }}"
                x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); $wire.send() }"
                class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900" placeholder="{{ __('Write a message…') }}"></textarea>
            <x-filament::button type="submit">{{ __('Send') }}</x-filament::button>
        </div>
        <div><x-filament::button size="xs" color="gray" wire:click="close" wire:confirm="{{ __('Close this chat?') }}">{{ __('Close chat') }}</x-filament::button></div>
    </form>
    @elseif ($chat->isOpen())
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Only the operator who took this chat writes in it.') }}</p>
    @endif
    @endif
</div>
