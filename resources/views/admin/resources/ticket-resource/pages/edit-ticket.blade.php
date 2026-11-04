<x-filament-panels::page>
    @if ($this->record->isMerged() && $this->record->mergedInto)
        {{-- Merged by staff (docs/tickets.md §6κ): its thread goes on in the other. --}}
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-600 dark:bg-warning-950/40">
            {{ __('Merged into ticket') }}
            <a class="font-semibold underline" href="{{ App\Admin\Resources\TicketResource::getUrl('edit', ['record' => $this->record->mergedInto]) }}">#{{ $this->record->mergedInto->reference }}</a>.
        </div>
    @endif
    {{-- The page's heartbeat (docs/tickets.md §6ν), which refreshes the thread too: every 10″ in front, every 30″ behind
         another tab. It says whether the tab is in front and someone moved lately, and whether the reply box changed;
         the page's row goes as soon as it is closed or left (the admin is an SPA: Alpine's destroy on navigation). --}}
    <div class="md:grid grid-cols-4 flex flex-col gap-4"
        x-data="{
            typed: false,
            active: Date.now(),
            last: Date.now(),
            timer: null,
            gone: false,
            init() {
                this.timer = setInterval(() => {
                    // By the clock, not by ticks: a browser slows the timers of a tab behind another (review).
                    if (!document.hidden || Date.now() - this.last >= {{ \App\Support\Tickets\TicketPresence::BACKGROUND_BEAT * 1000 - 1000 }}) this.beat();
                }, {{ \App\Support\Tickets\TicketPresence::BEAT * 1000 }});
            },
            beat() {
                if (this.gone) return;
                const viewing = !document.hidden && Date.now() - this.active < 300000;
                const typed = this.typed;
                this.typed = false;
                this.last = Date.now();
                $wire.presenceBeat(viewing, typed);
            },
            leave() {
                if (this.gone) return;
                this.gone = true;
                clearInterval(this.timer);
                const body = new FormData();
                body.append('_token', @js(csrf_token()));
                body.append('page', @js($this->getId()));
                navigator.sendBeacon(@js(route('filament.admin.ticket-presence.leave')), body);
            },
            destroy() { this.leave(); },
        }"
        x-on:mousemove.window.throttle.5000ms="active = Date.now()"
        x-on:keydown.window="active = Date.now()"
        x-on:scroll.window.throttle.5000ms="active = Date.now()"
        x-on:visibilitychange.document="if (!document.hidden) { active = Date.now(); beat(); }"
        x-on:pagehide.window="leave()"
        x-on:pageshow.window="if ($event.persisted) { gone = false; init(); beat(); }">
        <div class="md:col-span-3">
            <div class="flex flex-col gap-4 max-h-[60vh] overflow-y-auto pr-4">
                @foreach ($this->record->messages()->with(['user', 'staff', 'attachments'])->get() as $index => $message)
                    <div
                        @class([
                            'border p-4 rounded-lg w-full max-w-[80%]',
                            $message->isFromStaff() ? 'mr-auto' : 'ml-auto',
                            // A note stands apart from what the customer sees, not only by its badge.
                            'border-warning-300 bg-warning-50 dark:border-warning-600 dark:bg-warning-950/40' => $message->is_internal,
                            'border-gray-100 dark:border-gray-600' => !$message->is_internal,
                        ])
                        @if ($loop->last) x-data x-init="$el.scrollIntoView()" @endif>
                        <div class="flex justify-between gap-4">
                            <div class="flex items-center gap-4">
                                @if ($message->author())
                                    <x-filament-panels::avatar.user
                                        :user="$message->author()"
                                        class="size-7 shrink-0 rounded-full block"
                                    />
                                @endif
                                <div class="flex flex-col">
                                    <div class="flex gap-3 items-center">

                                        @if ($message->isFromStaff())
                                            <span class="text-lg font-semibold">{{ $message->staff?->name ?? __('Staff') }}</span>
                                        @elseif ($message->user)
                                            <a class="text-lg font-semibold hover:underline"
                                            href="{{ App\Admin\Resources\UserResource::getUrl('edit', ['record' => $message->user]) }}">
                                            {{ $message->user->name }}
                                        </a>
                                        @elseif ($message->sender_email)
                                            {{-- An address staff copied on the ticket (docs/tickets.md §6λ). --}}
                                            <span class="text-lg font-semibold">{{ $message->sender_email }}</span>
                                            <x-filament::badge color="gray" size="sm">CC</x-filament::badge>
                                        @elseif ($this->record->isGuest())
                                            {{-- A guest's, by the form, a mail or their link: no login to name (docs/tickets.md §3 Δ). --}}
                                            <span class="text-lg font-semibold">{{ $this->record->guest_name }}</span>
                                            <x-filament::badge color="gray" size="sm">{{ __('Guest') }}</x-filament::badge>
                                        @else
                                            <span class="text-lg font-semibold text-gray-500">{{ $this->record->client?->name ?? __('Customer') }}</span>
                                        @endif
                                        @if ($message->is_internal)
                                            <x-filament::badge color="warning" size="sm">{{ __('Internal note') }}</x-filament::badge>
                                        @endif
                                        @if (in_array($message->via, ['form', 'link', 'email', 'whmcs'], true))
                                            <x-filament::badge color="gray" size="sm">{{ match ($message->via) {
                                                'form' => __('Contact form'),
                                                'link' => __('Guest page'),
                                                'email' => __('Email'),
                                                'whmcs' => 'WHMCS',
                                            } }}</x-filament::badge>
                                        @endif
                                </div>
                                <div class="flex gap-1">
                                    <p class="text-sm text-gray-500">
                                        {{ $message->created_at->diffForHumans() }}
                                    </p>
                                    @if($message->via === \App\Models\TicketMessage::VIA_EMAIL && filled($message->body_original))
                                        <x-filament::modal width="4xl">
                                            <x-slot name="trigger">
                                                <span class="text-sm text-gray-500 cursor-pointer hover:underline">
                                                    — {{ __('By email: the whole mail') }}
                                                </span>
                                            </x-slot>
                                            <pre class="whitespace-pre-wrap text-sm">{{ $message->body_original }}</pre>
                                        </x-filament::modal>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div>
                            @can('delete', $message)
                            <button wire:click="deleteMessage({{ $message->id }})"
                                class="dark:text-danger-300 text-danger-600 p-0">
                                Delete
                            </button>
                            @endcan
                        </div>
                    </div>
                    <div class="mt-2 prose dark:prose-invert break-words overflow-x-auto max-w-full">
                        {!! \App\Support\TicketText::html((string) $message->message) !!}
                    </div>
                    <div class="flex flex-wrap gap-x-2">
                    @foreach($message->attachments as $attachment)
                        <div class="mt-2">
                            <a href="{{ route('filament.admin.ticket-attachments.show', $attachment) }}" class="text-sm rounded-md bg-gray-100 flex items-center dark:bg-gray-800 p-1 w-fit">
                                @if($attachment->canPreview())
                                    <img src="{{ route('filament.admin.ticket-attachments.show', $attachment) }}" alt="{{ $attachment->filename }}" class="max-w-full">
                                @else
                                    <x-ri-attachment-2 class="inline-block mr-1 size-4" />
                                    {{ $attachment->filename }}
                                @endif
                            </a>
                        </div>
                    @endforeach
                    </div>
                </div>
                @endforeach
            </div>
            @foreach ($drafts as $draft)
                {{-- An MCP agent's draft (Proposals::TICKET_REPLY): the text as written, never sent by itself. --}}
                <x-filament::section class="mt-4" wire:key="draft-{{ $draft->id }}" :heading="__('Drafted reply (MCP)')"
                    :description="__('By :who, :when. Read it all, links included, before you use it: it may carry what a customer wrote.', ['who' => $draft->proposer?->name ?? '—', 'when' => $draft->created_at?->diffForHumans()])">
                    <pre class="whitespace-pre-wrap break-words text-sm font-sans">{{ $draft->payload['message'] ?? '' }}</pre>
                    <div class="mt-3 flex flex-wrap gap-2 justify-end">
                        <x-filament::button color="gray" outlined size="sm" wire:click="discardDraft({{ $draft->id }})">{{ __('Discard') }}</x-filament::button>
                        <x-filament::button size="sm" wire:click="useDraft({{ $draft->id }})">{{ __('Use the draft') }}</x-filament::button>
                    </div>
                </x-filament::section>
            @endforeach
            @if ($presence !== [])
                {{-- Who else is on it now, from pages that beat (docs/tickets.md §6ν): it never outlives them. --}}
                <div class="mt-4 flex flex-wrap gap-2" data-ticket-presence>
                    @foreach ($presence as $member)
                        <x-filament::badge
                            :color="match ($member['state']) { 'writing' => 'warning', 'draft' => 'gray', default => 'info' }"
                            :icon="match ($member['state']) { 'writing' => 'heroicon-m-pencil-square', 'draft' => 'heroicon-m-document-text', default => 'heroicon-m-eye' }">
                            @switch ($member['state'])
                                @case ('writing')
                                    {{ __(':name is writing a reply', ['name' => $member['name']]) }}
                                    @break
                                @case ('draft')
                                    {{ __(':name has an unsent reply, last typed :time', ['name' => $member['name'], 'time' => $member['typed_at']?->diffForHumans() ?? '—']) }}
                                    @break
                                @default
                                    {{ __(':name is viewing this ticket', ['name' => $member['name']]) }}
                            @endswitch
                        </x-filament::badge>
                    @endforeach
                </div>
            @endif
            @if ($newer->isNotEmpty())
                {{-- Another's message came in while this member wrote: the first «Send» stops (docs/tickets.md §6ν). --}}
                <div class="mt-4 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm dark:border-danger-600 dark:bg-danger-950/40" data-ticket-newer>
                    <p class="font-semibold">{{ __('New in this ticket since you started writing:') }}</p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($newer as $message)
                            <li>
                                {{ $message->staff?->name ?? $message->user?->name ?? $message->sender_email ?? ($message->is_internal ? __('Staff') : null) ?? ($this->record->isGuest() ? $this->record->guest_name : null) ?? __('Customer') }}
                                · {{ $message->created_at?->diffForHumans() }}@if ($message->is_internal) · {{ __('Internal note') }}@endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-1">{{ __('Read it before you send yours: Send will stop once.') }}</p>
                </div>
            @endif
            <form wire:submit="send" x-on:input="typed = true">
                <div class="mt-4 mb-4">
                    {{ $this->form }}
                </div>
                <div class="flex flex-wrap gap-2 justify-end">
                    @if ($this->linkToClient->isVisible())
                        {{ $this->linkToClient }}
                        {{ $this->revokeGuestLinks }}
                    @endif
                    @if ($this->closeTicket->isVisible())
                        {{ $this->closeTicket }}
                    @endif
                    
                    <x-filament::button color="warning" outlined icon="heroicon-o-lock-closed" wire:click="note" wire:target="note">
                        {{ __('Add internal note') }}
                    </x-filament::button>
                    <x-filament::button type="submit" target="send">
                        Send Message
                    </x-filament::button>
                </div>
            </form>
        </div>

        <div class="md:order-last order-first w-full col-span-3 sm:col-auto">{{ $this->infolist }}</div>

    </div>
</x-filament-panels::page>
