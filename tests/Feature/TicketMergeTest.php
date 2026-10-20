<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Livewire\Tickets\Guest;
use App\Livewire\Tickets\Index as TicketIndex;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Support\Tickets\GuestLinks;
use App\Support\Tickets\MergeTickets;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\MessageId;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/** Staff merge a duplicate ticket into another of the same owner (docs/tickets.md §6κ). */
class TicketMergeTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private TicketDepartment $support;

    private FakeTicketMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($this->staff, 'staff');
    }

    private function sentCount(): int
    {
        return app('mailer')->getSymfonyTransport()->messages()->count();
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(array $attributes, string $status = Ticket::STATUS_OPEN): Ticket
    {
        return Ticket::query()->create($attributes + ['subject' => 'Help', 'status' => $status, 'priority' => 'low', 'department_id' => $this->support->id]);
    }

    /** A staff reply we mailed from $ticket: its Message-ID is the thread key. */
    private function mailedReply(Ticket $ticket): string
    {
        $key = MessageId::generate('myip.test');
        $message = TicketMessage::withoutEvents(fn () => TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $this->staff->id, 'message' => 'Our answer']));
        TicketMailId::query()->create(['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'message_id' => $key, 'kind' => TicketMailId::REPLY]);

        return $key;
    }

    private function receive(array $headers, string $text): void
    {
        static $n = 0;
        $headers += ['Message-ID' => '<merge' . (++$n) . '.' . bin2hex(random_bytes(4)) . '@example.com>'];
        $this->mailbox->unseen[] = Eml::make($headers, $text);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    public function test_the_duplicates_messages_mail_keys_and_links_move_and_it_is_closed_pointing_there(): void
    {
        $guest = ['guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'form', 'sender_vouched' => true];
        $kept = $this->ticket($guest, Ticket::STATUS_ANSWERED);
        $duplicate = $this->ticket(['guest_email' => 'MARIA@example.com'] + $guest);
        TicketMessage::withoutEvents(fn () => TicketMessage::query()->create(['ticket_id' => $duplicate->id, 'message' => 'Me again', 'via' => 'form']));
        $key = $this->mailedReply($duplicate);
        $token = basename((string) parse_url(GuestLinks::issue($duplicate), PHP_URL_PATH));
        $before = $this->sentCount();

        Livewire::test(EditTicket::class, ['record' => $duplicate->getRouteKey()])
            ->callAction(TestAction::make('merge')->schemaComponent('ticketActions', 'infolist'), ['target_id' => $kept->id])
            ->assertRedirect();

        $duplicate->refresh();
        $this->assertSame([Ticket::STATUS_CLOSED, $kept->id], [$duplicate->status, $duplicate->merged_into_id]);
        $this->assertSame(['Me again', 'Our answer'], $kept->messages()->where('is_internal', false)->orderBy('id')->pluck('message')->all());
        $this->assertSame($kept->id, TicketMailId::query()->where('message_id', $key)->sole()->ticket_id);
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $kept->fresh()->status, 'the duplicate waited on us: so does the one kept');
        $this->assertStringContainsString('#' . $duplicate->reference, $kept->messages()->where('is_internal', true)->sole()->message);
        $this->assertStringContainsString('#' . $kept->reference, $duplicate->messages()->where('is_internal', true)->sole()->message);
        $this->assertSame($before, $this->sentCount(), 'nothing mailed');
        Livewire::test(Guest::class, ['token' => $token])->assertSee('Me again')->assertSee('Our answer');
    }

    public function test_only_the_same_owners_tickets_merge(): void
    {
        $one = User::factory()->create();
        $other = User::factory()->create();
        $a = $this->ticket(['client_id' => $one->client()->id]);
        $b = $this->ticket(['client_id' => $one->client()->id]);
        $foreign = $this->ticket(['client_id' => $other->client()->id]);
        $guest = $this->ticket(['guest_name' => 'M', 'guest_email' => 'maria@example.com']);
        $otherGuest = $this->ticket(['guest_name' => 'E', 'guest_email' => 'eve@example.com']);
        $noAddress = $this->ticket(['guest_name' => 'X']);
        $noAddressToo = $this->ticket(['guest_name' => 'Y']);

        $this->assertTrue($a->canMergeInto($b));
        foreach ([[$a, $foreign], [$a, $guest], [$guest, $a], [$guest, $otherGuest], [$noAddress, $noAddressToo], [$a, $a]] as [$source, $target]) {
            $this->assertFalse($source->canMergeInto($target), "#{$source->id} into #{$target->id}");
        }
        app(MergeTickets::class)->handle($a, $b, $this->staff);
        $this->assertFalse($b->fresh()->canMergeInto($a->fresh()), 'not into one already merged');

        $this->expectException(InvalidArgumentException::class);
        app(MergeTickets::class)->handle($foreign, $b, $this->staff);
    }

    public function test_the_admin_offers_only_the_same_owners_tickets_and_refuses_another(): void
    {
        $owner = User::factory()->create();
        $a = $this->ticket(['client_id' => $owner->client()->id, 'subject' => 'Duplicate']);
        $b = $this->ticket(['client_id' => $owner->client()->id, 'subject' => 'Original']);
        $foreign = $this->ticket(['client_id' => User::factory()->create()->client()->id, 'subject' => 'Someone else']);

        Livewire::test(EditTicket::class, ['record' => $a->getRouteKey()])
            ->callAction(TestAction::make('merge')->schemaComponent('ticketActions', 'infolist'), ['target_id' => $foreign->id]);

        $this->assertNull($a->fresh()->merged_into_id, 'a crafted id of another customer is refused');
        $this->assertSame(0, $foreign->messages()->count());
        $options = (fn () => $this->mergeTargets($a))->call(Livewire::test(EditTicket::class, ['record' => $a->getRouteKey()])->instance());
        $this->assertSame([$b->id], array_keys($options));
    }

    public function test_a_reply_to_the_merged_tickets_mail_and_its_number_lead_to_the_one_kept(): void
    {
        $guest = ['guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email'];
        $kept = $this->ticket($guest);
        $duplicate = $this->ticket($guest);
        $key = $this->mailedReply($duplicate);
        app(MergeTickets::class)->handle($duplicate, $kept, $this->staff);
        $pass = 'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com';

        $this->receive(['From' => 'maria@example.com', $pass, 'In-Reply-To' => "<{$key}>", 'Subject' => 'Re: Help'], 'Thanks, works now');
        $this->assertSame('Thanks, works now', $kept->messages()->latest('id')->first()->message);
        $this->assertSame(0, $duplicate->messages()->where('is_internal', false)->count());

        $this->receive(['From' => 'maria@example.com', $pass, 'Subject' => "Re: [#{$duplicate->reference}] Help"], 'Old number');
        $this->assertSame($kept->id, TicketQuarantine::query()->latest('id')->first()->suggested_ticket_id);
    }

    public function test_the_customer_finds_the_merged_ticket_in_the_one_kept(): void
    {
        $owner = User::factory()->create();
        $kept = $this->ticket(['client_id' => $owner->client()->id, 'subject' => 'Original']);
        $duplicate = $this->ticket(['client_id' => $owner->client()->id, 'subject' => 'Duplicate subject']);
        app(MergeTickets::class)->handle($duplicate, $kept, $this->staff);

        $this->withSession($this->loginUser($owner))->get(route('tickets.show', $duplicate))->assertRedirect(route('tickets.show', $kept));
        $this->livewireAs($owner)->test(TicketIndex::class)->assertSee('Original')->assertDontSee('Duplicate subject');
    }

    public function test_a_merged_ticket_takes_no_more_replies_from_staff(): void
    {
        $owner = User::factory()->create();
        $kept = $this->ticket(['client_id' => $owner->client()->id]);
        $duplicate = $this->ticket(['client_id' => $owner->client()->id]);
        app(MergeTickets::class)->handle($duplicate, $kept, $this->staff);
        $before = $duplicate->messages()->count();

        Livewire::test(EditTicket::class, ['record' => $duplicate->getRouteKey()])->assertSee(__('Merged into ticket'))
            ->fillForm(['message' => 'Late reply'])->call('send')->call('note');

        $this->assertSame($before, $duplicate->messages()->count());
    }
}
