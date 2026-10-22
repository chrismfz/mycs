<?php

namespace App\Livewire\Tickets;

use App\Attributes\DisabledIf;
use App\Livewire\Component;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\CurrentClient;
use App\Support\Knowledgebase\KbSearch;
use App\Support\Tickets\TicketFiles;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use MyCS\TicketMail\Attachments;

#[DisabledIf('tickets_disabled')]
class Create extends Component
{
    use WithFileUploads;

    public array $attachments = [];

    public string $message;

    public string $subject = '';

    public ?int $department = null;

    public ?int $service = null;

    public string $priority = 'low'; // what the select shows first: unset, Livewire empties the select and «required» refuses it

    public function create()
    {
        // Add rules for the department
        $this->validate([
            // One the customer is offered (not hidden), when there are any (docs/tickets.md §5).
            'department' => TicketDepartment::query()->offered()->exists()
                ? ['required', 'integer', Rule::in(TicketDepartment::query()->offered()->pluck('id')->all())] : 'prohibited',
            // A service of the account, named only by a login that may see the services (`products`, PR 2b).
            'service' => CurrentClient::allows('products') ? 'nullable|exists:services,id,client_id,' . CurrentClient::id() : 'prohibited',
            'subject' => 'required|string',
            'message' => 'required|string',
            'priority' => 'required|in:low,medium,high',
            'attachments' => 'array|max:' . Attachments::MAX_COUNT,
            'attachments.*' => ['file', 'max:' . (Attachments::MAX_BYTES / 1024), TicketFiles::rule()],
        ]);

        $rateLimitKey = 'create-ticket:' . Auth::id();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            $this->notify(__('Too many ticket creation attempts. Please try again in 30 seconds.'), 'error');

            return;
        }

        RateLimiter::increment($rateLimitKey, 30);

        $ticket = Ticket::create([
            'client_id' => CurrentClient::id(),
            'department_id' => $this->department,
            'opened_via' => 'portal',
            'service_id' => $this->service,
            'subject' => $this->subject,
            'priority' => $this->priority,
        ]);

        $message = $ticket->messages()->create([
            'user_id' => Auth::id(),
            'message' => $this->message,
        ]);

        // The same rules as by email (docs/tickets.md §3 ΣΤ): a type judged by its bytes, a name of ours.
        foreach (array_slice($this->attachments, 0, Attachments::MAX_COUNT) as $attachment) {
            TicketFiles::storeUpload($message, $attachment);
        }

        $this->notify(__('Message sent successfully'), redirect: true);

        $this->reset(['attachments', 'message', 'subject', 'department', 'service', 'priority']);
        $this->dispatch('saved');

        $this->redirect(route('tickets.show', $ticket), true);
    }

    public function render()
    {
        return view('tickets.create', [
            'departments' => TicketDepartment::query()->offered()->get(['id', 'name']),
            'services' => CurrentClient::allows('products') ? CurrentClient::model()->services()->orderBy('id', 'desc')->get() : collect(),
            'suggestions' => KbSearch::find($this->subject, 5),
        ])->layoutData([
            'title' => __('ticket.create_ticket'),
            'sidebar' => true,
        ]);
    }
}
