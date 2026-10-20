<?php

namespace App\Listeners;

use App\Models\TicketMailId;
use App\Models\TicketMessage;
use Illuminate\Mail\Events\MessageSending;
use MyCS\TicketMail\MessageId;
use Symfony\Component\Mime\Address;

/**
 * The headers of a ticket's mail (docs/tickets.md §3 Α, A3): the Message-ID of ours kept for it (TicketMailId, the same on
 * every retry), In-Reply-To and References so the customer's mail program threads it and their answer carries our id,
 * Reply-To the department's address, X-MyCS-Ticket (a mail of ours coming back is a loop), and Auto-Submitted on the
 * automatic «we received it».
 */
class TicketReplyEmailListener
{
    public function handle(MessageSending $event): void
    {
        $id = $event->data['threadMessageId'] ?? null;
        if (!is_string($id) || !MessageId::isOurs($id)) {
            return;
        }
        $key = TicketMailId::query()->where('message_id', $id)->with('ticket.department')->first();
        $ticket = $key?->ticket;
        if ($ticket === null) {
            return;
        }
        $headers = $event->message->getHeaders();
        $headers->remove('Message-ID');
        $headers->addIdHeader('Message-ID', $id);

        // What it answers: the customer's last mail (by email, its own id), else our mail before it.
        $inbound = TicketMessage::query()->where('ticket_id', $ticket->id)->where('via', TicketMessage::VIA_EMAIL)
            ->where('email_message_id', 'not like', 'gen.%')->whereNotNull('email_message_id')->latest('id')->value('email_message_id');
        $inbound = MessageId::normalise($inbound) !== null && str_contains((string) $inbound, '@') ? (string) $inbound : null;
        $ours = TicketMailId::query()->where('ticket_id', $ticket->id)->where('id', '<', $key->id)->latest('id')->limit(10)
            ->pluck('message_id')->reverse()->values()->all();
        $parent = $inbound ?? ($ours !== [] ? $ours[count($ours) - 1] : null);
        $references = array_values(array_unique(array_merge($ours, $inbound !== null ? [$inbound] : [])));
        $headers->remove('In-Reply-To');
        $headers->remove('References');
        if ($parent !== null) {
            $headers->addIdHeader('In-Reply-To', $parent);
        }
        if ($references !== []) {
            $headers->addIdHeader('References', $references);
        }

        $department = $ticket->department;
        if (filled($department?->email)) {
            $event->message->replyTo($department->email);
            // From the department's address when it is of the domain our mail is sent for (its SPF and DKIM).
            if (self::domain((string) config('settings.mail_from_address')) === self::domain($department->email)) {
                $event->message->from(new Address($department->email, (string) config('settings.mail_from_name', config('app.name'))));
            }
        }
        $headers->remove('X-MyCS-Ticket');
        $headers->addTextHeader('X-MyCS-Ticket', (string) $ticket->reference);
        if ($key->kind === TicketMailId::RECEIVED) {
            $headers->remove('Auto-Submitted');
            $headers->addTextHeader('Auto-Submitted', 'auto-replied');
        }
    }

    private static function domain(string $email): ?string
    {
        return str_contains($email, '@') ? strtolower(substr($email, strrpos($email, '@') + 1)) : null;
    }
}
