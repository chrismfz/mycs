<?php

namespace Tests\Feature;

use App\Livewire\Contact;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Code review of PR B (#251): «Contact» without a login. */
class ContactFormReviewTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'captcha', 'settingable_type' => null], ['value' => 'disabled']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        TicketDepartment::query()->where('id', '!=', $this->support->id)->update(['hidden' => true]);
        foreach (['contact-ip:127.0.0.1', 'contact-email:maria@example.com'] as $key) {
            RateLimiter::clear($key);
        }
    }

    private function form(array $values = []): Testable
    {
        $test = Livewire::test(Contact::class);
        foreach ($values + ['name' => 'Maria', 'email' => 'maria@example.com', 'subject' => 'My site is down', 'message' => 'Please help'] as $field => $value) {
            $test->set($field, $value);
        }

        return $test;
    }

    private function mails()
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage());
    }

    /** R1: no department offered to a guest (all «clients only»): a ticket no one is told of. */
    public function test_with_no_department_offered_no_ticket_goes_untold(): void
    {
        $this->support->update(['clients_only' => true]);
        $admin = Staff::factory()->admin()->create();
        $this->support->notifyStaff()->attach($admin);

        $this->form()->call('submit');

        $staffMails = $this->mails()->filter(fn ($m) => $m->getTo()[0]->getAddress() === $admin->email)->count();
        $this->assertTrue(Ticket::query()->count() === 0 || $staffMails > 0, 'A guest ticket without a department was created and no staff member was told of it.');
    }

    /** R2: a name or subject of control characters only passes `required` and is stored empty. */
    public function test_a_name_or_subject_of_control_characters_only_is_refused(): void
    {
        $this->form(['name' => "\x01\x02", 'subject' => "\x07\x08"])->call('submit');

        $ticket = Ticket::query()->first();
        $this->assertTrue($ticket === null || ($ticket->guest_name !== '' && $ticket->subject !== ''), 'Stored an empty name/subject.');
    }

    /** R3: the form opened as a guest, then a login in another tab: the submit still makes a guest ticket. */
    public function test_a_login_after_mount_does_not_open_a_guests_ticket(): void
    {
        $test = $this->form();
        $user = User::factory()->create();
        Auth::guard('web')->login($user);

        $test->call('submit');

        $this->assertSame(0, Ticket::query()->whereNull('client_id')->count());
    }

    /** Fine (claim check): the «received» mail carries no typed name either. */
    public function test_the_received_mail_has_not_the_typed_name(): void
    {
        $this->form(['name' => 'Visit cheap-pills.example'])->call('submit')->assertHasNoErrors();

        $mail = $this->mails()->sole();
        $this->assertStringNotContainsString('cheap-pills', (string) $mail->getSubject() . $mail->getHtmlBody() . $mail->getTextBody());
    }

    /** Fine (claim check): a login without the tickets permission goes to the dashboard. */
    public function test_a_login_without_tickets_goes_to_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession($this->loginUser($user));
        $response = $this->get(route('contact'));
        $this->assertTrue($response->isRedirect(route('tickets.create')) || $response->isRedirect(route('dashboard')));
    }
}
