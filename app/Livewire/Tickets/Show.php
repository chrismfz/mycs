<?php

namespace App\Livewire\Tickets;

use App\Attributes\DisabledIf;
use App\Livewire\Component;
use App\Models\Staff;
use App\Models\Ticket;
use App\Support\Actor;
use App\Support\Tickets\TicketFiles;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Rule;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use MyCS\TicketMail\Attachments;

#[DisabledIf('tickets_disabled')]
class Show extends Component
{
    use WithFileUploads;

    #[Locked]
    public Ticket $ticket;

    public array $attachments = [];

    /** @return array<string, mixed> the files: the same rules as by email (docs/tickets.md §3 ΣΤ) */
    protected function rules(): array
    {
        return [
            'attachments' => 'array|max:' . Attachments::MAX_COUNT,
            'attachments.*' => ['file', 'max:' . (Attachments::MAX_BYTES / 1024), TicketFiles::rule()],
        ];
    }

    #[Rule('required', 'string')]
    public string $message;

    /** A merged ticket's page leads to the one it was merged into (same owner: docs/tickets.md §6κ). */
    public function mount(): void
    {
        if ($this->ticket->isMerged()) {
            $this->redirectRoute('tickets.show', $this->ticket->survivor());
        }
    }

    /** A page opened before the merge writes nothing into the merged ticket: it goes to the one kept (review of #266). */
    private function merged(): bool
    {
        $ticket = $this->ticket->fresh();
        if ($ticket === null || !$ticket->isMerged()) {
            return false;
        }
        $this->redirectRoute('tickets.show', $ticket->survivor());

        return true;
    }

    public function save()
    {
        $this->authorize('update', $this->ticket);
        if ($this->merged()) {
            return;
        }
        $this->validate();

        $rateLimitKey = 'ticket-message:' . $this->ticket->id . ':' . Auth::id();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $this->notify(__('Too many message attempts. Please try again in 10 seconds.'), 'error');

            return;
        }

        RateLimiter::increment($rateLimitKey, 10);

        // Written by staff logged in as the client (AUTH-19): the staff member's reply, not the customer's words.
        $actor = Actor::current();
        $message = $this->ticket->messages()->create([
            ...($actor instanceof Staff ? ['staff_id' => $actor->id] : ['user_id' => Auth::id()]),
            'message' => $this->message,
        ]);

        // The same rules as by email (docs/tickets.md §3 ΣΤ): a type judged by its bytes, a name of ours.
        foreach (array_slice($this->attachments, 0, Attachments::MAX_COUNT) as $attachment) {
            TicketFiles::storeUpload($message, $attachment);
        }

        $this->notify(__('Message sent successfully'));

        $this->message = '';
        $this->attachments = [];
        $this->dispatch('saved');
    }

    public function closeTicket()
    {
        $this->authorize('update', $this->ticket);
        if ($this->merged()) {
            return;
        }
        if (config('settings.ticket_client_closing_disabled', false)) {
            abort(403, 'Closing tickets is disabled.');
        }

        $this->ticket->update(['status' => 'closed']);
        $this->ticket->refresh();

        $this->notify(__('ticket.close_ticket_success'));
    }

    public function render()
    {
        return view('tickets.show')->layoutData([
            'sidebar' => true,
        ]);
    }
}
