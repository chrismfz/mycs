<div class="space-y-4">
    @foreach ($tickets as $ticket)
    <a href="{{ route('tickets.show', $ticket) }}" wire:navigate>
        <div class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg mb-4">
        <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-3">
            <div class="bg-secondary/10 p-2 rounded-lg">
                <x-ri-ticket-line class="size-5 text-secondary" />
            </div>
            <span class="font-medium">#{{ $ticket->id }} - {{ $ticket->subject }}</span>
            </div>
            <x-ticket.status :status="$ticket->status" />
        </div>
        <p class="text-base text-sm">
            {{ __('ticket.last_activity') }}
            {{ $ticket->messages()->orderBy('created_at', 'desc')->first()?->created_at->diffForHumans() }}
            {{ $ticket->department ? ' - ' . $ticket->department : '' }}
        </p>
        </div>
    </a>
    @endforeach
</div>
