<?php

namespace App\Livewire;

use App\Attributes\DisabledIf;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Support\CallerIp;
use App\Support\CurrentClient;
use App\Support\Tickets\TicketMailer;
use App\Traits\Captchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Symfony\Component\Mime\Address;

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

    /** Tickets from the form in an hour, from everyone: whatever addresses and networks, our mail stays bounded. */
    public const PER_HOUR = 30;

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
        // Logged in since the form opened (another tab): theirs is «New ticket», never a guest's ticket.
        if (Auth::guard('web')->check()) {
            $this->redirect(CurrentClient::allows('tickets') ? route('tickets.create') : route('dashboard'), true);

            return;
        }
        // No department takes guests: no form (a ticket of no department would reach no one, review of #251).
        $departments = $this->departments();
        if ($departments->isEmpty()) {
            return;
        }
        // Validated as kept: a name of control characters only is no name.
        $this->name = $this->line($this->name, 1000);
        $this->subject = $this->line($this->subject, 1000);
        $this->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'string', 'max:191', fn (string $attribute, mixed $value, \Closure $fail) => self::mailable((string) $value) ?: $fail(__('validation.email', ['attribute' => __('general.input.email')])),
                fn (string $attribute, mixed $value, \Closure $fail) => self::ours((string) $value) ? $fail(__('ticket.contact_our_address')) : null],
            'subject' => 'required|string|max:200',
            'department' => ['required', 'integer', Rule::in($departments->pluck('id')->all())],
            'message' => 'required|string|max:10000',
        ]);
        $this->requireCaptchaOrAltcha();

        $email = strtolower(trim($this->email));
        // Counted as they are taken (increment, not check-then-hit): two at once never both slip under a limit.
        $limits = ['contact-ip:' . CallerIp::key() => self::PER_IP,
            'contact-email:' . TicketBlockedSender::base($email) => self::PER_EMAIL, 'contact-all' => self::PER_HOUR];
        $over = false;
        foreach ($limits as $key => $max) {
            $over = RateLimiter::increment($key, 3600) > $max || $over;
        }
        if ($over) {
            $this->addError('email', __('ticket.contact_too_many'));

            return;
        }

        // A bot (the honeypot) or a blocked sender: told it went, given nothing to learn from.
        if ($this->website === '' && !TicketBlockedSender::blocks($email)) {
            $ticket = DB::transaction(function () use ($email) {
                $ticket = Ticket::query()->create([
                    'subject' => $this->subject,
                    'status' => Ticket::STATUS_OPEN,
                    'priority' => 'medium',
                    'department_id' => $this->department,
                    'opened_via' => 'form',
                    // Typed in, never proven: our answers to it carry nothing the visitor wrote (§3 Β, review of #249).
                    'sender_vouched' => false,
                    'guest_name' => $this->name,
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

    /** An address we may mail: one mailbox at a host with a dot, no comment, no address literal, as Symfony will send it. */
    public static function mailable(string $email): bool
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || str_contains($email, '(') || str_contains($email, '[')) {
            return false;
        }
        $domain = (string) (idn_to_ascii(substr($email, $at + 1), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '');
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $domain) !== 1) {
            return false;
        }
        try {
            new Address(substr($email, 0, $at) . '@' . $domain);
        } catch (\Throwable) {
            return false;
        }

        return filter_var(substr($email, 0, $at) . '@' . $domain, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** One of our own addresses (a department's): the form would have us mail ourselves. */
    public static function ours(string $email): bool
    {
        $base = TicketBlockedSender::base($email);

        return TicketDepartment::query()->get()->flatMap(fn (TicketDepartment $department) => $department->ownAddresses())
            ->map(fn (string $address) => TicketBlockedSender::base($address))->contains($base);
    }

    /** One line: no control or format characters (bidi, zero-width), single spaces. */
    private function line(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F\p{Cf}]/u', ' ', $value)));

        return mb_substr($value, 0, $max);
    }

    public function render()
    {
        return view('contact', ['departments' => $this->departments()])->layoutData(['title' => __('navigation.contact')]);
    }
}
