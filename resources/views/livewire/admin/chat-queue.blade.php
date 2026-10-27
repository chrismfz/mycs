<div wire:poll.5s.visible class="flex flex-col gap-3">
    @if ($operator)
    @error('queue') <p class="text-xs text-warning-600">{{ $message }}</p> @enderror
    <div>
        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Waiting') }}</h3>
        <ul class="flex flex-col gap-1">
            @forelse ($queue as $chat)
            <li class="flex items-center gap-2 text-sm">
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium text-gray-800 dark:text-gray-100">{{ $chat->client?->name ?? __('Customer') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $chat->department?->name }} · {{ $chat->created_at?->diffForHumans(short: true) }}</span>
                </span>
                <x-filament::button size="xs" wire:click="claim({{ (int) $chat->id }})">{{ __('Take') }}</x-filament::button>
            </li>
            @empty
            <li class="text-xs text-gray-500 dark:text-gray-400">{{ __('No one is waiting.') }}</li>
            @endforelse
        </ul>
    </div>
    @if ($mine->isNotEmpty())
    <div>
        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('My chats') }}</h3>
        <ul class="flex flex-col gap-1">
            @foreach ($mine as $chat)
            <li>
                <button type="button" wire:click="$dispatch('customer-chat-open', { chat: {{ (int) $chat->id }} })"
                    class="flex w-full flex-col rounded-lg px-1 py-1 text-start text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                    <span class="truncate font-medium text-gray-800 dark:text-gray-100">{{ $chat->client?->name ?? __('Customer') }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $chat->department?->name }}</span>
                </button>
            </li>
            @endforeach
        </ul>
    </div>
    @endif
    @endif
</div>
