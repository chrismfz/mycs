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
        // Never a reason for the mail not to go: a header we cannot write is left out, and logged.
        try {
            $this->headers($event);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function headers(MessageSending $event): void
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
        // The customer's id is theirs: in the headers only when it is one a mail may carry (RFC 5322 msg-id).
        $inbound = self::valid($inbound) ? (string) $inbound : null;
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

        // Replies go to the department's mailbox; the From stays ours (a submission server refuses another sender).
        $department = $ticket->department;
        if (filled($department?->email) && filter_var($department->email, FILTER_VALIDATE_EMAIL)) {
            $event->message->replyTo($department->email);
        }
        $headers->remove('X-MyCS-Ticket');
        $headers->addTextHeader('X-MyCS-Ticket', (string) $ticket->reference);
        if ($key->kind === TicketMailId::RECEIVED) {
            $headers->remove('Auto-Submitted');
            $headers->addTextHeader('Auto-Submitted', 'auto-replied');
        }
    }

    private static function valid(?string $id): bool
    {
        if ($id === null || MessageId::normalise($id) === null || !str_contains($id, '@')) {
            return false;
        }
        try {
            new Address($id);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
