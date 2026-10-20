<?php

namespace App\Livewire;

use App\Attributes\DisabledIf;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Support\CurrentClient;
use App\Support\Tickets\TicketMailer;
use App\Traits\Captchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * «Contact» without a login (docs/tickets.md §3 Ζ, PR B): a guest's ticket from a name, an address, a subject, a
 * department and a message; no priority, no files. ALTCHA, a honeypot, limits per network address and per email.
 * The address is the visitor's word: the ticket says it is not vouched for, the answer to it says nothing of what was
 * written, and the visitor sees the ticket only through the mail sent to that address (PR Γ). A login is sent to
 * «New ticket».
 */
#[DisabledIf('tickets_disabled')]
class Contact extends Component
{
    use Captchable;

    /** Tickets from one network address in an hour. */
    public const PER_IP = 5;

    /** Tickets for one email address in an hour. */
    public const PER_EMAIL = 3;

    public string $name = '';

    public string $email = '';

    public string $subject = '';

    public ?int $department = null;

    public string $message = '';

    /** Filled by bots only: hidden from people (and from screen readers). */
    public string $website = '';

    #[Locked]
    public bool $sent = false;

    public function mount()
    {
        if (Auth::guard('web')->check()) {
            return $this->redirect(CurrentClient::allows('tickets') ? route('tickets.create') : route('dashboard'), true);
        }
        $this->department = $this->departments()->count() === 1 ? $this->departments()->first()?->id : null;
    }

    /** @return Collection<int, TicketDepartment> */
    public function departments(): Collection
    {
        return TicketDepartment::query()->offered(guest: true)->get();
    }

    public function submit(): void
    {
        if ($this->sent) {
            return;
        }
        $departments = $this->departments();
        $this->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email:rfc|max:191',
            'subject' => 'required|string|max:200',
            'department' => $departments->isNotEmpty() ? ['required', 'integer', Rule::in($departments->pluck('id')->all())] : 'prohibited',
            'message' => 'required|string|max:10000',
        ]);
        $this->captcha();

        $email = strtolower(trim($this->email));
        $limits = ['contact-ip:' . request()->ip() => self::PER_IP, 'contact-email:' . TicketBlockedSender::base($email) => self::PER_EMAIL];
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->addError('email', __('ticket.contact_too_many'));

                return;
            }
        }
        foreach ($limits as $key => $max) {
            RateLimiter::hit($key, 3600);
        }

        // A bot (the honeypot) or a blocked sender: told it went, given nothing to learn from.
        if ($this->website === '' && !TicketBlockedSender::blocks($email)) {
            $ticket = DB::transaction(function () use ($email) {
                $ticket = Ticket::query()->create([
                    'subject' => $this->line($this->subject, 200),
                    'status' => Ticket::STATUS_OPEN,
                    'priority' => 'medium',
                    'department_id' => $this->department,
                    'opened_via' => 'form',
                    // Typed in, never proven: our answers to it carry nothing the visitor wrote (§3 Β, review of #249).
                    'sender_vouched' => false,
                    'guest_name' => $this->line($this->name, 100),
                    'guest_email' => $email,
                ]);
                $ticket->messages()->create(['message' => trim($this->message), 'via' => 'form']);

                return $ticket;
            });
            // «We received it»: the number only, nothing the visitor wrote (an address typed in may be anyone's).
            app(TicketMailer::class)->received($ticket, $email);
        }

        $this->reset(['name', 'subject', 'message', 'website']);
        $this->sent = true;
    }

    /** One line: no control characters, single spaces. */
    private function line(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value)));

        return mb_substr($value, 0, $max);
    }

    public function render()
    {
        return view('contact', ['departments' => $this->departments()])->layoutData(['title' => __('navigation.contact')]);
    }
}
