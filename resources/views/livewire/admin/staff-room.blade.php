<div wire:poll.5s.visible class="flex h-full flex-col gap-3">
    @if ($canView)
    <div class="flex max-h-[60vh] min-h-64 flex-col gap-3 overflow-y-auto rounded-lg border border-gray-200 p-3 dark:border-white/10"
        x-data x-init="$el.scrollTop = $el.scrollHeight" x-on:livewire:update.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
        @forelse ($messages as $message)
        <div @class(['flex flex-col', 'items-end' => $message->staff_id === $me?->id, 'items-start' => $message->staff_id !== $me?->id])>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ $message->staff?->name }} · {{ $message->created_at?->format('d/m H:i') }}
            </div>
            <div @class([
                'mt-0.5 max-w-[85%] rounded-lg px-3 py-2 text-sm break-words',
                'bg-primary-50 text-gray-900 dark:bg-primary-500/10 dark:text-gray-100' => $message->staff_id === $me?->id,
                'bg-gray-100 text-gray-900 dark:bg-white/5 dark:text-gray-100' => $message->staff_id !== $me?->id,
            ])>{!! \App\Support\ChatText::render($message->body) !!}</div>
        </div>
        @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No messages yet.') }}</p>
        @endforelse
    </div>

    @if ($canPost)
    <form wire:submit="send" class="flex flex-col gap-1">
        <div class="flex items-end gap-2">
            <textarea wire:model="body" rows="2" maxlength="{{ \App\Support\ChatText::MAX }}"
                x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.send() }"
                class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900"
                placeholder="{{ __('Write a message…') }}"></textarea>
            <x-filament::button type="submit">{{ __('Send') }}</x-filament::button>
        </div>
        @error('body') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('A customer matter goes in the note of its chat or ticket, not here.') }}</p>
    </form>
    @endif
    @endif
</div>
