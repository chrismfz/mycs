<?php

namespace Tests\Feature;

use App\Admin\Resources\CannedReplyResource;
use App\Admin\Resources\CannedReplyResource\Pages\ManageCannedReplies;
use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Models\CannedReply;
use App\Models\CannedReplyCategory;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Import\WhmcsTicketImport;
use App\Support\Tickets\CannedReplyExpander;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

/** Predefined replies (docs/tickets.md §6ι): kept by staff, inserted into a reply with its tokens filled, brought from WHMCS. */
class CannedRepliesTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Staff', 'role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($this->staff, 'staff');
    }

    private function ticket(): Ticket
    {
        $user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Pappa']);
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Invoice', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'Help']);

        return $ticket;
    }

    public function test_a_reply_is_inserted_after_what_staff_wrote_with_its_tokens_filled(): void
    {
        $ticket = $this->ticket();
        $reply = CannedReply::query()->create(['title' => 'Bank', 'body' => 'Dear {{client.first_name}}, ticket {{ticket.reference}}. {{unknown.token}} — {{staff.name}}']);

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->fillForm(['message' => 'Hello'])->fillForm(['canned' => $reply->id])
            ->assertSchemaStateSet(['message' => "Hello\n\nDear Maria, ticket {$ticket->reference}. {{unknown.token}} — Nikos Staff", 'canned' => null])
            ->call('send')->assertHasNoErrors();

        $this->assertStringStartsWith("Hello\n\nDear Maria", TicketMessage::query()->latest('id')->first()->message);
    }

    public function test_a_guests_name_and_address_fill_the_tokens(): void
    {
        $ticket = Ticket::query()->create(['subject' => 'Q', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low', 'guest_name' => 'Eleni Guest', 'guest_email' => 'eleni@example.com']);

        $this->assertSame('Eleni / Eleni Guest / eleni@example.com', CannedReplyExpander::expand('{{client.first_name}} / {{ client.name }} / {{CLIENT.EMAIL}}', $ticket));
    }

    public function test_an_inactive_reply_is_not_offered_nor_inserted(): void
    {
        $ticket = $this->ticket();
        $off = CannedReply::query()->create(['title' => 'Old', 'body' => 'Old text', 'is_active' => false]);

        $this->assertSame([], CannedReply::options());
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])->fillForm(['message' => 'Hi'])->fillForm(['canned' => $off->id])
            ->assertSchemaStateSet(['message' => 'Hi']);
    }

    public function test_replies_are_offered_by_category_in_order(): void
    {
        $invoices = CannedReplyCategory::query()->create(['name' => 'Invoices', 'sort' => 1]);
        $domains = CannedReplyCategory::query()->create(['name' => 'Domains', 'sort' => 2]);
        $b = CannedReply::query()->create(['title' => 'B', 'body' => 'b', 'canned_reply_category_id' => $invoices->id, 'sort' => 2]);
        $a = CannedReply::query()->create(['title' => 'A', 'body' => 'a', 'canned_reply_category_id' => $invoices->id, 'sort' => 1]);
        $d = CannedReply::query()->create(['title' => 'D', 'body' => 'd', 'canned_reply_category_id' => $domains->id]);
        $g = CannedReply::query()->create(['title' => 'G', 'body' => 'g']);

        $this->assertSame(['Invoices' => [$a->id => 'A', $b->id => 'B'], 'Domains' => [$d->id => 'D'], __('General') => [$g->id => 'G']], CannedReply::options());
    }

    public function test_staff_keep_them_in_the_admin(): void
    {
        Livewire::test(ManageCannedReplies::class)->assertOk()
            ->callAction('create', ['title' => 'Bank accounts', 'body' => 'IBAN …', 'sort' => 0, 'is_active' => true])->assertHasNoActionErrors();

        $this->assertSame('Bank accounts', CannedReply::query()->sole()->title);
    }

    public function test_staff_without_the_permission_do_not_see_them(): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'T', 'permissions' => ['admin.tickets.viewAny']])->id]), 'staff');

        $this->get(CannedReplyResource::getUrl('index'))->assertForbidden();
    }

    private function whmcs(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INT, userid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT,
            status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, merged_ticket_id INT DEFAULT 0)');
        $pdo->exec('CREATE TABLE tblticketpredefinedcats (id INTEGER PRIMARY KEY, parentid INT, name TEXT)');
        $pdo->exec('CREATE TABLE tblticketpredefinedreplies (id INTEGER PRIMARY KEY, catid INT, name TEXT, reply TEXT)');
        $pdo->exec("INSERT INTO tblticketpredefinedcats VALUES (1, 0, 'Invoices'), (2, 0, 'Domains')");
        $pdo->exec("INSERT INTO tblticketpredefinedreplies VALUES (1, 1, 'Bank &amp; IBAN', 'Dear [NAME],\r\nuse &lt;IBAN&gt;.'), (2, 2, 'Expiry', 'Renew it'), (3, 9, 'Lost', 'x')");

        return $pdo;
    }

    public function test_whmcs_replies_come_by_category_with_its_merge_fields_as_tokens(): void
    {
        $counts = (new WhmcsTicketImport($this->whmcs()))->run();

        $this->assertSame(3, $counts['canned_replies']);
        $bank = CannedReply::query()->where('whmcs_id', 1)->sole();
        $this->assertSame('Bank & IBAN', $bank->title);
        $this->assertSame("Dear {{client.name}},\nuse <IBAN>.", $bank->body);
        $this->assertSame('Invoices', $bank->category->name);
        $this->assertNull(CannedReply::query()->where('whmcs_id', 3)->sole()->category, 'a category WHMCS lost: General');
    }

    public function test_a_rerun_follows_whmcs_but_keeps_what_staff_edited_or_deleted_here(): void
    {
        $whmcs = $this->whmcs();
        (new WhmcsTicketImport($whmcs))->run();
        $this->travel(1)->minutes();
        CannedReply::query()->where('whmcs_id', 1)->sole()->update(['body' => 'Ours now']);
        CannedReply::query()->where('whmcs_id', 3)->sole()->delete();
        $whmcs->exec("UPDATE tblticketpredefinedreplies SET reply = 'Changed in WHMCS' WHERE id IN (1, 2)");

        (new WhmcsTicketImport($whmcs))->run();

        $this->assertSame('Ours now', CannedReply::query()->where('whmcs_id', 1)->sole()->body, 'edited here: kept');
        $this->assertSame('Changed in WHMCS', CannedReply::query()->where('whmcs_id', 2)->sole()->body, 'not edited here: follows WHMCS');
        $this->assertFalse(CannedReply::query()->where('whmcs_id', 3)->exists(), 'deleted here: stays deleted');
        $this->assertSame(2, CannedReplyCategory::query()->count(), 'no category doubled');

        (new WhmcsTicketImport($whmcs))->run();
        $this->assertSame('Changed in WHMCS', CannedReply::query()->where('whmcs_id', 2)->sole()->body, 'and on the next run still followed');
    }
}
