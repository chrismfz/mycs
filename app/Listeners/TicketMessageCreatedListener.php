<?php

namespace App\Listeners;

use App\Events\TicketMessage\Created;
use App\Models\Ticket;
use App\Support\Tickets\TicketMailer;

class TicketMessageCreatedListener
{
    /**
     * Who wrote it moves the ticket (docs/tickets.md §5): staff's reply → answered, the customer's (or guest's) → back
     * with us; an internal note moves nothing and is never sent.
     */
    public function handle(Created $event): void
    {
        $message = $event->ticketMessage;
        $ticket = $message->ticket;
        if ($message->is_internal) {
            return;
        }
        $ticket->update(['status' => $ticket->statusAfterReply($message->isFromStaff())]);
        if ($message->isFromStaff()) {
            // To the owner, by mail with a Message-ID of ours (A3): the account's owner, or the guest's address.
            app(TicketMailer::class)->reply($message);

            return;
        }
        app(TicketMailer::class)->notifyStaff($message, new: $ticket->messages()->count() === 1);
    }
}
