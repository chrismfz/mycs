<div @if ($chat?->isOpen()) wire:poll.4s.visible @endif class="flex flex-col gap-3">
    @if ($chat === null)
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This chat is not available to you.') }}</p>
    @else
    <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
            @if ($clientUrl)
            <a href="{{ $clientUrl }}" wire:navigate class="hover:underline">{{ $chat->client?->name ?? __('Customer') }}</a>
            @else
            {{ $chat->isVisitor() ? __('Visitor') : ($chat->client?->name ?? __('Customer')) }}
            @endif
        </h3>
        <span class="text-xs text-gray-500 dark:text-gray-400">#{{ (int) $chat->id }}</span>
        <x-filament::badge size="sm" :color="match ($chat->status) { 'waiting' => 'warning', 'active' => 'success', default => 'gray' }">{{ __(ucfirst($chat->status)) }}</x-filament::badge>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $chat->department?->name }}@if ($chat->operator) · {{ $chat->operator->name }}@endif</span>
        <span class="flex-1"></span>
        <x-filament::button size="xs" color="gray" wire:click="$dispatch('customer-chat-open', { chat: null })">{{ __('Back to the staff chat') }}</x-filament::button>
    </div>

    @if ($chat->started_anonymously)
    @include('livewire.admin.partials.visitor-label', ['chat' => $chat])
    @endif

    <div class="flex max-h-[60vh] min-h-64 flex-col lg:h-[60vh] gap-3 overflow-y-auto rounded-lg border border-gray-200 p-3 dark:border-white/10"
        x-data x-init="$el.scrollTop = $el.scrollHeight">
        @foreach ($messages as $message)
        @php
            $visitor = $message->author_type === \App\Models\LiveChatMessage::VISITOR;
            $client = $visitor || $message->author_type === \App\Models\LiveChatMessage::CLIENT;
        @endphp
        @if ($message->author_type === \App\Models\LiveChatMessage::SYSTEM)
        <p class="text-center text-xs text-gray-500 dark:text-gray-400">{{ $message->body }} · {{ $message->created_at?->format('d/m H:i') }}</p>
        @continue
        @endif
        @if ($message->author_type === \App\Models\LiveChatMessage::NOTE)
        {{-- An internal note: the staff only, never the customer (step 3β, F20). --}}
        <div class="flex flex-col items-center">
            <div class="text-xs text-warning-700 dark:text-warning-400">{{ __('Internal note') }} · {{ $message->staff?->name ?? __('Staff') }} · {{ $message->created_at?->format('d/m H:i') }}</div>
            <div class="mt-0.5 w-[85%] rounded-lg border border-dashed border-warning-400 bg-warning-50 px-3 py-2 text-sm break-words text-gray-900 dark:border-warning-500/50 dark:bg-warning-500/10 dark:text-gray-100">{!! \App\Support\ChatText::render($message->body) !!}</div>
        </div>
        @continue
        @endif
        <div @class(['flex flex-col', 'items-start' => $client, 'items-end' => !$client])>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{-- Which login of the account writes (G11), not only the account. --}}
                {{-- A visitor's role from author_type, never from the name they typed (F24). --}}
                {{ $visitor ? __('Visitor') : ($client ? ($message->user?->name ?? __('Customer')) : ($message->staff?->name ?? __('System'))) }} · {{ $message->created_at?->format('d/m H:i') }}
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
                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none dark:border-white/20 dark:bg-gray-900" placeholder="{{ __('Write a message…') }}"></textarea>
            <x-filament::button type="submit">{{ __('Send') }}</x-filament::button>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-filament::button size="xs" color="gray" wire:click="close" wire:confirm="{{ __('Close this chat?') }}">{{ __('Close chat') }}</x-filament::button>
            @if ($chat->isVisitor() && $chat->assigned_to === $me?->id)
            {{-- No customer lookup in a visitor's chat (G6): for the account, they log in. --}}
            <x-filament::button size="xs" color="gray" wire:click="askLogin" :disabled="$chat->login_requested_at !== null">{{ $chat->login_requested_at ? __('Asked to log in') : __('Ask to log in') }}</x-filament::button>
            <x-filament::button size="xs" color="danger" wire:click="block" wire:confirm="{{ __('Block this visitor\'s address for :hours hours and close the chat?', ['hours' => \App\Support\VisitorChatTools::BLOCK_HOURS]) }}">{{ __('Block') }}</x-filament::button>
            @endif
        </div>
    </form>
    @if ($targets['operators'] !== [] || $targets['departments']->isNotEmpty())
    <form wire:submit="transfer" wire:confirm="{{ __('Transfer this chat?') }}" class="flex flex-wrap items-center gap-2">
        <select wire:model="transferTo" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-900">
            <option value="">{{ __('Transfer to…') }}</option>
            @if ($targets['operators'] !== [])
            <optgroup label="{{ __('Operators') }}">
                @foreach ($targets['operators'] as $operator)
                <option value="staff:{{ (int) $operator->id }}">{{ $operator->name }}</option>
                @endforeach
            </optgroup>
            @endif
            @if ($targets['departments']->isNotEmpty())
            <optgroup label="{{ __('Departments') }}">
                @foreach ($targets['departments'] as $department)
                <option value="department:{{ (int) $department->id }}">{{ $department->name }}</option>
                @endforeach
            </optgroup>
            @endif
        </select>
        <x-filament::button type="submit" size="xs" color="gray">{{ __('Transfer') }}</x-filament::button>
    </form>
    @endif
    @error('transfer') <p class="text-sm text-warning-600">{{ $message }}</p> @enderror
    @elseif ($chat->isOpen())
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Only the operator who took this chat writes in it.') }}</p>
    @endif

    @if ($canReply && $chat->isOpen())
    {{-- A form of its own, never a switch on the reply: a note can never go to the customer by a forgotten toggle. --}}
    <form wire:submit="addNote" x-data="{ open: false }" class="flex flex-col gap-1">
        <button type="button" x-show="!open" x-on:click="open = true" class="self-start text-xs text-warning-700 hover:underline dark:text-warning-400">{{ __('Add an internal note') }}</button>
        <div x-show="open" x-cloak class="flex items-end gap-2">
            <textarea wire:model="note" rows="2" maxlength="{{ \App\Support\ChatText::MAX }}"
                class="block w-full rounded-lg border border-dashed border-warning-400 px-3 py-2 bg-warning-50 text-sm dark:border-warning-500/50 dark:bg-warning-500/10" placeholder="{{ __('Internal note, the customer does not see it…') }}"></textarea>
            <x-filament::button type="submit" color="warning">{{ __('Add note') }}</x-filament::button>
        </div>
        @error('note') <p class="text-sm text-warning-600">{{ $message }}</p> @enderror
    </form>
    @endif
    @endif
</div>
