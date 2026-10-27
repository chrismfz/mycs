{{--
    The «Chat» bubble of the client area (docs/livechat.md §7, step 3α). Hidden while no one is available, unless the
    account's chat is open. It remembers in this browser whether its panel was open, so the conversation stays in front
    across pages; it polls only while open.
--}}
<div class="fixed bottom-4 right-4 z-40 flex flex-col items-end gap-2"
    x-data="{
        init() { try { if (localStorage.getItem('mycs-chat-open') === '1' && !$wire.open) $wire.toggle() } catch (e) {} },
        remember(open) { try { localStorage.setItem('mycs-chat-open', open ? '1' : '0') } catch (e) {} },
    }">
    @if ($may)
    @if ($open)
    <div @if ($chat) wire:poll.4s.visible @endif
        class="flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3 rounded-xl border border-neutral bg-background p-4 shadow-xl">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">{{ __('Live chat') }}</h2>
            <button type="button" class="text-xs opacity-70 hover:opacity-100" wire:click="toggle" x-on:click="remember(false)">{{ __('Close') }}</button>
        </div>

        @if ($chat)
        <div class="flex max-h-80 min-h-40 flex-col gap-2 overflow-y-auto text-sm"
            x-data x-init="$el.scrollTop = $el.scrollHeight"
            x-on:livewire:navigated.document="$el.scrollTop = $el.scrollHeight">
            @foreach ($messages as $message)
            @php
                $customer = $message->author_type === \App\Models\LiveChatMessage::CLIENT;
                // «You» only for this login's own messages; another login of the account by its first name (review of #475).
                $mine = $customer && $message->user_id === auth('web')->id();
            @endphp
            <div @class(['flex flex-col', 'items-end' => $customer, 'items-start' => !$customer])>
                <div class="text-xs opacity-60">
                    {{ $mine ? __('You') : ($customer ? \App\Support\LiveChats::loginName($message->user) : \App\Support\LiveChats::operatorName($message->staff)) }} · {{ $message->created_at?->format('H:i') }}
                </div>
                <div @class(['mt-0.5 max-w-[85%] rounded-lg px-3 py-2 break-words', 'bg-primary/15' => $customer, 'bg-background-secondary' => !$customer])>{!! \App\Support\ChatText::render($message->body) !!}</div>
            </div>
            @endforeach
        </div>
        @if ($chat->status === \App\Models\LiveChat::WAITING)
        <p class="text-xs opacity-70">{{ __('Someone from our team will be with you shortly.') }}</p>
        @endif
        <form wire:submit="send" class="flex flex-col gap-2">
            <textarea wire:model="body" rows="2" maxlength="{{ \App\Support\ChatText::MAX }}"
                x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); $wire.send() }"
                class="block w-full rounded-lg border border-neutral bg-background-secondary p-2 text-sm" placeholder="{{ __('Write a message…') }}"></textarea>
            @error('body') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
            <div class="flex items-center justify-between">
                <button type="button" class="text-xs opacity-70 hover:opacity-100" wire:click="end" wire:confirm="{{ __('End this chat?') }}">{{ __('End chat') }}</button>
                <x-button.primary type="submit" class="!w-auto">{{ __('Send') }}</x-button.primary>
            </div>
        </form>
        @elseif ($departments->isEmpty())
        <p class="text-sm opacity-80">{{ __('Live chat is not available right now.') }}</p>
        @if ($ticketsUrl) <a href="{{ $ticketsUrl }}" wire:navigate class="text-sm underline">{{ __('Open a ticket') }}</a> @endif
        @else
        <form wire:submit="start" class="flex flex-col gap-2">
            @if ($departments->count() > 1)
            <select wire:model.live="department" class="block w-full rounded-lg border border-neutral bg-background-secondary p-2 text-sm">
                @foreach ($departments as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
            @endif
            @if ($available)
            <textarea wire:model="body" rows="3" maxlength="{{ \App\Support\ChatText::MAX }}"
                class="block w-full rounded-lg border border-neutral bg-background-secondary p-2 text-sm" placeholder="{{ __('How can we help?') }}"></textarea>
            @error('body') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
            <x-button.primary type="submit">{{ __('Start chat') }}</x-button.primary>
            @else
            <p class="text-sm opacity-80">{{ __('No one is available in this department right now.') }}</p>
            @if ($ticketsUrl) <a href="{{ $ticketsUrl }}" wire:navigate class="text-sm underline">{{ __('Open a ticket') }}</a> @endif
            @endif
        </form>
        @endif
    </div>
    @endif
    <button type="button" wire:click="toggle" x-on:click="remember(!$wire.open)"
        class="rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white shadow-lg hover:opacity-90">
        {{ __('Chat') }}
    </button>
    @endif
</div>
