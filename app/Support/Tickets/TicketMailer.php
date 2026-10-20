<?php

namespace App\Support\Tickets;

use App\Helpers\NotificationHelper;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Cache;
use MyCS\TicketMail\MessageId;

/**
 * The mail about a ticket (docs/tickets.md §3, A3). Each gets a Message-ID of ours, kept (TicketMailId) before it is
 * queued, so a retry sends the same one and the answer to it joins the ticket. Only the new reply, never the history;
 * only to the ticket's owner (the account's owner, or the guest's address), never to anyone in To or Cc (§3 Γ).
 * Headers in TicketReplyEmailListener.
 */
final class TicketMailer
{
    /** Staff are told of a ticket's replies at most once in this many minutes. */
    public const NOTICE_MINUTES = 10;

    /** A staff reply (not a note): to the owner. */
    public function reply(TicketMessage $message): void
    {
        $ticket = $message->ticket;
        if ($message->is_internal || !$message->isFromStaff() || $ticket === null) {
            return;
        }
        if ($ticket->isGuest()) {
            if (blank($ticket->guest_email)) {
                return;
            }
            $id = $this->newId($ticket, $message, TicketMailId::REPLY, (string) $ticket->guest_email);
            // A From our server did not vouch for may be forged: the mail to it carries nothing the sender wrote (their
            // subject, their name), only our words (no spam relayed by us, signed as ours).
            // Their way to the ticket (PR Γ): a new link in every mail, to this address only.
            $data = ['ticketMessage' => $message, 'ticket_url' => GuestLinks::issue($ticket)];
            if ($ticket->sender_vouched === false) {
                $data['ticket'] = ['reference' => $ticket->reference, 'subject' => __('Your message'), 'guest_name' => __('customer')];
            }
            NotificationHelper::guestTicketNotification($ticket, 'guest_ticket_message', $data, $id);

            return;
        }
        if ($ticket->client !== null) {
            $id = $this->newId($ticket, $message, TicketMailId::REPLY, $ticket->client->owner()?->email);
            NotificationHelper::ticketMessageNotification($ticket->client, $message, ['thread_message_id' => $id]);
        }
    }

    /**
     * «We received your message» to whoever opened a ticket by mail, once, and only when our server vouched for the
     * address (otherwise a forged From would have us mail a stranger: backscatter). Marked as an automatic reply.
     */
    public function received(Ticket $ticket, string $to): void
    {
        if (blank($to) || TicketMailId::query()->where('ticket_id', $ticket->id)->where('kind', TicketMailId::RECEIVED)->exists()) {
            return;
        }
        $id = $this->newId($ticket, null, TicketMailId::RECEIVED, $to);
        // A guest's link only to the ticket's own address; an account's ticket is seen in the client area.
        $url = $ticket->isGuest() && strcasecmp($to, (string) $ticket->guest_email) === 0 ? GuestLinks::issue($ticket) : route('tickets.show', $ticket);
        NotificationHelper::guestTicketNotification($ticket, 'ticket_received', ['ticket_url' => $url], $id, $to);
    }

    /** A new link to a guest's ticket, to its own address (an expired link asked for again, PR Γ). */
    public function link(Ticket $ticket): void
    {
        if (!$ticket->isGuest() || blank($ticket->guest_email)) {
            return;
        }
        $id = $this->newId($ticket, null, TicketMailId::RECEIVED, (string) $ticket->guest_email);
        NotificationHelper::guestTicketNotification($ticket, 'ticket_link', ['ticket_url' => GuestLinks::issue($ticket)], $id);
    }

    /**
     * Staff told of a new ticket or a customer's reply: the one it is assigned to, else the department's (Configuration
     * → Ticket Departments → Notify). Never of their own reply or a note.
     */
    public function notifyStaff(TicketMessage $message, bool $new): void
    {
        $ticket = $message->ticket;
        if ($message->is_internal || $message->isFromStaff() || $ticket === null) {
            return;
        }
        $can = fn (Staff $member) => filled($member->email) && $member->hasPermission('admin.tickets.viewAny');
        $assignee = $ticket->assignedTo;
        $staff = collect($assignee instanceof Staff && $can($assignee) ? [$assignee] : []);
        // An assignee who cannot be told (no tickets permission, no address): the department's, not no one.
        if ($staff->isEmpty()) {
            $staff = ($ticket->department?->notifyStaff()->get() ?? collect())->filter($can);
        }
        // A new ticket is always told; its replies at most once in NOTICE_MINUTES: a flood is one mail to look at.
        if ($staff->isEmpty() || (!$new && !Cache::add('ticket-notice:' . $ticket->id, true, now()->addMinutes(self::NOTICE_MINUTES)))) {
            return;
        }
        $subject = ($new ? 'New ticket' : 'Reply') . " [#{$ticket->reference}] " . mb_substr((string) $ticket->subject, 0, 150);
        $who = e($ticket->requesterName()) . ($ticket->isGuest() ? ' (' . e((string) $ticket->guest_email) . ', ' . e(__('guest')) . ')' : '');
        $body = '<p><strong>' . $who . '</strong> · ' . e((string) $ticket->department?->name) . '</p>'
            . '<pre style="white-space:pre-wrap">' . e(mb_substr((string) $message->message, 0, 3000)) . '</pre>'
            . '<p><a href="' . e(route('filament.admin.resources.tickets.edit', $ticket)) . '">' . e(__('Open the ticket')) . '</a></p>';
        foreach ($staff as $member) {
            NotificationHelper::sendSystemEmailNotification($subject, $body, email: $member->email);
        }
    }

    private function newId(Ticket $ticket, ?TicketMessage $message, string $kind, ?string $to): string
    {
        $id = MessageId::generate(self::domain($ticket));
        TicketMailId::query()->create(['ticket_id' => $ticket->id, 'ticket_message_id' => $message?->id, 'message_id' => $id,
            'kind' => $kind, 'recipient' => $to !== null ? mb_substr($to, 0, 250) : null]);

        return $id;
    }

    /** The domain our ids are of: the department's address, else the site's. */
    public static function domain(Ticket $ticket): string
    {
        $email = (string) $ticket->department?->email;
        foreach ([str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '', (string) parse_url((string) config('app.url'), PHP_URL_HOST)] as $domain) {
            // An IDN as its ASCII form (xn--): an id of ours is ASCII, or it would not be ours (MessageId::isOurs).
            $ascii = $domain !== '' ? strtolower((string) (idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '')) : '';
            if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $ascii) === 1) {
                return $ascii;
            }
        }

        return 'localhost.localdomain';
    }
}
