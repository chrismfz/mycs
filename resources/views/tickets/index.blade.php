<div class="container mt-14 space-y-4">
    <div class="flex flex-row justify-between">
        <x-navigation.breadcrumb />
        <x-navigation.link :href="route('tickets.create')" class="flex items-center gap-2">
            <x-ri-add-line class="size-5" />
            <span>{{ __('ticket.create_ticket') }}</span>
        </x-navigation.link>
    </div>
    @forelse ($tickets as $ticket)
    <a href="{{ route('tickets.show', $ticket) }}" wire:navigate>
        <div class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg mb-4">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-3">
                    <div class="bg-secondary/10 p-2 rounded-lg">
                        <x-ri-ticket-line class="size-5 text-secondary" />
                    </div>
                    <span class="font-medium">#{{ $ticket->reference }} - {{ $ticket->subject }}</span>
                </div>
                <x-ticket.status :status="$ticket->status" />
            </div>
            <p class="text-base text-sm">
                {{ __('ticket.last_activity') }}
                {{ $ticket->publicMessages()->orderBy('created_at', 'desc')->first()?->created_at->diffForHumans() }}
                {{ $ticket->department ? ' - ' . $ticket->department->name : '' }}
            </p>
        </div>
    </a>
    @empty
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg">
        <p class="text-base text-sm">{{ __('ticket.no_tickets') }}</p>
    </div>
    @endforelse

    {{ $tickets->links() }}
</div>