<?php

namespace App\Livewire\Admin;

use App\Admin\Resources\TicketResource;
use App\Models\Staff;
use App\Models\Ticket;
use App\Support\AdminBadge;
use Illuminate\View\View;
use Livewire\Attributes\Defer;
use Livewire\Component;

/**
 * On every admin page, for staff who may open the tickets (owner 2026-11-12): every 45 seconds the count of tickets
 * waiting on us, the same cached count as the menu's badge (AdminBadge, no extra load on the database), so the badge
 * in the sidebar stays live; and when a new ticket arrives, a small notification in the corner, «New ticket #…».
 * A customer's reply only moves the count; the notification is for a new ticket.
 * Deferred: it counts in a request of its own right after the page, never in the page's (each queue is counted once per page,
 * AdminSidebarReviewTest).
 */
#[Defer]
class TicketNotifier extends Component
{
    public function placeholder(): string
    {
        return '<div hidden class="mycs-ticket-notifier"></div>';
    }

    public function render(): View
    {
        $staff = auth('staff')->user();
        $allowed = $staff instanceof Staff && TicketResource::canAccess();
        $count = $allowed ? AdminBadge::count('tickets.awaiting', fn () => Ticket::query()->whereIn('status', Ticket::AWAITING_STAFF)->count()) : 0;
        // The newest ticket still waiting on us: a new ticket raises it, a reply to an older one does not.
        $latest = $allowed ? AdminBadge::count('tickets.latest', fn () => (int) Ticket::query()->whereIn('status', Ticket::AWAITING_STAFF)->max('id')) : 0;
        $ticket = $latest > 0 ? Ticket::query()->find($latest) : null;

        return view('livewire.admin.ticket-notifier', [
            'staffId' => $staff instanceof Staff ? $staff->id : 0,
            'count' => $count,
            'latest' => $ticket !== null ? $ticket->id : 0,
            // Escaped here as well as by Blade: the notice draws its title and body as HTML, and a subject is the
            // sender's own text (review of #815, REV-815-1).
            'title' => $ticket !== null ? e(__('New ticket') . ' #' . $ticket->reference) : '',
            'subject' => $ticket !== null ? e(mb_substr((string) $ticket->subject, 0, 120)) : '',
            'url' => $ticket !== null ? TicketResource::getUrl('edit', ['record' => $ticket]) : '',
            'index' => TicketResource::getUrl('index'),
        ]);
    }
}
