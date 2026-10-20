<?php

namespace App\Support\Tickets;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use InvalidArgumentException;
use MyCS\TicketMail\InboundEmail;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\ReplyText;

/**
 * What staff do with a quarantined mail (docs/tickets.md §3 Β): attach it to a ticket, open a new one from it, dismiss
 * it, block its sender. Each once: the row is locked and must still be pending. A mail attached by staff from someone
 * who is not the ticket's owner says who wrote it, so it never reads as the owner's words.
 */
final class QuarantineActions
{
    public function __construct(private readonly InboundRouter $router) {}

    public function attach(TicketQuarantine $quarantine, Ticket $ticket, Staff $staff): TicketMessage
    {
        return $this->handle($quarantine, $staff, function (TicketQuarantine $quarantine, InboundEmail $email) use ($ticket) {
            $author = $this->router->owner($ticket, $email->fromEmail);
            $text = ReplyText::clean($email->body);
            if ($author === false) {
                $text = '(' . __('From') . ' ' . $email->fromEmail . ")\n\n" . $text;
            }
            $message = $ticket->messages()->create($this->messageData($quarantine, $email, $author ?: null, $text));
            TicketFiles::storeInbound($message, $email->attachments);

            return [TicketQuarantine::ATTACHED, $ticket, $message];
        })[2];
    }

    /** A new ticket from it: a guest's (its sender), or in the account staff chose. */
    public function open(TicketQuarantine $quarantine, Staff $staff, ?int $clientId = null): Ticket
    {
        return $this->handle($quarantine, $staff, function (TicketQuarantine $quarantine, InboundEmail $email) use ($clientId) {
            $ticket = Ticket::query()->create([
                'subject' => $quarantine->subject ?: __('(no subject)'),
                'status' => Ticket::STATUS_OPEN,
                'priority' => 'medium',
                'department_id' => $quarantine->department_id,
                'opened_via' => 'email',
                'client_id' => $clientId,
                'guest_name' => $clientId === null ? ($email->fromName ?? $email->fromEmail) : null,
                'guest_email' => $clientId === null ? ($email->fromEmail ?: null) : null,
            ]);
            $author = $clientId !== null ? $this->router->owner($ticket, $email->fromEmail) : null;
            $text = trim($email->body);
            if ($clientId !== null && $author === false) {
                $text = '(' . __('From') . ' ' . $email->fromEmail . ")\n\n" . $text;
            }
            $message = $ticket->messages()->create($this->messageData($quarantine, $email, $author ?: null, $text));
            TicketFiles::storeInbound($message, $email->attachments);

            return [TicketQuarantine::OPENED, $ticket];
        })[1];
    }

    public function dismiss(TicketQuarantine $quarantine, Staff $staff): void
    {
        $this->handle($quarantine, $staff, fn () => [TicketQuarantine::DISMISSED, null], parse: false);
    }

    /** Blocks the sender (its address) and dismisses every pending mail of it. */
    public function block(TicketQuarantine $quarantine, Staff $staff): void
    {
        if (blank($quarantine->from_email)) {
            throw new InvalidArgumentException(__('It has no sender to block.'));
        }
        TicketBlockedSender::query()->firstOrCreate(['value' => TicketBlockedSender::normalise($quarantine->from_email)],
            ['note' => __('From the quarantine'), 'created_by' => $staff->id]);
        foreach (TicketQuarantine::query()->where('status', TicketQuarantine::PENDING)->where('from_email', $quarantine->from_email)->get() as $pending) {
            $this->dismiss($pending, $staff);
        }
    }

    /**
     * @param  \Closure(TicketQuarantine, InboundEmail): array{0: string, 1: ?Ticket, 2?: TicketMessage}  $do
     * @return array{0: string, 1: ?Ticket, 2?: TicketMessage}
     */
    private function handle(TicketQuarantine $quarantine, Staff $staff, \Closure $do, bool $parse = true): array
    {
        return TicketFiles::transaction(function () use ($quarantine, $staff, $do, $parse) {
            $locked = TicketQuarantine::query()->lockForUpdate()->findOrFail($quarantine->id);
            if (!$locked->isPending()) {
                throw new InvalidArgumentException(__('Already handled.'));
            }
            $email = $parse ? $this->email($locked) : null;
            $done = $do($locked, $email ?? new InboundEmail(null, '', null, 0, '', ''));
            $locked->update(['status' => $done[0], 'ticket_id' => $done[1]?->id, 'handled_by' => $staff->id, 'handled_at' => now()]);

            return $done;
        });
    }

    private function email(TicketQuarantine $quarantine): InboundEmail
    {
        $raw = $quarantine->raw();
        if ($raw === null || $quarantine->reason === 'too_large') {
            // Its words as kept (no files): the mail itself is gone or was never fetched.
            return new InboundEmail(null, (string) $quarantine->from_email, $quarantine->from_name, 1, (string) $quarantine->subject,
                (string) ($quarantine->preview ?: __('(The mail was too large to fetch: ask the sender for it.)')));
        }

        return Parser::parse($raw);
    }

    /** @return array<string, mixed> */
    private function messageData(TicketQuarantine $quarantine, InboundEmail $email, ?User $author, string $text): array
    {
        if ($email->refused !== []) {
            $text .= "\n\n— " . __('Files not kept') . ': ' . implode(', ', array_map(fn (array $file) => $file['name'], $email->refused));
        }

        return [
            'user_id' => $author?->id,
            'message' => mb_strcut($text !== '' ? $text : '(' . __('no text') . ')', 0, 60_000, 'UTF-8'),
            'body_original' => $email->body,
            'via' => TicketMessage::VIA_EMAIL,
            // Its Message-ID stays with the quarantine row (skipped next time): the message needs none.
            'email_message_id' => null,
        ];
    }
}
