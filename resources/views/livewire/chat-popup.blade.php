{{--
    The popup /chat (docs/livechat.md §4, §7, step 4; the owner's mockup of 2026-11-16, screens α–δ and στ). Everything a
    visitor or the staff wrote goes out through {{ }} or ChatText::render, the chat's one allowed {!! !!} (§3 Α).
--}}
<div class="flex flex-1 flex-col gap-4">
    <h1 class="text-lg font-semibold">{{ __('Live chat') }}</h1>

    @if ($mode === 'impersonating')
    <p class="text-sm opacity-80">{{ __('The chat is not available while you are logged in as a client.') }}</p>

    @elseif ($mode === 'unavailable')
    <p class="text-sm opacity-80">{{ __('Live chat is not available right now.') }}</p>
    @if (! $user)
    <a href="{{ route('contact') }}" class="text-sm underline">{{ __('Send us a message') }}</a>
    @endif

    @elseif ($mode === 'customer')
    {{-- Who the chat will be from, plainly (G4): the login's own name and email, and the account. --}}
    <div class="rounded-lg border border-neutral p-3 text-sm">
        <p>{{ __('Signed in as :name, :email (account :account)', ['name' => $user->name, 'email' => $user->email, 'account' => (string) \App\Support\CurrentClient::model()?->name]) }}</p>
        <div class="mt-1 flex items-center gap-1 text-xs">
            <span class="opacity-70">{{ __('Not you?') }}</span>
            <livewire:auth.logout />
        </div>
    </div>
    @livewire('client.chat-bubble', ['popup' => true], key('mycs-chat-popup'))

    @elseif ($mode === 'choose')
    {{-- α --}}
    <p class="text-sm opacity-80">{{ __('How can we help?') }}</p>
    <button type="button" wire:click="login" class="flex flex-col items-start rounded-lg border border-neutral p-3 text-start hover:bg-background-secondary">
        <span class="font-semibold">{{ __('Sign in') }}</span>
        <span class="text-sm opacity-70">{{ __('For your account: services, invoices, domains.') }}</span>
    </button>
    <button type="button" wire:click="asGuest" class="flex flex-col items-start rounded-lg border border-neutral p-3 text-start hover:bg-background-secondary">
        <span class="font-semibold">{{ __('Continue as a visitor') }}</span>
        <span class="text-sm opacity-70">{{ __('Questions before you buy.') }}</span>
    </button>

    @elseif ($mode === 'form')
    {{-- β --}}
    <form wire:submit="start" class="flex flex-col gap-3">
        @if ($departments->count() > 1)
        <select wire:model="department" class="block w-full rounded-lg border border-neutral bg-background-secondary p-2 text-sm" aria-label="{{ __('Department') }}">
            @foreach ($departments as $option)
            <option value="{{ (int) $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
        @endif
        <input type="text" wire:model="name" maxlength="{{ \App\Support\VisitorChats::NAME_MAX }}" autocomplete="name"
            class="block w-full rounded-lg border border-neutral bg-background-secondary p-2 text-sm" placeholder="{{ __('Name (optional)') }}">
        <input type="email" wire:model="email" maxlength="{{ \App\Support\VisitorChats::EMAIL_MAX }}" autocomplete="email"
            class="block w-full rounded-lg border border-neutral bg-background-secondary p-2 text-sm" placeholder="{{ __('Email (optional)') }}">
        <textarea wire:model="message" rows="4" maxlength="{{ \App\Support\ChatText::MAX }}" required
            class="block w-full rounded-lg border border-neutral bg-background-secondary p-2 text-sm" placeholder="{{ __('How can we help?') }}"></textarea>
        @error('message') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
        <div wire:ignore><x-captcha.altcha :challenge="route('chat.altcha')" /></div>
        @error('captcha') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
        <x-button.primary type="submit" wire:loading.attr="disabled">{{ __('Start chat') }}</x-button.primary>
        <p class="text-xs opacity-70">{{ __('The email is needed only if you want an answer by email later. For your account, sign in instead.') }}</p>
    </form>

    @elseif ($mode === 'ticket')
    {{-- δ: the guest ticket form we already have, the message already in it. --}}
    <p class="text-sm">{{ __('No one is available right now. Leave us a message and we will answer by email.') }}</p>
    @livewire('contact', ['prefill' => $message, 'embedded' => true], key('mycs-chat-contact'))

    @elseif ($mode === 'chat')
    {{-- γ --}}
    <div wire:poll.3s.visible class="flex flex-1 flex-col gap-3">
        <div class="flex max-h-[60vh] min-h-48 flex-col gap-2 overflow-y-auto text-sm" x-data x-init="$el.scrollTop = $el.scrollHeight">
            @foreach ($messages as $line)
            @php $own = $line->author_type === \App\Models\LiveChatMessage::VISITOR; @endphp
            <div @class(['flex flex-col', 'items-end' => $own, 'items-start' => ! $own])>
                <div class="text-xs opacity-60">{{ $own ? __('You') : \App\Support\LiveChats::operatorName($line->staff) }} · {{ $line->created_at?->format('H:i') }}</div>
                <div @class(['mt-0.5 max-w-[85%] rounded-lg px-3 py-2 break-words', 'bg-primary/15' => $own, 'bg-background-secondary' => ! $own])>{!! \App\Support\ChatText::render($line->body) !!}</div>
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
    </div>

    @elseif ($mode === 'ended')
    {{-- στ --}}
    <p class="text-sm">{{ __('The chat has ended.') }}</p>
    <button type="button" class="self-start text-sm underline" wire:click="newChat">{{ __('Start a new chat') }}</button>
    @endif
</div>
