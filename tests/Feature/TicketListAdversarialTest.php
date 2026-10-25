<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Models\Audit;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketCc;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Support\Tickets\TicketBulkActions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/**
 * Adversarial review of #460 (docs/tickets.md §6μ): «With selected» on staff's ticket list.
 *
 * Tests named `test_defect_…` expose a defect and fail on the branch as reviewed; `test_refused_…` / `test_holds_…` are
 * attacks the branch already refuses, kept so they stay refused.
 */
class TicketListAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private TicketDepartment $sales;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('audit.console', true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create(['email' => 'boss@staff.example']);
        $this->sales = TicketDepartment::query()->create(['name' => 'Sales', 'email' => 'sales@ours.example']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(array $attributes = []): Ticket
    {
        $guest = !array_key_exists('client_id', $attributes);
        $ticket = Ticket::query()->create($attributes + ['subject' => 'Question', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->sales->id] + ($guest ? ['guest_name' => 'Guest Person', 'guest_email' => 'guest@guest.example', 'opened_via' => 'form'] : []));
        TicketMessage::withoutEvents(fn () => $ticket->messages()->create(['message' => 'First words', 'is_internal' => false]));

        return $ticket;
    }

    private function list(?Staff $staff = null, string $tab = 'all'): Testable
    {
        return $this->livewireAs($staff ?? $this->staff)->test(ListTickets::class)->set('activeTab', $tab);
    }

    private function block(Ticket $ticket, bool $wholeDomain = false): void
    {
        $this->list()->selectTableRecords([$ticket->id])
            ->callAction(TestAction::make('blockAndDelete')->table()->bulk(), data: ['whole_domain' => $wholeDomain]);
    }

    // ---- block sender & delete: customers' and our addresses ----

    /**
     * DEFECT (high): a customer whose login has an IDN domain (stored as typed, in Unicode) is blocked by a guest
     * ticket from that very address. isCustomersAddress() looks the logins up by the *ASCII* (xn--) domain of the
     * sender's base, so a login stored in Unicode is never found; the block is then stored as xn-- and matches the
     * customer's own mail (blocks() compares bases). Their mail to support is dropped silently from then on.
     */
    public function test_defect_a_customers_idn_login_is_blocked_when_written_in_unicode(): void
    {
        User::factory()->create(['email' => 'owner@bücher.example']);
        $guest = $this->ticket(['guest_email' => 'owner@bücher.example']);

        $this->block($guest);

        $this->assertFalse(TicketBlockedSender::blocks('owner@bücher.example'), 'a customer\'s login was blocked');
        $this->assertNotNull($guest->fresh(), 'the refusal deletes nothing');
    }

    /** DEFECT (high): the same, the guest writing the ASCII (xn--) form of a customer's Unicode login. */
    public function test_defect_a_customers_idn_login_is_blocked_when_written_in_punycode(): void
    {
        User::factory()->create(['email' => 'owner@bücher.example']);
        $guest = $this->ticket(['guest_email' => 'owner@xn--bcher-kva.example']);

        $this->block($guest);

        $this->assertFalse(TicketBlockedSender::blocks('owner@bücher.example'), 'a customer\'s login was blocked');
    }

    /**
     * DEFECT (high): «block the whole domain» compares the sender's domain as written with the logins' as stored. A
     * guest writing the Unicode form of a domain where a customer's login is stored in ASCII (xn--) gets the domain
     * blocked (normalise() stores it as xn--), and with it the customer.
     */
    public function test_defect_whole_domain_block_of_an_idn_domain_with_a_customers_login(): void
    {
        User::factory()->create(['email' => 'owner@xn--bcher-kva.example']);
        $guest = $this->ticket(['guest_email' => 'stranger@bücher.example']);

        $this->block($guest, wholeDomain: true);

        $this->assertFalse(TicketBlockedSender::blocks('owner@xn--bcher-kva.example'), 'a customer\'s domain was blocked');
        $this->assertSame(0, TicketBlockedSender::query()->count());
    }

    /**
     * DEFECT (high): base() treats googlemail.com as gmail.com, so blocking «@gmail.com» blocks every googlemail.com
     * address too; but the «a customer's login is at @domain» check only looks for logins at @gmail.com. A customer
     * whose login is at googlemail.com is blocked by a gmail spammer's whole-domain block. (Blocking a free-mail
     * domain at all also drops every future customer who writes from it: see the findings table.)
     */
    public function test_defect_whole_domain_block_of_gmail_blocks_a_customer_at_googlemail(): void
    {
        User::factory()->create(['email' => 'real.customer@googlemail.com']);
        $spam = $this->ticket(['guest_email' => 'spammer@gmail.com']);

        $this->block($spam, wholeDomain: true);

        $this->assertFalse(TicketBlockedSender::blocks('real.customer@googlemail.com'), 'a customer at googlemail.com was blocked');
        $this->assertNotNull($spam->fresh());
    }

    /**
     * DEFECT (medium): a member of staff's own address is not «one of our addresses». Staff CC'd on a guest's ticket
     * who answered by mail are among its senders; block-and-delete blocks them, and their next mail to support is
     * dropped (InboundRouter checks blocks() before anything).
     */
    public function test_defect_a_staff_members_address_among_the_senders_is_blocked(): void
    {
        $colleague = Staff::factory()->create(['email' => 'colleague@staff.example']);
        $spam = $this->ticket(['guest_email' => 'spammer@spam.example']);
        TicketCc::query()->create(['ticket_id' => $spam->id, 'email' => 'colleague@staff.example']);
        TicketMessage::withoutEvents(fn () => $spam->messages()->create(['message' => 'FYI', 'is_internal' => false, 'sender_email' => $colleague->email]));

        $this->block($spam);

        $this->assertFalse(TicketBlockedSender::blocks('colleague@staff.example'), 'a member of staff was blocked');
    }

    /** Refused already: the customer's login with a +tag, Gmail dots and googlemail spelling, in any case. */
    public function test_refused_a_customers_gmail_login_however_spelled(): void
    {
        User::factory()->create(['email' => 'Jane.Doe@gmail.com']);
        foreach (['janedoe+x@googlemail.com', 'J.A.N.E.D.O.E@GMAIL.COM', ' "jane.doe"@gmail.com '] as $spelling) {
            $this->block($this->ticket(['guest_email' => $spelling]));
        }

        $this->assertSame(0, TicketBlockedSender::query()->count());
        $this->assertSame(3, Ticket::query()->count());
    }

    /** Refused already: our department's address however spelled, and its domain. */
    public function test_refused_our_address_or_domain_however_spelled(): void
    {
        $this->block($this->ticket(['guest_email' => 'SALES+loop@Ours.Example']));
        $this->block($this->ticket(['guest_email' => 'anyone@ours.example']), wholeDomain: true);

        $this->assertSame(0, TicketBlockedSender::query()->count());
        $this->assertSame(2, Ticket::query()->count());
    }

    /** Refused already: a customer's login as a CC's reply in a guest ticket. */
    public function test_refused_a_customers_login_among_the_cc_senders(): void
    {
        User::factory()->create(['email' => 'owner@customer.example']);
        $spam = $this->ticket(['guest_email' => 'spammer@spam.example']);
        TicketMessage::withoutEvents(fn () => $spam->messages()->create(['message' => 'Me too', 'is_internal' => false, 'sender_email' => 'Owner+cc@Customer.example']));

        $this->block($spam);

        $this->assertSame(0, TicketBlockedSender::query()->count());
        $this->assertNotNull($spam->fresh());
    }

    /** Refused already: a client's ticket smuggled into a guest selection, with the whole domain asked for. */
    public function test_refused_whole_domain_block_with_a_clients_ticket_in_the_selection(): void
    {
        $owner = User::factory()->create(['email' => 'owner@client.example']);
        $clients = $this->ticket(['client_id' => $owner->client()->id]);
        $spam = $this->ticket(['guest_email' => 'spammer@spam.example']);

        $this->list()->selectTableRecords([$spam->id, $clients->id])
            ->callAction(TestAction::make('blockAndDelete')->table()->bulk(), data: ['whole_domain' => true])->assertNotified();

        $this->assertSame(0, TicketBlockedSender::query()->count());
        $this->assertSame(2, Ticket::query()->count());
    }

    /**
     * DEFECT (low): block-and-delete dismisses every pending quarantined mail of *any* blocked sender, not only the
     * senders of the tickets selected: mail left pending from a sender blocked earlier (e.g. by the Blocked Senders
     * page, which does not dismiss) is dismissed too, in this staff member's name, by an action that never named it.
     */
    public function test_defect_block_and_delete_dismisses_quarantine_of_unrelated_earlier_blocks(): void
    {
        TicketBlockedSender::query()->create(['value' => '@older.example']);
        $unrelated = TicketQuarantine::query()->create(['reason' => 'automated', 'from_email' => 'notice@older.example', 'raw_path' => 'q/old.eml', 'status' => TicketQuarantine::PENDING]);
        $spam = $this->ticket(['guest_email' => 'spammer@spam.example']);

        $this->block($spam);

        $this->assertNull($spam->fresh());
        $this->assertSame(TicketQuarantine::PENDING, $unrelated->fresh()->status, 'an unrelated sender\'s quarantined mail was dismissed');
    }

    /** Holds already: a refusal leaves nothing half done, and a success writes the audit with the senders. */
    public function test_holds_block_and_delete_audits_each_ticket_and_refusal_writes_nothing(): void
    {
        User::factory()->create(['email' => 'owner@customer.example']);
        $a = $this->ticket(['guest_email' => 'one@spam.example']);
        $b = $this->ticket(['guest_email' => 'owner@customer.example']);

        $this->list()->selectTableRecords([$a->id, $b->id])->callAction(TestAction::make('blockAndDelete')->table()->bulk())->assertNotified();
        $this->assertSame(0, TicketBlockedSender::query()->count());
        $this->assertSame(0, Audit::query()->where('event', 'blocked_and_deleted')->count());
        $this->assertSame(2, Ticket::query()->count());

        $this->block($a);
        $this->assertNull($a->fresh());
        $audit = Audit::query()->where('auditable_type', Ticket::class)->where('auditable_id', $a->id)->where('event', 'blocked_and_deleted')->sole();
        $this->assertSame(['one@spam.example'], $audit->new_values['senders']);
        $this->assertSame($this->staff->id, $audit->new_values['staff_id']);
    }

    // ---- merge ----

    /** Refused already: a target outside the selection (crafted target_id), of another client. */
    public function test_refused_merge_into_a_crafted_target_outside_the_selection(): void
    {
        $one = User::factory()->create();
        $other = User::factory()->create();
        $a = $this->ticket(['client_id' => $one->client()->id]);
        $b = $this->ticket(['client_id' => $one->client()->id]);
        $foreign = $this->ticket(['client_id' => $other->client()->id]);

        $this->list()->selectTableRecords([$a->id, $b->id])
            ->callAction(TestAction::make('merge')->table()->bulk(), data: ['target_id' => $foreign->id]);

        $this->assertNull($a->fresh()->merged_into_id);
        $this->assertNull($b->fresh()->merged_into_id);
        $this->assertSame(1, $foreign->messages()->count());
    }

    /** Refused already: a guest's ticket into a client's whose login is the guest's address; and +tag / Gmail spellings. */
    public function test_refused_merge_guest_into_client_or_differently_spelled_guest(): void
    {
        $owner = User::factory()->create(['email' => 'owner@client.example']);
        $clients = $this->ticket(['client_id' => $owner->client()->id]);
        $guest = $this->ticket(['guest_email' => 'owner@client.example']);
        $tagged = $this->ticket(['guest_email' => 'owner+x@client.example']);

        $this->list()->selectTableRecords([$clients->id, $guest->id])->callAction(TestAction::make('merge')->table()->bulk(), data: ['target_id' => $clients->id]);
        $this->list()->selectTableRecords([$guest->id, $tagged->id])->callAction(TestAction::make('merge')->table()->bulk(), data: ['target_id' => $guest->id]);

        $this->assertSame(0, Ticket::query()->whereNotNull('merged_into_id')->count());
        $this->assertSame(1, $clients->messages()->count());
    }

    /** Holds already: the same guest in other case and spaces merges, with CCs, Message-IDs and guest links. */
    public function test_holds_same_guest_in_other_case_merges_with_ccs_and_threading(): void
    {
        $kept = $this->ticket(['guest_email' => 'guest@guest.example']);
        $dup = $this->ticket(['guest_email' => ' Guest@GUEST.example ']);
        TicketCc::query()->create(['ticket_id' => $dup->id, 'email' => 'cc@third.example']);
        $reply = TicketMessage::withoutEvents(fn () => $dup->messages()->create(['message' => 'Our answer', 'is_internal' => false, 'staff_id' => $this->staff->id]));
        TicketMailId::query()->create(['ticket_id' => $dup->id, 'ticket_message_id' => $reply->id, 'message_id' => 'tk.dup@ours.example', 'kind' => TicketMailId::REPLY]);

        $this->list()->selectTableRecords([$kept->id, $dup->id])->callAction(TestAction::make('merge')->table()->bulk())->assertHasNoActionErrors();

        $this->assertSame($kept->id, $dup->fresh()->merged_into_id);
        $this->assertSame($kept->id, TicketMailId::query()->where('message_id', 'tk.dup@ours.example')->value('ticket_id'));
        $this->assertTrue(TicketCc::query()->where('ticket_id', $kept->id)->where('email', 'cc@third.example')->exists());
        $this->assertSame(0, TicketMessage::query()->where('ticket_id', $dup->id)->where('is_internal', false)->count());
    }

    /** Holds already: one ticket merged elsewhere meanwhile (stale selection) undoes the whole merge. */
    public function test_holds_a_ticket_merged_meanwhile_undoes_the_whole_merge(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();
        $c = $this->ticket();
        $elsewhere = $this->ticket();
        $selection = Ticket::query()->whereIn('id', [$a->id, $b->id, $c->id])->get();
        // Another member of staff merges $c meanwhile; our models are stale.
        Ticket::query()->whereKey($c->id)->update(['merged_into_id' => $elsewhere->id, 'status' => Ticket::STATUS_CLOSED]);

        try {
            app(TicketBulkActions::class)->merge($selection, $selection->firstWhere('id', $a->id), $this->staff);
            $this->fail('The merge went ahead.');
        } catch (InvalidArgumentException) {
        }

        $this->assertNull($b->fresh()->merged_into_id, 'the merge before the refusal was kept');
        $this->assertSame(1, $b->messages()->count());
        $this->assertSame(1, $a->messages()->count());
    }

    /** Refused already: merge without «Update Tickets», called straight at the Livewire action. */
    public function test_refused_merge_and_delete_without_the_permission(): void
    {
        $deleter = Staff::factory()->withPermissions(['admin.tickets.viewAny', 'admin.tickets.deleteAny'])->create();
        $updater = Staff::factory()->withPermissions(['admin.tickets.viewAny', 'admin.tickets.update'])->create();
        $a = $this->ticket();
        $b = $this->ticket();

        foreach ([[$deleter, 'merge'], [$deleter, 'pin'], [$updater, 'delete'], [$updater, 'blockAndDelete']] as [$staff, $name]) {
            $list = $this->list($staff)->selectTableRecords([$a->id, $b->id]);
            $list->assertActionHidden(TestAction::make($name)->table()->bulk());
            $this->assertFalse($list->instance()->getTable()->getBulkAction($name)->isAuthorized(), $name);
            // Called anyway, the request is refused and nothing runs.
            $list->call('mountAction', $name, [], ['table' => true, 'bulk' => true]);
        }

        $this->assertSame(0, Ticket::query()->whereNotNull('pinned_at')->count());
        $this->assertSame(2, Ticket::query()->whereNull('merged_into_id')->count());
        $this->assertSame(0, TicketBlockedSender::query()->count());
    }

    // ---- delete ----

    /**
     * DEFECT (low): deleting a ticket others were merged into un-merges them (merged_into_id is nullOnDelete): they
     * come back on the list as ordinary closed tickets with no messages but «Merged into #…» (a ticket gone), and a
     * reply to their number lands in an empty husk. The modal says neither; it should refuse, or say and delete them.
     */
    public function test_defect_deleting_a_survivor_resurrects_its_merged_tickets(): void
    {
        $survivor = $this->ticket();
        $merged = $this->ticket(['status' => Ticket::STATUS_CLOSED]);
        $merged->forceFill(['merged_into_id' => $survivor->id])->save();

        $this->list()->selectTableRecords([$survivor->id])->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertNull($survivor->fresh());
        $this->assertTrue($merged->fresh() === null || $merged->fresh()->merged_into_id !== null,
            'a merged ticket was left as an ordinary ticket pointing nowhere');
    }

    // ---- XSS and LIKE ----

    /** Holds already: subject, guest name and address are escaped in the columns and in each modal. */
    public function test_holds_markup_in_subject_guest_name_and_email_is_escaped(): void
    {
        $evil = $this->ticket(['subject' => '<script>alert(1)</script>', 'guest_name' => '<img src=x onerror=alert(2)>', 'guest_email' => 'guest@guest.example']);
        TicketMessage::withoutEvents(fn () => $evil->messages()->create(['message' => 'x', 'is_internal' => false, 'sender_email' => '"<svg/onload=alert(3)>"@spam.example']));
        $twin = $this->ticket();

        $this->list()->assertDontSeeHtml('<script>alert(1)</script>')->assertDontSeeHtml('<img src=x onerror=alert(2)>');
        foreach (['delete', 'blockAndDelete', 'merge'] as $name) {
            $this->list()->selectTableRecords([$evil->id, $twin->id])->mountAction(TestAction::make($name)->table()->bulk())
                ->assertDontSeeHtml('<script>alert(1)</script>')->assertDontSeeHtml('<img src=x onerror=alert(2)>')
                ->assertDontSeeHtml('<svg/onload=alert(3)>');
        }
    }

    /** Holds already: LIKE wildcards in the e-mail and number filters are literal. */
    public function test_holds_like_wildcards_in_the_email_and_number_filters(): void
    {
        $a = $this->ticket(['guest_email' => 'alpha@guest.example']);
        $b = $this->ticket(['guest_email' => 'beta@guest.example']);

        $this->list()->filterTable('email', ['email' => '%'])->assertCanNotSeeTableRecords([$a, $b]);
        $this->list()->filterTable('email', ['email' => '_lpha'])->assertCanNotSeeTableRecords([$a, $b]);
        $this->list()->filterTable('number', ['number' => '%'])->assertCanNotSeeTableRecords([$a, $b]);
    }
}
