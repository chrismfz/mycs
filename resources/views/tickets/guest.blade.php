{{-- A guest's ticket by its link (docs/tickets.md §3 Δ): its messages, never staff's notes; an answer in text. --}}
<div class="container mt-14 max-w-4xl">
    <div class="bg-primary-800 p-6 rounded-lg">
        @if ($ticket === null)
            <h1 class="text-2xl font-semibold">{{ __('ticket.guest_link_invalid') }}</h1>
            @if ($expired)
                <p class="mt-2">{{ __('ticket.guest_link_expired') }}</p>
                @if ($resent)
                    <p class="mt-4" role="status">{{ __('ticket.guest_link_resent') }}</p>
                @else
                    <x-button.primary class="mt-4" wire:click="resend" wire:loading.attr="disabled">{{ __('ticket.guest_link_resend') }}</x-button.primary>
                @endif
            @else
                <p class="mt-2">{{ __('ticket.guest_link_gone') }}</p>
            @endif
        @else
            <h1 class="text-2xl font-semibold">{{ __('ticket.title', ['id' => $ticket->reference]) }} · {{ $subject }}</h1>
            <p class="mt-1 text-sm text-base/70">{{ $ticket->department?->name }} · <x-ticket.status :status="$ticket->status" /></p>
            <div class="flex flex-col gap-4 mt-6">
                @foreach ($messages as $message)
                    @if (!\App\Livewire\Tickets\Guest::shown($message, $ticket))
                        <p class="text-sm text-base/60 ml-auto max-w-[85%]">{{ __('ticket.guest_message_hidden') }}</p>
                        @continue
                    @endif
                    <article class="bg-background-secondary border border-neutral p-4 rounded-lg w-full max-w-[85%] {{ $message->isFromStaff() ? 'mr-auto' : 'ml-auto' }}">
                        <header class="flex justify-between gap-4 text-sm">
                            <span class="font-semibold">{{ \App\Livewire\Tickets\Guest::author($message, $ticket) }}</span>
                            <time datetime="{{ $message->created_at->toIso8601String() }}" class="text-base/60">{{ $message->created_at->diffForHumans() }}</time>
                        </header>
                        <div class="prose dark:prose-invert max-w-none mt-2">
                            {!! \App\Support\TicketText::html((string) $message->message) !!}
                        </div>
                        @if ($message->attachments->isNotEmpty() && \App\Livewire\Tickets\Guest::shown($message, $ticket))
                            <ul class="mt-2 text-sm">
                                @foreach ($message->attachments as $attachment)
                                    <li><a class="underline" href="{{ route('tickets.guest.attachment', ['token' => $token, 'attachment' => $attachment->uuid]) }}" rel="nofollow">{{ $attachment->filename }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </article>
                @endforeach
            </div>
            <form wire:submit="reply" class="mt-6 flex flex-col gap-2">
                <x-form.textarea wire:model="message" name="message" :label="__('ticket.reply')" rows="6" maxlength="10000" required />
                <x-button.primary type="submit" class="md:self-end" wire:loading.attr="disabled">{{ __('ticket.contact_send') }}</x-button.primary>
            </form>
        @endif
    </div>
</div>
