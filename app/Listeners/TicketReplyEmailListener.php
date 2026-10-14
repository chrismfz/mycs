<?php

namespace App\Listeners;

use App\Models\TicketMessage;
use Illuminate\Mail\Events\MessageSending;

class TicketReplyEmailListener
{
    /**
     * Handle the event.
     */
    public function handle(MessageSending $event): void
    {
        if (($event->data['templateKey'] ?? null) !== 'new_ticket_message' || !isset($event->data['ticketMessageId'])) {
            return;
        }
        $ticketMessage = TicketMessage::find($event->data['ticketMessageId']);
        if ($ticketMessage !== null) {
            $message = $event->message;
            $host = config('app.url');
            // Only hostname without scheme or link
            $host = parse_url($host, PHP_URL_HOST);

            $ticket = $ticketMessage->ticket;
            // Check if we ever sent a reply to this ticket
            // Second last, because last is the current message
            $previousReply = $ticket->messages()->where('user_id', '!=', $ticket->user_id)->orderBy('id', 'desc')->skip(1)->first();
            $message->getHeaders()->addHeader('Message-ID', $ticketMessage->id . '@' . $host);
            if ($previousReply) {
                $message->getHeaders()->addHeader('In-Reply-To', $previousReply->id . '@' . $host);
            }
            $message->getHeaders()->addHeader('References', $ticket->id . '@' . $host);

            // Update reply to
            if (config('settings.ticket_mail_piping', false)) {
                $message->replyTo(config('settings.ticket_mail_email'));
            }
        }
    }
}
