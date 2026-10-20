<?php

namespace Tests\Feature;

use App\Livewire\Contact;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Throwable;

/** Adversarial review of #251: «Contact» without a login (docs/tickets.md §3 Ζ, §6δ). */
class ContactFormAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    /** The network address the next Livewire request comes from. */
    private string $ip = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'captcha', 'settingable_type' => null], ['value' => 'disabled']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test']);
        TicketDepartment::query()->where('id', '!=', $this->support->id)->update(['hidden' => true]);
        // Livewire's test requests always come from 127.0.0.1: each one, as the kernel takes it, from $this->ip.
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', $this->ip));
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    /** A submission from a network address of the attacker's choosing (a botnet, or one IPv6 /64). */
    private function form(array $values = [], string $ip = '127.0.0.1'): Testable
    {
        $this->ip = $ip;
        $test = Livewire::test(Contact::class);
        foreach ($values + ['name' => 'Maria', 'email' => 'maria@example.com', 'subject' => 'Hello', 'message' => 'Hello'] as $field => $value) {
            $test->set($field, $value);
        }

        return $test;
    }

    /** @return list<string> the addresses our acknowledgements went to */
    private function acknowledged(): array
    {
        return $this->sent()->map(fn (Email $mail) => $mail->getTo()[0]->getAddress())->values()->all();
    }

    /** A quoted local part is the same mailbox («"maria"@x» is maria@x): a fourth «we received it» in the hour. */
    public function test_a_quoted_local_part_is_the_same_mailbox_for_the_limit(): void
    {
        for ($i = 0; $i < Contact::PER_EMAIL; $i++) {
            $this->form(ip: "198.51.100.$i")->call('submit')->assertHasNoErrors();
        }
        $this->form(['email' => '"maria"@example.com'], '198.51.100.9')->call('submit')->assertHasErrors('email');

        $this->assertLessThanOrEqual(Contact::PER_EMAIL, count($this->acknowledged()));
    }

    /** An IDN domain in its two spellings is one mailbox: unicode and xn-- both count. */
    public function test_an_idn_domain_in_both_spellings_is_one_mailbox_for_the_limit(): void
    {
        for ($i = 0; $i < Contact::PER_EMAIL; $i++) {
            $this->form(['email' => 'maria@xn--exmple-cua.com'], "198.51.100.$i")->call('submit')->assertHasNoErrors();
        }
        $this->form(['email' => 'maria@exämple.com'], '198.51.100.9')->call('submit')->assertHasErrors('email');

        $this->assertLessThanOrEqual(Contact::PER_EMAIL, count($this->acknowledged()));
    }

    /**
     * Gmail ignores dots: one victim inbox has 2^(n-1) spellings, each a fresh «3 an hour». With a botnet (or an IPv6
     * /64, below) and a PoW captcha that costs a CPU-second, the victim gets as many acknowledgements as the attacker
     * likes, from our server and our domain. There is no cap that does not depend on how the address is spelled.
     */
    public function test_dots_in_a_gmail_address_do_not_buy_more_mail_to_one_inbox(): void
    {
        foreach (['victim@gmail.com', 'v.ictim@gmail.com', 'vi.ctim@gmail.com', 'vic.tim@gmail.com', 'vict.im@gmail.com', 'victi.m@gmail.com'] as $i => $email) {
            $this->form(['email' => $email], "198.51.100.$i")->call('submit');
        }

        $this->assertLessThanOrEqual(Contact::PER_EMAIL, count($this->acknowledged()), 'acks to one Gmail inbox: ' . implode(', ', $this->acknowledged()));
    }

    /** One IPv6 /64 is one subscriber's LAN: 2^64 «network addresses», each with its own 5 an hour. */
    public function test_one_ipv6_64_is_one_network_address_for_the_limit(): void
    {
        for ($i = 1; $i <= Contact::PER_IP; $i++) {
            $this->form(['email' => "someone$i@example.com"], "2001:db8:1:2::$i")->call('submit')->assertHasNoErrors();
        }
        $this->form(['email' => 'someone9@example.com'], '2001:db8:1:2::99')->call('submit')->assertHasErrors('email');

        $this->assertSame(Contact::PER_IP, Ticket::query()->count());
    }

    /** Sanity: the requests above really come from the address given (the per-address limit holds for one address). */
    public function test_the_network_address_reaches_the_component(): void
    {
        for ($i = 1; $i <= Contact::PER_IP; $i++) {
            $this->form(['email' => "someone$i@example.com"], '203.0.113.7')->call('submit')->assertHasNoErrors();
        }
        $this->form(['email' => 'someone9@example.com'], '203.0.113.7')->call('submit')->assertHasErrors('email');
        $this->form(['email' => 'someone9@example.com'], '203.0.113.8')->call('submit')->assertHasNoErrors();
    }

    /**
     * No department is offered to a guest (all «clients only» or hidden): the admin has said guests open no tickets.
     * The form takes it anyway, as a ticket of no department (`prohibited` only forbids naming one), which no one is
     * told of (no department, no one to notify).
     */
    public function test_no_department_for_guests_means_no_guests_ticket(): void
    {
        $this->support->update(['clients_only' => true]);

        $this->form()->call('submit');

        $this->assertSame(0, Ticket::query()->count(), 'a guest ticket with department ' . var_export(Ticket::query()->first()?->department_id, true));
        $this->assertCount(0, $this->sent());
    }

    /**
     * The captcha setting's default is «disabled» (app/Classes/Settings.php): then this public form, which mails any
     * address typed in, has no captcha at all, only the per-address limits. A public form that sends mail should not
     * open without one.
     */
    public function test_the_public_form_does_not_take_tickets_without_a_captcha(): void
    {
        $this->form()->call('submit');

        $this->assertSame(0, Ticket::query()->count());
        $this->assertCount(0, $this->sent());
    }

    /** Our own department address typed in: we mail ourselves (the inbound side quarantines it as own_address). */
    public function test_our_own_address_is_refused(): void
    {
        $this->form(['email' => 'Support+x@MyIP.test'])->call('submit')->assertHasErrors('email');

        $this->assertSame(0, Ticket::query()->count());
        $this->assertCount(0, $this->sent());
    }

    /** @return array<string, array{string}> */
    public static function unmailable(): array
    {
        return [
            'a comment Symfony refuses' => ['maria(free text here)@example.com'],
            'an address literal' => ['maria@[127.0.0.1]'],
            'a host with no dot' => ['root@localhost'],
        ];
    }

    /** `email:rfc` takes addresses we should never mail, or cannot: refused on the form, no ticket half made. */
    #[DataProvider('unmailable')]
    public function test_an_address_we_should_not_mail_is_refused(string $email): void
    {
        try {
            $this->form(['email' => $email])->call('submit')->assertHasErrors('email');
        } catch (Throwable $e) {
            $this->fail(get_class($e) . ': ' . $e->getMessage() . ' (tickets made: ' . Ticket::query()->count() . ')');
        }

        $this->assertSame(0, Ticket::query()->count());
        $this->assertCount(0, $this->sent());
    }

    /** «One line, no control characters»: Unicode's format controls too (RLO turns the staff notice's subject around). */
    public function test_bidi_and_invisible_controls_are_taken_out(): void
    {
        $this->form(['name' => "Maria\u{202E}lmth.exe", 'subject' => "Invoice\u{200B}\u{2066} #12\u{2069}\u{202E}gpj.exe"])->call('submit')->assertHasNoErrors();

        $ticket = Ticket::query()->sole();
        $this->assertDoesNotMatchRegularExpression('/\p{Cf}/u', $ticket->guest_name . $ticket->subject);
    }
}
