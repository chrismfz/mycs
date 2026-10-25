<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketBlockedSenderResource\Pages\ManageTicketBlockedSenders;
use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Models\Audit;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Support\Tickets\TicketFiles;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/** Staff's ticket list after WHMCS's (owner 2026-10-25, docs/tickets.md §6μ): the search panel, the columns, «With selected». */
class TicketListTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private TicketDepartment $sales;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('audit.console', true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
        $this->sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $this->support = TicketDepartment::query()->create(['name' => 'Support', 'email' => 'support@ours.test']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(array $attributes = []): Ticket
    {
        $guest = !array_key_exists('client_id', $attributes);
        $ticket = Ticket::query()->create($attributes + ['subject' => 'Question', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->sales->id] + ($guest ? ['guest_name' => 'Nikos Guest', 'guest_email' => 'nikos@guest.example', 'opened_via' => 'form'] : []));
        // Without events: a customer's message would reopen an answered or closed ticket.
        TicketMessage::withoutEvents(fn () => $ticket->messages()->create(['message' => 'First words', 'is_internal' => false]));

        return $ticket;
    }

    private function list(?Staff $staff = null, string $tab = 'all'): Testable
    {
        return $this->livewireAs($staff ?? $this->staff)->test(ListTickets::class)->set('activeTab', $tab);
    }

    // ---- columns and search ----

    public function test_columns_show_the_number_and_subject_as_one_link_the_guest_badge_and_the_pin_first(): void
    {
        $guest = $this->ticket(['subject' => 'Quote for VPS']);
        $client = User::factory()->create();
        $theirs = $this->ticket(['client_id' => $client->client()->id, 'subject' => 'Server down']);
        $theirs->forceFill(['pinned_at' => now()])->save();

        $this->list()
            ->assertSee('#' . $guest->reference . ' – Quote for VPS')
            ->assertSee('nikos@guest.example')
            ->assertSeeHtml('uppercase tracking-wide')
            ->assertCanSeeTableRecords([$theirs, $guest], inOrder: true);
    }

    public function test_the_search_box_finds_by_number_subject_and_id(): void
    {
        $a = $this->ticket(['subject' => 'Invoice charged twice']);
        $b = $this->ticket(['subject' => 'DNS not resolving']);

        $this->list()->searchTable($b->reference)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        $this->list()->searchTable('charged')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        $this->list()->searchTable((string) $a->id)->assertCanSeeTableRecords([$a]);
    }

    // ---- filters ----

    public function test_client_department_status_and_priority_filters(): void
    {
        $owner = User::factory()->create();
        $theirs = $this->ticket(['client_id' => $owner->client()->id, 'department_id' => $this->support->id, 'priority' => 'high']);
        $guest = $this->ticket(['status' => Ticket::STATUS_CUSTOMER_REPLY, 'priority' => 'low']);
        $answered = $this->ticket(['status' => Ticket::STATUS_ANSWERED]);

        $this->list()->filterTable('client', $owner->client()->id)->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$guest, $answered]);
        $this->list()->filterTable('department_id', [$this->support->id])->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$guest]);
        $this->list()->filterTable('department_id', [$this->support->id, $this->sales->id])->assertCanSeeTableRecords([$theirs, $guest, $answered]);
        $this->list()->filterTable('status', [Ticket::STATUS_OPEN, Ticket::STATUS_CUSTOMER_REPLY])
            ->assertCanSeeTableRecords([$theirs, $guest])->assertCanNotSeeTableRecords([$answered]);
        $this->list()->filterTable('priority', ['high', 'low'])->assertCanSeeTableRecords([$theirs, $guest])->assertCanNotSeeTableRecords([$answered]);
    }

    public function test_subject_or_message_text_filter_reads_every_message_and_takes_wildcards_literally(): void
    {
        $bySubject = $this->ticket(['subject' => 'Backup restore']);
        $byMessage = $this->ticket(['subject' => 'Help']);
        $byMessage->messages()->create(['message' => 'Please restore my backup', 'is_internal' => false]);
        $byNote = $this->ticket(['subject' => 'Other']);
        $byNote->messages()->create(['message' => 'internal: backup checked', 'is_internal' => true]);
        $none = $this->ticket(['subject' => 'Nothing']);

        $this->list()->filterTable('text', ['text' => 'backup'])
            ->assertCanSeeTableRecords([$bySubject, $byMessage, $byNote])->assertCanNotSeeTableRecords([$none]);
        $this->list()->filterTable('text', ['text' => '%'])->assertCanNotSeeTableRecords([$bySubject, $none]);
    }

    public function test_email_filter_finds_the_guest_the_accounts_login_and_a_cc_sender(): void
    {
        $guest = $this->ticket(['guest_email' => 'maria@guest.example']);
        $owner = User::factory()->create(['email' => 'owner@client.example']);
        $theirs = $this->ticket(['client_id' => $owner->client()->id]);
        $copied = $this->ticket(['guest_email' => 'someone@else.example']);
        $copied->messages()->create(['message' => 'Me too', 'is_internal' => false, 'sender_email' => 'cc.person@third.example']);

        $this->list()->filterTable('email', ['email' => 'MARIA@guest'])->assertCanSeeTableRecords([$guest])->assertCanNotSeeTableRecords([$theirs, $copied]);
        $this->list()->filterTable('email', ['email' => 'owner@client'])->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$guest]);
        $this->list()->filterTable('email', ['email' => 'cc.person'])->assertCanSeeTableRecords([$copied])->assertCanNotSeeTableRecords([$guest, $theirs]);
    }

    public function test_number_filter_by_reference_with_or_without_hash_or_by_id(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();

        $this->list()->filterTable('number', ['number' => '#' . $a->reference])->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        $this->list()->filterTable('number', ['number' => (string) $b->id])->assertCanSeeTableRecords([$b]);
    }

    public function test_assigned_filter_me_unassigned_or_a_staff_member(): void
    {
        $other = Staff::factory()->admin()->create();
        $mine = $this->ticket(['assigned_to' => $this->staff->id]);
        $theirs = $this->ticket(['assigned_to' => $other->id]);
        $nobodys = $this->ticket();

        $this->list()->filterTable('assigned', 'me')->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs, $nobodys]);
        $this->list()->filterTable('assigned', 'none')->assertCanSeeTableRecords([$nobodys])->assertCanNotSeeTableRecords([$mine, $theirs]);
        $this->list()->filterTable('assigned', (string) $other->id)->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$mine]);
    }

    public function test_pinned_guests_and_merged_tickets(): void
    {
        $owner = User::factory()->create();
        $pinned = $this->ticket(['client_id' => $owner->client()->id]);
        $pinned->forceFill(['pinned_at' => now()])->save();
        $guest = $this->ticket();
        $merged = $this->ticket(['status' => Ticket::STATUS_CLOSED]);
        $merged->forceFill(['merged_into_id' => $guest->id])->save();

        $this->list()->assertCanNotSeeTableRecords([$merged]);
        $this->list()->filterTable('show', ['merged' => true])->assertCanSeeTableRecords([$merged]);
        $this->list()->filterTable('show', ['pinned' => true])->assertCanSeeTableRecords([$pinned])->assertCanNotSeeTableRecords([$guest]);
        $this->list()->filterTable('show', ['guests' => true])->assertCanSeeTableRecords([$guest])->assertCanNotSeeTableRecords([$pinned]);
    }

    public function test_filters_hold_inside_the_tab(): void
    {
        $open = $this->ticket(['department_id' => $this->support->id]);
        $openSales = $this->ticket();
        $closed = $this->ticket(['status' => Ticket::STATUS_CLOSED, 'department_id' => $this->support->id]);

        $this->list(tab: 'open')->filterTable('department_id', [$this->support->id])
            ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$openSales, $closed]);
        $this->list(tab: 'closed')->filterTable('status', [Ticket::STATUS_OPEN])->assertCanNotSeeTableRecords([$open, $openSales, $closed]);
    }

    // ---- pin, close ----

    public function test_pin_and_unpin_are_audited_and_put_the_ticket_first(): void
    {
        $old = $this->ticket();
        $new = $this->ticket();

        $this->list()->selectTableRecords([$old->id])->callAction(TestAction::make('pin')->table()->bulk())->assertHasNoActionErrors();
        $this->assertNotNull($old->fresh()->pinned_at);
        $this->list()->assertCanSeeTableRecords([$old, $new], inOrder: true);
        $this->assertTrue(Audit::query()->where('auditable_type', Ticket::class)->where('auditable_id', $old->id)->where('event', 'updated')
            ->where('new_values', 'like', '%pinned_at%')->exists());

        $this->list()->selectTableRecords([$old->id])->callAction(TestAction::make('unpin')->table()->bulk());
        $this->assertNull($old->fresh()->pinned_at);
    }

    public function test_close_closes_every_selected_ticket(): void
    {
        $a = $this->ticket();
        $b = $this->ticket(['status' => Ticket::STATUS_ANSWERED]);
        $c = $this->ticket();

        $this->list()->selectTableRecords([$a->id, $b->id])->callAction(TestAction::make('close')->table()->bulk());
        $this->assertSame([Ticket::STATUS_CLOSED, Ticket::STATUS_CLOSED, Ticket::STATUS_OPEN], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
    }

    // ---- merge ----

    public function test_merge_into_the_oldest_keeps_senders_files_and_threading(): void
    {
        $oldest = $this->ticket(['subject' => 'Quote']);
        $dup = $this->ticket(['subject' => 'Quote again']);
        $staffReply = $dup->messages()->create(['message' => 'Our answer', 'is_internal' => false, 'staff_id' => $this->staff->id]);
        $cc = $dup->messages()->create(['message' => 'From the CC', 'is_internal' => false, 'sender_email' => 'cc@third.example']);
        $file = TicketMessageAttachment::query()->create(['ticket_message_id' => $cc->id, 'path' => 'tickets/x.txt', 'filename' => 'x.txt', 'filesize' => 1, 'mime_type' => 'text/plain']);
        TicketMailId::query()->create(['ticket_id' => $dup->id, 'ticket_message_id' => $staffReply->id, 'message_id' => 'tk.abc@ours.test', 'kind' => TicketMailId::REPLY]);

        $this->list()->selectTableRecords([$dup->id, $oldest->id])
            ->callAction(TestAction::make('merge')->table()->bulk())->assertHasNoActionErrors();

        $dup->refresh();
        $this->assertSame($oldest->id, $dup->merged_into_id);
        $this->assertSame(Ticket::STATUS_CLOSED, $dup->status);
        $this->assertSame($oldest->id, $staffReply->fresh()->ticket_id);
        $this->assertSame($this->staff->id, $staffReply->fresh()->staff_id);
        $this->assertSame('cc@third.example', $cc->fresh()->sender_email);
        $this->assertSame($oldest->id, $file->fresh()->ticketMessage->ticket_id);
        $this->assertSame($oldest->id, TicketMailId::query()->where('message_id', 'tk.abc@ours.test')->value('ticket_id'));
    }

    public function test_merge_into_a_chosen_ticket_of_three(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();
        $c = $this->ticket();

        $this->list()->selectTableRecords([$a->id, $b->id, $c->id])
            ->callAction(TestAction::make('merge')->table()->bulk(), data: ['target_id' => $b->id])->assertHasNoActionErrors();

        $this->assertSame([$b->id, null, $b->id], [$a->fresh()->merged_into_id, $b->fresh()->merged_into_id, $c->fresh()->merged_into_id]);
        $this->assertSame(3, $b->messages()->where('is_internal', false)->count());
    }

    /** Decided: tickets of different clients (or guest addresses) are never merged; the whole merge is refused. */
    public function test_merge_of_different_clients_or_an_already_merged_ticket_changes_nothing(): void
    {
        $one = User::factory()->create();
        $other = User::factory()->create();
        $a = $this->ticket(['client_id' => $one->client()->id]);
        $b = $this->ticket(['client_id' => $one->client()->id]);
        $foreign = $this->ticket(['client_id' => $other->client()->id]);

        $this->list()->selectTableRecords([$a->id, $b->id, $foreign->id])
            ->callAction(TestAction::make('merge')->table()->bulk(), data: ['target_id' => $a->id])
            ->assertNotified();
        $this->assertNull($b->fresh()->merged_into_id);
        $this->assertNull($foreign->fresh()->merged_into_id);
        $this->assertSame(1, $foreign->messages()->count());

        $guestA = $this->ticket(['guest_email' => 'one@guest.example']);
        $guestB = $this->ticket(['guest_email' => 'two@guest.example']);
        $this->list()->selectTableRecords([$guestA->id, $guestB->id])->callAction(TestAction::make('merge')->table()->bulk());
        $this->assertNull($guestB->fresh()->merged_into_id);

        $merged = $this->ticket(['client_id' => $one->client()->id]);
        $merged->forceFill(['merged_into_id' => $a->id, 'status' => Ticket::STATUS_CLOSED])->save();
        $this->list()->filterTable('show', ['merged' => true])->selectTableRecords([$a->id, $merged->id])
            ->callAction(TestAction::make('merge')->table()->bulk(), data: ['target_id' => $merged->id]);
        $this->assertNull($a->fresh()->merged_into_id);
    }

    // ---- delete ----

    public function test_delete_deletes_for_good_and_audits_each(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();
        $keep = $this->ticket();

        $this->list()->selectTableRecords([$a->id, $b->id])->callAction(TestAction::make('delete')->table()->bulk())->assertHasNoActionErrors();

        $this->assertSame([$keep->id], Ticket::query()->pluck('id')->all());
        $this->assertSame(0, TicketMessage::query()->whereIn('ticket_id', [$a->id, $b->id])->count());
        $this->assertSame(2, Audit::query()->where('auditable_type', Ticket::class)->where('event', 'deleted')->whereIn('auditable_id', [$a->id, $b->id])->count());
    }

    // ---- block sender & delete ----

    public function test_block_and_delete_blocks_every_sender_dismisses_their_quarantine_and_audits(): void
    {
        $spam = $this->ticket(['guest_email' => 'Promo@Spam.example']);
        $spam->messages()->create(['message' => 'More', 'is_internal' => false, 'sender_email' => 'friend@spam2.example']);
        $other = $this->ticket(['guest_email' => 'real@person.example']);
        $waiting = TicketQuarantine::query()->create(['reason' => 'spam', 'from_email' => 'promo+x@spam.example', 'raw_path' => 'q/1.eml', 'status' => TicketQuarantine::PENDING]);
        $unrelated = TicketQuarantine::query()->create(['reason' => 'spam', 'from_email' => 'real@person.example', 'raw_path' => 'q/2.eml', 'status' => TicketQuarantine::PENDING]);

        $this->list()->selectTableRecords([$spam->id])->callAction(TestAction::make('blockAndDelete')->table()->bulk())->assertHasNoActionErrors();

        $this->assertNull($spam->fresh());
        $this->assertNotNull($other->fresh());
        $this->assertEqualsCanonicalizing(['promo@spam.example', 'friend@spam2.example'], TicketBlockedSender::query()->pluck('value')->all());
        $this->assertTrue(TicketBlockedSender::blocks('promo+later@spam.example'));
        $this->assertSame(TicketQuarantine::DISMISSED, $waiting->fresh()->status);
        $this->assertSame(TicketQuarantine::PENDING, $unrelated->fresh()->status);
        $audit = Audit::query()->where('auditable_type', Ticket::class)->where('auditable_id', $spam->id)->where('event', 'blocked_and_deleted')->sole();
        $this->assertContains('promo@spam.example', $audit->new_values['blocked']);
    }

    public function test_block_the_whole_domain(): void
    {
        $spam = $this->ticket(['guest_email' => 'a@spam.example']);

        $this->list()->selectTableRecords([$spam->id])
            ->callAction(TestAction::make('blockAndDelete')->table()->bulk(), data: ['whole_domain' => true])->assertHasNoActionErrors();

        $this->assertSame(['@spam.example'], TicketBlockedSender::query()->pluck('value')->all());
        $this->assertTrue(TicketBlockedSender::blocks('anyone@spam.example'));
    }

    public function test_block_refuses_a_clients_ticket_our_address_a_customers_login_or_their_domain(): void
    {
        $owner = User::factory()->create(['email' => 'owner@customer.example']);
        $clients = $this->ticket(['client_id' => $owner->client()->id]);
        $guest = $this->ticket(['guest_email' => 'spam@guest.example']);
        $ours = $this->ticket(['guest_email' => 'Support@ours.test']);
        $login = $this->ticket(['guest_email' => 'owner+tag@customer.example']);
        $sameDomain = $this->ticket(['guest_email' => 'stranger@customer.example']);

        foreach ([[$clients, $guest], [$ours], [$login]] as $selection) {
            $this->list()->selectTableRecords(array_map(fn (Ticket $ticket) => $ticket->id, $selection))
                ->callAction(TestAction::make('blockAndDelete')->table()->bulk())->assertNotified();
        }
        $this->list()->selectTableRecords([$sameDomain->id])
            ->callAction(TestAction::make('blockAndDelete')->table()->bulk(), data: ['whole_domain' => true])->assertNotified();

        $this->assertSame(0, TicketBlockedSender::query()->count());
        $this->assertSame(5, Ticket::query()->whereIn('id', [$clients->id, $guest->id, $ours->id, $login->id, $sameDomain->id])->count());
    }

    // ---- permissions ----

    public function test_without_permission_the_actions_are_refused(): void
    {
        $viewer = Staff::factory()->withPermissions(['admin.tickets.viewAny'])->create();
        $deleter = Staff::factory()->withPermissions(['admin.tickets.viewAny', 'admin.tickets.deleteAny'])->create();
        $a = $this->ticket();
        $b = $this->ticket();

        $visible = fn (Staff $staff) => array_values(array_filter(['pin', 'unpin', 'merge', 'close', 'delete', 'blockAndDelete'],
            fn (string $name) => $this->list($staff)->instance()->getTable()->getBulkAction($name)?->isVisible()));
        $this->assertSame([], $visible($viewer));
        // Delete alone does not let them block.
        $this->assertSame(['delete'], $visible($deleter));
        $this->assertSame(['pin', 'unpin', 'merge', 'close', 'delete', 'blockAndDelete'], $visible($this->staff));

        // Hidden is also unauthorized: Filament refuses to run it, whatever the request says.
        $this->list($viewer)->selectTableRecords([$a->id, $b->id])->assertActionHidden(TestAction::make('delete')->table()->bulk());
        foreach (['delete', 'merge'] as $name) {
            $this->assertFalse($this->list($viewer)->instance()->getTable()->getBulkAction($name)->isAuthorized());
        }
        $this->assertFalse($this->list($deleter)->instance()->getTable()->getBulkAction('blockAndDelete')->isAuthorized());
        $this->assertSame(2, Ticket::query()->whereNull('merged_into_id')->count());
        $this->assertSame(0, TicketBlockedSender::query()->count());
        $this->assertSame(0, DB::table('tickets')->whereNotNull('pinned_at')->count());
    }

    // ---- independent review of #460 ----

    /** No list of free mail providers (owner 2026-10-25): their domains are refused because customers log in there. */
    public function test_a_free_mail_domain_is_refused_whole_when_a_customer_logs_in_there(): void
    {
        User::factory()->create(['email' => 'customer@outlook.com']);
        $spam = $this->ticket(['guest_email' => 'spammer@Outlook.com']);

        $this->list()->selectTableRecords([$spam->id])
            ->callAction(TestAction::make('blockAndDelete')->table()->bulk(), data: ['whole_domain' => true])->assertNotified();

        $this->assertSame(0, TicketBlockedSender::query()->count());
        $this->assertNotNull($spam->fresh());
    }

    public function test_blocked_senders_show_who_blocked_from_which_tickets_and_take_star_at_domain(): void
    {
        $spam = $this->ticket(['guest_email' => 'promo@spam.example']);
        $this->list()->selectTableRecords([$spam->id])->callAction(TestAction::make('blockAndDelete')->table()->bulk(), data: ['whole_domain' => true]);

        $block = TicketBlockedSender::query()->sole();
        $this->assertSame('@spam.example', $block->value);
        $this->assertSame($this->staff->id, $block->created_by);
        $this->assertStringContainsString('#' . $spam->reference, (string) $block->note);
        $this->assertSame('@other.example', TicketBlockedSender::normalise('*@Other.example'));

        $this->livewireAs($this->staff)->test(ManageTicketBlockedSenders::class)
            ->assertSee('*@spam.example')->assertSee($this->staff->name)->assertSee('#' . $spam->reference)
            ->callAction('create', data: ['value' => '*@third.example'])->assertHasNoActionErrors();
        $this->assertTrue(TicketBlockedSender::blocks('anyone@third.example'));
    }

    public function test_deleting_removes_the_files_from_disk_but_not_a_file_another_message_names(): void
    {
        $doomed = $this->ticket();
        $kept = $this->ticket();
        $dir = storage_path('app/' . TicketFiles::DIRECTORY);
        File::ensureDirectoryExists($dir);
        file_put_contents($dir . '/only-mine.txt', 'x');
        file_put_contents($dir . '/shared.txt', 'x');
        $mine = $doomed->messages()->first();
        $theirs = $kept->messages()->first();
        foreach ([[$mine, 'only-mine.txt'], [$mine, 'shared.txt'], [$theirs, 'shared.txt']] as [$message, $name]) {
            TicketMessageAttachment::query()->create(['ticket_message_id' => $message->id, 'path' => TicketFiles::DIRECTORY . '/' . $name,
                'filename' => $name, 'filesize' => 1, 'mime_type' => 'text/plain']);
        }

        $this->list()->selectTableRecords([$doomed->id])->callAction(TestAction::make('delete')->table()->bulk())->assertHasNoActionErrors();

        $this->assertFileDoesNotExist($dir . '/only-mine.txt');
        $this->assertFileExists($dir . '/shared.txt');
        File::delete($dir . '/shared.txt');
    }

    public function test_a_merged_ticket_is_found_by_its_number(): void
    {
        $survivor = $this->ticket();
        $merged = $this->ticket(['status' => Ticket::STATUS_CLOSED]);
        $merged->forceFill(['merged_into_id' => $survivor->id])->save();

        $this->list()->searchTable('#' . $merged->reference)->assertCanSeeTableRecords([$merged]);
        $this->list()->filterTable('number', ['number' => $merged->reference])->assertCanSeeTableRecords([$merged]);
        $this->list()->searchTable('Question')->assertCanNotSeeTableRecords([$merged]);
    }

    public function test_percent_and_underscore_are_searched_literally(): void
    {
        $offer = $this->ticket(['subject' => 'Get 50% off_now']);
        $other = $this->ticket(['subject' => 'Get 500 offers']);

        $this->list()->searchTable('50%')->assertCanSeeTableRecords([$offer])->assertCanNotSeeTableRecords([$other]);
        $this->list()->filterTable('text', ['text' => 'off_now'])->assertCanSeeTableRecords([$offer])->assertCanNotSeeTableRecords([$other]);
    }
}
