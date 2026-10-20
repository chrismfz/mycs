<?php

namespace Tests\Feature;

use App\Livewire\Contact;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** «Contact» without a login, PR B (docs/tickets.md §3 Ζ). */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'captcha', 'settingable_type' => null], ['value' => 'disabled']);
        // Livewire and requests read the settings again from the database: mail on there.
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']); // a truncating test may have taken the migration's (#242)
        $this->support->update(['email' => 'support@myip.test']);
        TicketDepartment::query()->where('id', '!=', $this->support->id)->update(['hidden' => true]);
        foreach (['contact-ip:127.0.0.1', 'contact-email:maria@example.com'] as $key) {
            RateLimiter::clear($key);
        }
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    private function form(array $values = []): Testable
    {
        $test = Livewire::test(Contact::class);
        foreach ($values + ['name' => 'Maria', 'email' => 'Maria@Example.com', 'subject' => 'My site is down', 'message' => 'Please help'] as $field => $value) {
            $test->set($field, $value);
        }

        return $test;
    }

    public function test_a_visitor_opens_a_guests_ticket_and_gets_its_number_only(): void
    {
        $this->get(route('contact'))->assertOk()->assertSee(__('ticket.contact_send'));

        $this->form()->call('submit')->assertHasNoErrors()->assertSet('sent', true)->assertSee(__('ticket.contact_sent_title'));

        $ticket = Ticket::query()->sole();
        $this->assertSame([null, 'Maria', 'maria@example.com', 'form', false, $this->support->id, 'My site is down', Ticket::STATUS_OPEN],
            [$ticket->client_id, $ticket->guest_name, $ticket->guest_email, $ticket->opened_via, $ticket->sender_vouched, $ticket->department_id, $ticket->subject, $ticket->status]);
        $this->assertSame('Please help', $ticket->messages()->sole()->message);

        $mail = $this->sent()->sole();
        $this->assertSame('maria@example.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('auto-replied', $mail->getHeaders()->get('Auto-Submitted')?->getBodyAsString());
        $this->assertStringContainsString($ticket->reference, (string) $mail->getSubject());
        // An address typed in may be anyone's: nothing the visitor wrote goes to it.
        $text = (string) $mail->getSubject() . (string) $mail->getHtmlBody();
        $this->assertStringNotContainsString('My site is down', $text);
        $this->assertStringNotContainsString('Please help', $text);
        $this->assertSame(TicketMailId::RECEIVED, TicketMailId::query()->sole()->kind);
    }

    /** The staff's answer to it says nothing the visitor wrote either (the address is not vouched for). */
    public function test_the_answer_to_a_form_ticket_carries_none_of_the_visitors_words(): void
    {
        $this->form(['name' => 'Win a prize at spam.example', 'subject' => 'CHEAP PILLS spam.example'])->call('submit')->assertHasNoErrors();
        $ticket = Ticket::query()->sole();

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'How can we help?']);

        $reply = $this->sent()->last();
        $this->assertStringContainsString('How can we help?', (string) $reply->getHtmlBody());
        $this->assertStringNotContainsString('spam.example', (string) $reply->getSubject() . (string) $reply->getHtmlBody());
    }

    public function test_a_bot_filling_the_honeypot_or_a_blocked_sender_gets_nothing(): void
    {
        $this->form(['website' => 'http://spam.example'])->call('submit')->assertSet('sent', true);
        TicketBlockedSender::query()->create(['value' => 'example.com']);
        $this->form()->call('submit')->assertSet('sent', true);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertCount(0, $this->sent());
    }

    public function test_at_most_three_an_hour_for_one_address(): void
    {
        for ($i = 0; $i < Contact::PER_EMAIL; $i++) {
            $this->form(['email' => $i === 1 ? 'maria+x@example.com' : 'maria@example.com'])->call('submit')->assertHasNoErrors();
        }
        $this->form()->call('submit')->assertHasErrors('email');

        $this->assertSame(Contact::PER_EMAIL, Ticket::query()->count());
    }

    public function test_the_captcha_is_asked_for(): void
    {
        Setting::query()->updateOrCreate(['key' => 'captcha', 'settingable_type' => null], ['value' => 'altcha']);

        $this->form()->set('captcha', 'not-a-solution')->call('submit')->assertHasErrors('captcha');

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_only_the_departments_a_guest_is_offered(): void
    {
        $sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $clients = TicketDepartment::query()->create(['name' => 'Clients', 'clients_only' => true]);
        $hidden = TicketDepartment::query()->create(['name' => 'Abuse', 'hidden' => true]);

        foreach ([$clients, $hidden] as $department) {
            $this->form(['department' => $department->id])->call('submit')->assertHasErrors('department');
        }
        $this->form(['department' => $sales->id])->call('submit')->assertHasNoErrors();

        $this->assertSame($sales->id, Ticket::query()->sole()->department_id);
        $this->get(route('contact'))->assertSee('Sales')->assertDontSee('Clients')->assertDontSee('Abuse');
    }

    public function test_the_fields_are_one_line_and_bounded(): void
    {
        $this->form(['name' => "Maria\r\nBcc: x@evil.test", 'subject' => str_repeat('a', 201)])->call('submit')->assertHasErrors('subject');
        $this->form(['name' => "Maria\r\nBcc: x@evil.test"])->call('submit')->assertHasNoErrors();

        $this->assertSame('Maria Bcc: x@evil.test', Ticket::query()->sole()->guest_name);
    }

    public function test_a_login_is_sent_to_new_ticket_and_the_menu_points_each_way(): void
    {
        $this->get('/')->assertSee(route('contact'), false);

        $user = User::factory()->create();
        $this->actingAs($user)->withSession($this->loginUser($user));
        $this->get(route('contact'))->assertRedirect(route('tickets.create'));
    }

    public function test_tickets_disabled_closes_the_form(): void
    {
        Setting::query()->updateOrCreate(['key' => 'tickets_disabled', 'settingable_type' => null], ['value' => '1']);

        $this->get(route('contact'))->assertNotFound();
    }
}
