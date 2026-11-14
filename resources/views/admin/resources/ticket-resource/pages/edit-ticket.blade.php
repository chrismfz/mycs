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
                    {{-- Each message a card (owner 2026-11-13): who and by what in its header, the full time beside «ago»;
                         a mail's From, the server our server took it from and our server's verdict under it, for staff. --}}
                    <div
                        @class([
                            'w-full max-w-[85%] overflow-hidden rounded-xl border bg-white dark:bg-gray-900',
                            $message->isFromStaff() ? 'mr-auto' : 'ml-auto',
                            // A note stands apart from what the customer sees, not only by its badge.
                            'border-warning-300 dark:border-warning-600' => $message->is_internal,
                            'border-gray-200 dark:border-white/10' => !$message->is_internal,
                        ])
                        wire:key="ticket-message-{{ $message->id }}"
                        data-ticket-message="{{ $message->id }}"
                        @if ($loop->last) x-data x-init="$el.scrollIntoView()" @endif>
                        <div @class([
                            'flex items-start justify-between gap-3 border-b px-4 py-2.5',
                            'border-warning-200 bg-warning-50 dark:border-warning-700 dark:bg-warning-950/40' => $message->is_internal,
                            'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => !$message->is_internal,
                        ])>
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($message->author())
                                    <x-filament-panels::avatar.user :user="$message->author()" class="size-7 shrink-0 rounded-full block" />
                                @endif
                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                    @if ($message->isFromStaff())
                                        <span class="font-semibold">{{ $message->staff?->name ?? __('Staff') }}</span>
                                        <x-filament::badge color="gray" size="sm">{{ __('Staff') }}</x-filament::badge>
                                    @elseif ($message->user)
                                        <a class="font-semibold hover:underline" href="{{ App\Admin\Resources\UserResource::getUrl('edit', ['record' => $message->user]) }}">{{ $message->user->name }}</a>
                                        <x-filament::badge color="info" size="sm">{{ __('Client') }}</x-filament::badge>
                                    @elseif ($message->sender_email)
                                        {{-- An address staff copied on the ticket (docs/tickets.md §6λ). --}}
                                        <span class="font-semibold">{{ $message->sender_email }}</span>
                                        <x-filament::badge color="gray" size="sm">CC</x-filament::badge>
                                    @elseif ($this->record->isGuest())
                                        {{-- A guest's, by the form, a mail or their link: no login to name (docs/tickets.md §3 Δ). --}}
                                        <span class="font-semibold">{{ $this->record->guest_name }}</span>
                                        <x-filament::badge color="gray" size="sm">{{ __('Guest') }}</x-filament::badge>
                                    @else
                                        <span class="font-semibold text-gray-500">{{ $this->record->client?->name ?? __('Customer') }}</span>
                                        <x-filament::badge color="info" size="sm">{{ __('Client') }}</x-filament::badge>
                                    @endif
                                    @if ($message->is_internal)
                                        <x-filament::badge color="warning" size="sm" icon="heroicon-m-lock-closed">{{ __('Internal note') }}</x-filament::badge>
                                    @endif
                                    @php
                                        // By what it came: a customer's with no channel of its own wrote in the client area.
                                        [$channel, $channelIcon] = match (true) {
                                            $message->via === 'email' => [__('Email'), 'heroicon-m-envelope'],
                                            $message->via === 'form' => [__('Contact form'), 'heroicon-m-clipboard-document-list'],
                                            $message->via === 'link' => [__('Guest page'), 'heroicon-m-link'],
                                            $message->via === 'whmcs' => ['WHMCS', 'heroicon-m-archive-box'],
                                            $message->via === 'api' => ['API', 'heroicon-m-code-bracket'],
                                            $message->via === \App\Mcp\Tools\TicketNoteTool::VIA => ['MCP', 'heroicon-m-cpu-chip'],
                                            !$message->isFromStaff() && !$message->is_internal => [__('Client area'), 'heroicon-m-user-circle'],
                                            default => [null, null],
                                        };
                                    @endphp
                                    @if ($channel)
                                        <x-filament::badge color="gray" size="sm" :icon="$channelIcon">{{ $channel }}</x-filament::badge>
                                    @endif
                                </div>
                            </div>
                            <div class="flex shrink-0 items-start gap-1">
                                <div class="text-right text-xs leading-tight text-gray-500 dark:text-gray-400">
                                    <div class="text-sm text-gray-700 dark:text-gray-200">{{ $message->created_at->format('d/m/Y H:i') }}</div>
                                    <div>{{ $message->created_at->diffForHumans() }}</div>
                                </div>
                                @can('delete', $message)
                                    <x-filament::dropdown placement="bottom-end">
                                        <x-slot name="trigger">
                                            <x-filament::icon-button icon="heroicon-m-ellipsis-vertical" color="gray" size="sm" :label="__('Actions')" />
                                        </x-slot>
                                        <x-filament::dropdown.list>
                                            <x-filament::dropdown.list.item icon="heroicon-m-trash" color="danger"
                                                wire:click="deleteMessage({{ $message->id }})"
                                                wire:confirm="{{ __('Delete this message?') }}">
                                                {{ __('Delete') }}
                                            </x-filament::dropdown.list.item>
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>
                                @endcan
                            </div>
                        </div>
                        @php($mail = is_array($message->mail) ? $message->mail : null)
                        @if ($mail !== null || ($message->via === \App\Models\TicketMessage::VIA_EMAIL && filled($message->body_original)))
                            <dl class="grid grid-cols-[5.5rem_1fr] gap-x-3 gap-y-0.5 border-b border-gray-200 px-4 py-2 text-xs text-gray-600 dark:border-white/10 dark:text-gray-300" data-ticket-mail>
                                @if ($mail !== null)
                                    <dt class="text-gray-400">{{ __('From') }}</dt><dd class="break-all">{{ $mail['from'] ?? '—' }}</dd>
                                    <dt class="text-gray-400">{{ __('Server') }}</dt><dd class="break-all">{{ $mail['server'] ?? '—' }}</dd>
                                    <dt class="text-gray-400">{{ __('Check') }}</dt>
                                    <dd>
                                        @if (($mail['vouched'] ?? null) === true)
                                            <span class="text-success-600 dark:text-success-400">✓ {{ __('Verified') }}</span>
                                        @elseif (($mail['vouched'] ?? null) === null)
                                            <span class="text-gray-500">{{ __('Not checked') }}</span>
                                        @else
                                            <span class="text-warning-600 dark:text-warning-400">⚠ {{ __('Not verified: it may be forged') }}</span>
                                        @endif
                                        @if (filled($mail['reason'] ?? null))
                                            <span class="text-gray-400">· {{ str_replace(['_', '+'], [' ', ', '], (string) $mail['reason']) }}</span>
                                        @endif
                                    </dd>
                                @endif
                                <dt></dt>
                                <dd>
                                    {{-- The whole mail is fetched when asked for, not with every refresh of the thread (review 3). --}}
                                    <button type="button" wire:click="showMail({{ $message->id }})" class="cursor-pointer text-primary-600 hover:underline dark:text-primary-400">{{ $mail !== null ? __('The whole mail and its headers') : __('By email: the whole mail') }}</button>
                                </dd>
                            </dl>
                        @endif
                        <div class="prose max-w-full overflow-x-auto break-words px-4 py-3 dark:prose-invert">
                            @if ($message->via === \App\Mcp\Tools\TicketNoteTool::VIA)
                                {{-- An agent's note: as written, links not clickable, as a drafted reply (review of #860, ADV-860-4). --}}
                                <pre class="whitespace-pre-wrap break-words text-sm font-sans">{{ $message->message }}</pre>
                            @else
                                {!! \App\Support\TicketText::html((string) $message->message) !!}
                            @endif
                        </div>
                        @if ($message->attachments->isNotEmpty())
                            <div class="flex flex-wrap gap-2 px-4 pb-3">
                                @foreach ($message->attachments as $attachment)
                                    <a href="{{ route('filament.admin.ticket-attachments.show', $attachment) }}" class="flex w-fit items-center rounded-md bg-gray-100 p-1 text-sm dark:bg-gray-800">
                                        @if ($attachment->canPreview())
                                            <img src="{{ route('filament.admin.ticket-attachments.show', $attachment) }}" alt="{{ $attachment->filename }}" class="max-w-full">
                                        @else
                                            <x-ri-attachment-2 class="mr-1 inline-block size-4" />
                                            {{ $attachment->filename }}
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <x-filament::modal id="ticket-mail" width="4xl" :heading="__('The whole mail')">
                @if ($shownMail = $this->shownMail())
                    @if (filled($shownMail->mail['headers'] ?? null))
                        <p class="text-sm font-semibold">{{ __('Headers') }}</p>
                        <pre class="max-h-[40vh] overflow-auto whitespace-pre-wrap break-all rounded bg-gray-50 p-2 text-xs dark:bg-white/5" data-ticket-mail-headers>{{ $shownMail->mail['headers'] }}</pre>
                    @endif
                    @if (filled($shownMail->body_original))
                        <pre class="whitespace-pre-wrap text-sm">{{ $shownMail->body_original }}</pre>
                    @endif
                @endif
            </x-filament::modal>
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
