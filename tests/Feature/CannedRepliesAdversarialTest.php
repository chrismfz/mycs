<?php

namespace Tests\Feature;

use App\Admin\Resources\CannedReplyResource\Pages\ManageCannedReplies;
use App\Models\CannedReply;
use App\Models\CannedReplyCategory;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Import\WhmcsTicketImport;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

/** Adversarial review of #265 (predefined replies). */
class CannedRepliesAdversarialTest extends TestCase
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

    private function whmcs(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INT, userid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT,
            status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, merged_ticket_id INT DEFAULT 0)');
        $pdo->exec('CREATE TABLE tblticketpredefinedcats (id INTEGER PRIMARY KEY, parentid INT, name TEXT)');
        $pdo->exec('CREATE TABLE tblticketpredefinedreplies (id INTEGER PRIMARY KEY, catid INT, name TEXT, reply TEXT)');
        $pdo->exec("INSERT INTO tblticketpredefinedcats VALUES (1, 0, 'Invoices')");
        $pdo->exec("INSERT INTO tblticketpredefinedreplies VALUES (1, 1, 'Bank', 'From WHMCS')");

        return $pdo;
    }

    public function test_a_staff_edit_in_the_second_the_import_made_the_reply_is_not_overwritten_by_the_next_run(): void
    {
        // keep() tells "edited here" by updated_at != created_at, at one-second precision: an edit in the second the
        // import created (or last followed) the reply looks unedited, and the next run overwrites staff's text.
        Carbon::setTestNow('2026-10-20 10:00:00');
        $whmcs = $this->whmcs();
        (new WhmcsTicketImport($whmcs))->run();
        CannedReply::query()->where('whmcs_id', 1)->sole()->update(['body' => 'Ours now']);
        $whmcs->exec("UPDATE tblticketpredefinedreplies SET reply = 'Changed in WHMCS'");
        (new WhmcsTicketImport($whmcs))->run();
        $this->assertSame('Ours now', CannedReply::query()->where('whmcs_id', 1)->sole()->body, 'edited here: kept, whatever the second');
    }

    public function test_staff_can_rename_a_category_from_the_admin(): void
    {
        // Categories are made from the reply form (createOptionForm) or by the import, but nothing in the admin renames,
        // re-sorts or deletes one: a typo stays for good, and CannedReplyCategory's tombstone is unreachable.
        if (Filament::getPanel('admin')->getModelResource(CannedReplyCategory::class) !== null) {
            $this->assertTrue(true);

            return;
        }
        $category = CannedReplyCategory::query()->create(['name' => 'Invoces']);
        $reply = CannedReply::query()->create(['title' => 'A', 'body' => 'a', 'canned_reply_category_id' => $category->id]);

        Livewire::test(ManageCannedReplies::class)
            ->mountAction(TestAction::make('edit')->table($reply))
            ->callAction(TestAction::make('editOption')->schemaComponent('canned_reply_category_id', 'mountedActionSchema0'), ['name' => 'Invoices']);

        $this->assertSame('Invoices', $category->fresh()->name);
    }
}
