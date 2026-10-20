<?php

namespace App\Livewire\Tickets;

use App\Attributes\DisabledIf;
use App\Livewire\Component;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketGuestLink;
use App\Models\TicketMessage;
use App\Support\Tickets\GuestLinks;
use App\Support\Tickets\TicketMailer;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * A guest's ticket by its link (docs/tickets.md §3 Δ, PR Γ): its messages (never staff's notes) and an answer, in text
 * (guests send no files on the web, §3 ΣΤ). The link is checked again on every request: revoked or expired, the page
 * shows nothing of the ticket and offers a new link to the ticket's own address.
 */
#[DisabledIf('tickets_disabled')]
class Guest extends Component
{
    /** Answers from one link in an hour. */
    public const REPLIES_PER_HOUR = 10;

    #[Locked]
    public string $token;

    public string $message = '';

    #[Locked]
    public bool $resent = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->link()?->forceFill(['last_used_at' => now()])->save();
    }

    private function link(): ?TicketGuestLink
    {
        return GuestLinks::find($this->token);
    }

    private function ticket(): ?Ticket
    {
        return $this->link()?->ticket;
    }

    public function reply(): void
    {
        $ticket = $this->ticket();
        if ($ticket === null) {
            return;
        }
        // No control or format characters (bidi, zero-width): an answer of them only is no answer (as the contact form).
        $this->message = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\p{Cf}]/u', '', $this->message));
        $this->validate(['message' => 'required|string|max:10000']);
        // Counted as taken (increment): two at once never both slip under the limit.
        if (RateLimiter::increment('ticket-guest-reply:' . $ticket->id, 3600) > self::REPLIES_PER_HOUR) {
            $this->addError('message', __('ticket.contact_too_many'));

            return;
        }
        $ticket->messages()->create(['message' => trim($this->message), 'via' => 'link']);
        $this->message = '';
        $this->notify(__('Message sent successfully'));
    }

    /** An expired link: a new one, to the ticket's own address only (never to one typed here), once in a while. */
    public function resend(): void
    {
        $link = GuestLinks::any($this->token);
        $ticket = $link?->ticket;
        if ($this->resent || $link === null || $link->revoked_at !== null || $ticket === null || !$ticket->isGuest() || blank($ticket->guest_email)) {
            $this->resent = true;

            return;
        }
        if (RateLimiter::increment('ticket-guest-resend:' . $ticket->id, 3600) <= 3) {
            app(TicketMailer::class)->link($ticket);
        }
        $this->resent = true;
    }

    public function render()
    {
        $ticket = $this->ticket();
        $known = $ticket === null ? GuestLinks::any($this->token) : null;

        return view('tickets.guest', [
            'ticket' => $ticket,
            'messages' => $ticket?->publicMessages()->with(['staff', 'attachments'])->oldest('id')->get() ?? collect(),
            // Expired (not revoked): a new link can be asked for; anything else is just «not valid».
            'expired' => $known !== null && $known->revoked_at === null && $known->ticket?->isGuest() === true,
            'subject' => $ticket === null ? '' : ($ticket->sender_vouched === false ? __('Your message') : (string) $ticket->subject),
        ])->layoutData(['title' => $ticket !== null ? __('ticket.title', ['id' => $ticket->reference]) : __('ticket.guest_link_invalid')]);
    }

    /**
     * Shown to whoever has the link: what came from that inbox. On a ticket whose address no server vouched for (a form,
     * a forged From), what came in with it may be a stranger's, so it is not served on our domain (review of #252): only
     * staff's answers and what was written here, by whoever reads the inbox the link went to.
     */
    public static function shown(TicketMessage $message, Ticket $ticket): bool
    {
        return $ticket->sender_vouched !== false || $message->isFromStaff() || $message->via === 'link';
    }

    /** Who wrote a message, as the guest sees it. */
    public static function author(TicketMessage $message, Ticket $ticket): string
    {
        $staff = $message->staff;

        if ($message->isFromStaff()) {
            return $staff instanceof Staff ? (string) $staff->name : (string) config('app.name');
        }

        return $ticket->sender_vouched === false ? __('You') : (string) $ticket->guest_name;
    }
}
