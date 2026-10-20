<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Livewire\Contact;
use App\Livewire\Tickets\Guest;
use App\Models\EmailLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketGuestLink;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Captcha\Altcha;
use App\Support\Tickets\GuestLinks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** A guest's ticket by the link mailed to them, PR Γ (docs/tickets.md §3 Δ). */
class GuestTicketLinkTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']); // a truncating test may have taken the migration's (#242)
        $this->support->update(['email' => 'support@myip.test']);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    private function guestTicket(): Ticket
    {
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email', 'sender_vouched' => true]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help me', 'via' => TicketMessage::VIA_EMAIL]);

        return $ticket;
    }

    /** The link the latest mail to the guest carries. */
    private function mailedLink(): string
    {
        preg_match('#/t/([A-Za-z0-9_-]{43})#', (string) $this->sent()->last()->getHtmlBody(), $m);
        $this->assertNotEmpty($m, 'the mail has a link');

        return $m[1];
    }

    private function staffReply(Ticket $ticket, array $attributes = []): TicketMessage
    {
        return TicketMessage::query()->create($attributes + ['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'We are on it']);
    }

    public function test_a_staff_reply_mails_a_link_that_opens_the_ticket_without_staffs_notes(): void
    {
        $ticket = $this->guestTicket();
        $this->staffReply($ticket, ['message' => 'INTERNAL-NOTE', 'is_internal' => true]);
        $this->staffReply($ticket);
        $token = $this->mailedLink();

        $this->get(route('tickets.guest', $token))->assertOk()
            ->assertSee('Site down')->assertSee('Help me')->assertSee('We are on it')->assertDontSee('INTERNAL-NOTE')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertSame(hash('sha256', $token), TicketGuestLink::query()->sole()->token_hash, 'kept only hashed');
        $this->assertStringNotContainsString($token, (string) EmailLog::query()->latest('id')->value('body'), 'never in the email log');
        $this->assertTrue(TicketGuestLink::query()->sole()->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_the_guest_answers_on_the_page(): void
    {
        $ticket = $this->guestTicket();
        $this->staffReply($ticket);
        $token = $this->mailedLink();

        Livewire::test(Guest::class, ['token' => $token])->set('message', 'Thank you, it works')->call('reply')->assertHasNoErrors();

        $message = $ticket->messages()->latest('id')->first();
        $this->assertSame(['Thank you, it works', 'link', null, null], [$message->message, $message->via, $message->user_id, $message->staff_id]);
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
    }

    public function test_an_unknown_or_malformed_token_shows_nothing(): void
    {
        $ticket = $this->guestTicket();
        GuestLinks::issue($ticket);

        foreach (['x', str_repeat('A', 43), str_repeat('a', 64)] as $token) {
            $this->get(route('tickets.guest', $token))->assertOk()->assertDontSee('Site down')->assertSee(__('ticket.guest_link_invalid'));
        }
    }

    public function test_an_expired_link_shows_nothing_and_mails_a_new_one_to_the_tickets_address(): void
    {
        $ticket = $this->guestTicket();
        $this->staffReply($ticket);
        $token = $this->mailedLink();
        $before = $this->sent()->count();
        $this->travel(GuestLinks::DAYS + 1)->days();

        Livewire::test(Guest::class, ['token' => $token])->assertDontSee('Site down')->assertSee(__('ticket.guest_link_resend'))
            ->call('resend')->assertSee(__('ticket.guest_link_resent'))
            ->set('message', 'still here')->call('reply');

        $this->assertSame(2, $ticket->messages()->count(), 'no answer by an expired link');
        $mail = $this->sent()->last();
        $this->assertSame($before + 1, $this->sent()->count());
        $this->assertSame('maria@example.com', $mail->getTo()[0]->getAddress());
        $this->assertNotSame($token, $this->mailedLink());
        $this->get(route('tickets.guest', $this->mailedLink()))->assertSee('Site down');
    }

    /** §3 Δ: linked to an account, its address changed, or revoked by staff: every link leads nowhere. */
    public function test_links_go_when_the_ticket_is_linked_its_address_changed_or_staff_revoke_them(): void
    {
        $ticket = $this->guestTicket();
        $one = basename(parse_url(GuestLinks::issue($ticket), PHP_URL_PATH));
        $ticket->update(['guest_email' => 'other@example.com']);
        $this->assertNull(GuestLinks::find($one));

        $two = basename(parse_url(GuestLinks::issue($ticket), PHP_URL_PATH));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->callAction('revokeGuestLinks');
        $this->assertNull(GuestLinks::find($two));

        $three = basename(parse_url(GuestLinks::issue($ticket), PHP_URL_PATH));
        $ticket->update(['client_id' => User::factory()->create()->client()->id]);
        $this->assertNull(GuestLinks::find($three));
        $this->get(route('tickets.guest', $three))->assertDontSee('Site down');
    }

    /** A page open when the link is revoked: its answer goes nowhere. */
    public function test_a_page_open_when_the_link_is_revoked_answers_nothing(): void
    {
        $ticket = $this->guestTicket();
        $token = basename(parse_url(GuestLinks::issue($ticket), PHP_URL_PATH));
        $page = Livewire::test(Guest::class, ['token' => $token]);
        GuestLinks::revoke($ticket);

        $page->set('message', 'late')->call('reply');

        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_files_by_the_link_only_of_messages_the_guest_may_see(): void
    {
        $ticket = $this->guestTicket();
        $public = $this->staffReply($ticket);
        $note = $this->staffReply($ticket, ['message' => 'note', 'is_internal' => true]);
        $other = $this->guestTicket();
        File::ensureDirectoryExists(storage_path('app/tickets/uploads'));
        $files = [];
        foreach (['public' => $public, 'note' => $note, 'other' => $other->messages()->sole()] as $name => $message) {
            file_put_contents(storage_path("app/tickets/uploads/guest-{$name}.txt"), $name);
            $files[$name] = $message->attachments()->create(['path' => "tickets/uploads/guest-{$name}.txt", 'filename' => "{$name}.txt", 'mime_type' => 'text/plain', 'filesize' => 4]);
        }
        $token = basename(parse_url(GuestLinks::issue($ticket), PHP_URL_PATH));

        $this->get(route('tickets.guest.attachment', ['token' => $token, 'attachment' => $files['public']->uuid]))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('tickets.guest.attachment', ['token' => $token, 'attachment' => $files['note']->uuid]))->assertNotFound();
        $this->get(route('tickets.guest.attachment', ['token' => $token, 'attachment' => $files['other']->uuid]))->assertNotFound();
        foreach ($files as $file) {
            @unlink($file->localPath);
        }
    }

    /** The «we received it» of a contact form ticket carries its link (PR B): seen only by whoever reads that inbox. */
    public function test_the_contact_forms_acknowledgement_carries_the_link(): void
    {
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            public function verify(string $payload): bool
            {
                return $payload === 'solved';
            }
        });
        $test = Livewire::test(Contact::class);
        foreach (['captcha' => 'solved', 'name' => 'Maria', 'email' => 'maria@example.com', 'subject' => 'Hello', 'message' => 'Hi'] as $field => $value) {
            $test->set($field, $value);
        }
        $test->set('department', $this->support->id)->call('submit')->assertHasNoErrors();

        // The address was typed in, so what came with it is not served by the link (review of #252): only the number.
        $ticket = Ticket::query()->sole();
        $this->get(route('tickets.guest', $this->mailedLink()))->assertOk()->assertSee($ticket->reference)
            ->assertDontSee('Hello')->assertSee(__('ticket.guest_message_hidden'));
    }
}
