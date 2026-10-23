<div wire:poll.5s.visible class="flex h-full flex-col gap-3">
    @if ($canView)
    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
        @if ($peer)
        {{ __('Private with :name', ['name' => $peer->name]) }}
        <span class="ms-2 text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('Only the two of you read this.') }}</span>
        @else
        {{ __('Staff room') }}
        @endif
    </h3>
    {{-- Follows new messages while the reader is at the bottom; marks them seen only while the tab is in front. --}}
    <div class="flex max-h-[60vh] min-h-64 flex-col gap-3 overflow-y-auto rounded-lg border border-gray-200 p-3 dark:border-white/10"
        wire:key="staff-thread-{{ $thread->id }}"
        data-last-id="{{ (int) $messages->last()?->id }}"
        x-data="{
            atBottom: true,
            told: 0,
            seen() {
                const id = Number(this.$el.dataset.lastId);
                if (document.visibilityState === 'visible' && id > this.told) { this.told = id; $wire.markSeen(id) }
            },
        }"
        x-init="
            $el.scrollTop = $el.scrollHeight; seen();
            $el.addEventListener('scroll', () => atBottom = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 40);
            new MutationObserver(() => { if (atBottom) $el.scrollTop = $el.scrollHeight; seen() }).observe($el, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-last-id'] });
        "
        x-on:visibilitychange.document="seen()">
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
                x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); $wire.send() }"
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
