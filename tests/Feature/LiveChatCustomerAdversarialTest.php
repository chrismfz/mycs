<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\StaffPresenceStatus;
use App\Livewire\Admin\CustomerChat;
use App\Livewire\Client\ChatBubble;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #475 (live chat with logged-in customers, step 3α). Each test fails on the PR head and states
 * the expected behaviour.
 */
class LiveChatCustomerAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply', 'admin.staff_chat.view', 'admin.staff_online.view'];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'livechat_enabled', 'settingable_type' => null], ['value' => '1', 'type' => 'boolean']);
        config(['settings.livechat_enabled' => true]);
    }

    private function department(string $name = 'Support'): TicketDepartment
    {
        return TicketDepartment::query()->create(['name' => $name]);
    }

    private function operator(TicketDepartment ...$departments): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'O' . uniqid(), 'permissions' => self::OPERATOR])->id]);
        foreach ($departments as $department) {
            $department->chatStaff()->attach($staff->id);
        }
        StaffPresence::setStatus($staff, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($staff);

        return $staff->fresh();
    }

    private function asCustomer(User $user, Client $client): void
    {
        $this->actingAs($user, 'web');
        app(CurrentClient::class)->switchTo($client->id);
    }

    private function asStaff(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');
    }

    /** An owner, and a second login of the same account with `tickets` and `chat` only. */
    private function accountWithTwoLogins(): array
    {
        $owner = User::factory()->create(['first_name' => 'Olga', 'last_name' => 'Owner']);
        $client = $owner->client();
        $member = User::factory()->create(['first_name' => 'Mallory', 'last_name' => 'Helper']);
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => ['tickets', 'chat']]);

        return [$owner, $member, $client];
    }

    private function startedAndTaken(): array
    {
        $support = $this->department();
        $taker = $this->operator($support);
        $colleague = $this->operator($support);
        $owner = User::factory()->create();
        $client = $owner->client();
        $this->asCustomer($owner, $client);
        $this->assertNull(LiveChats::start($owner, $support->id, 'my site is down'));
        $chat = LiveChat::query()->sole();
        $this->assertTrue(LiveChats::claim($taker, $chat));

        return [$chat->fresh(), $taker, $colleague, $owner, $client];
    }

    /**
     * The operator who took a chat leaves (deactivated, or unlisted from the department): the chat stays `active` and
     * assigned to them for ever. No colleague of the department can take it over or close it, and the customer keeps
     * writing into a chat no one can answer (LiveChats::claim needs `waiting`, closeAsStaff needs assigned_to = me).
     */
    public function test_a_chat_whose_operator_left_can_be_taken_over_or_closed_by_a_colleague(): void
    {
        [$chat, $taker, $colleague] = $this->startedAndTaken();
        $taker->forceFill(['active' => false])->save();

        $this->assertNotNull(LiveChats::postAsStaff($taker->fresh(), $chat, 'still here'), 'the one who left cannot write (OK)');
        $this->assertTrue(
            LiveChats::claim($colleague, $chat->fresh()) || LiveChats::closeAsStaff($colleague, $chat->fresh()),
            'a listed colleague must be able to take over or close a chat whose operator can no longer answer it'
        );
    }

    public function test_a_chat_whose_operator_was_unlisted_can_be_taken_over_or_closed_by_a_colleague(): void
    {
        [$chat, $taker, $colleague] = $this->startedAndTaken();
        TicketDepartment::query()->findOrFail($chat->department_id)->chatStaff()->detach($taker->id);

        $this->assertSame([], LiveChats::mine($taker->fresh())->all(), 'it is gone from the taker\'s «My chats» (OK)');
        $this->assertTrue(
            LiveChats::claim($colleague, $chat->fresh()) || LiveChats::closeAsStaff($colleague, $chat->fresh()),
            'a listed colleague must be able to take over or close it'
        );
    }

    /**
     * Staff::hasHistory ignores chats a member took: one who took a chat and said nothing yet can be deleted, and
     * `assigned_to` goes NULL (nullOnDelete) on an `active` chat: neither `waiting` (no one can claim it) nor anyone's.
     */
    public function test_an_operator_holding_an_open_chat_is_not_deleted_out_from_under_it(): void
    {
        [$chat, $taker] = $this->startedAndTaken();

        $this->assertTrue($taker->hasHistory(), 'a member who took a chat has history: disable, do not delete');
    }

    /**
     * §3 Δ / G11: another login of the account continues the chat, and the operator cannot tell. Every customer message
     * shows the account's name only, so a sub-login with just `tickets`+`chat` talks to the operator as the owner.
     */
    public function test_the_operator_sees_which_login_of_the_account_writes(): void
    {
        $support = $this->department();
        $operator = $this->operator($support);
        [$owner, $member, $client] = $this->accountWithTwoLogins();
        $this->asCustomer($owner, $client);
        LiveChats::start($owner, $support->id, 'hello from the owner');
        $chat = LiveChat::query()->sole();
        LiveChats::claim($operator, $chat);

        $this->asCustomer($member, $client);
        $this->assertNull(LiveChats::postAsCustomer($member, $chat, 'please change the account email'));

        $this->asStaff($operator);
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->assertSee('please change the account email')
            ->assertSee('Mallory');
    }

    /** The same: in the bubble, a message of another login of the account is labelled «You». */
    public function test_the_customer_does_not_see_another_logins_message_as_their_own(): void
    {
        $support = $this->department();
        $this->operator($support);
        [$owner, $member, $client] = $this->accountWithTwoLogins();
        $this->asCustomer($owner, $client);
        LiveChats::start($owner, $support->id, 'hello from the owner');
        $chat = LiveChat::query()->sole();
        $this->asCustomer($member, $client);
        LiveChats::postAsCustomer($member, $chat, 'written by the helper');

        $this->asCustomer($owner, $client);
        $html = Livewire::test(ChatBubble::class)->call('toggle')->assertSee('written by the helper')->html();
        $this->assertSame(1, substr_count($html, __('You') . ' ·'), 'only the owner\'s own message is «You»');
    }

    /**
     * §3 Ε: «ανά λογαριασμό για πελάτες». The limits are keyed by login, so every login of an account adds 20 messages a
     * minute to the account's one chat (and 3 more starts in 10 minutes). An owner may invite as many logins as they like.
     */
    public function test_the_message_limit_is_per_account_not_per_login(): void
    {
        $support = $this->department();
        $this->operator($support);
        [$owner, $member, $client] = $this->accountWithTwoLogins();
        $this->asCustomer($owner, $client);
        LiveChats::start($owner, $support->id, 'hi');
        $chat = LiveChat::query()->sole();

        foreach ([$owner, $member] as $login) {
            $this->asCustomer($login, $client);
            for ($i = 0; $i < LiveChats::PER_MINUTE; $i++) {
                LiveChats::postAsCustomer($login, $chat, "flood {$i}");
            }
        }

        $this->assertLessThanOrEqual(LiveChats::PER_MINUTE + 1, LiveChatMessage::query()->count(), 'at most 20 a minute for the account (plus the first)');
    }

    public function test_the_start_limit_is_per_account_not_per_login(): void
    {
        $support = $this->department();
        $this->operator($support);
        [$owner, $member, $client] = $this->accountWithTwoLogins();

        $started = 0;
        foreach ([$owner, $member] as $login) {
            $this->asCustomer($login, $client);
            for ($i = 0; $i < LiveChats::STARTS_PER_10_MINUTES; $i++) {
                if (LiveChats::start($login, $support->id, 'hi') === null) {
                    $started++;
                }
                LiveChat::query()->update(['status' => LiveChat::CLOSED]);
            }
        }

        $this->assertLessThanOrEqual(LiveChats::STARTS_PER_10_MINUTES, $started, 'three starts in 10 minutes for the account');
    }

    /**
     * The migration gives `chat` to the logins with `tickets`, but the WHMCS importer (re-runnable; myip's cut-over runs it
     * after this deploy) does not: an imported sub-login with `tickets` gets no chat, unlike the same login here today.
     */
    public function test_a_whmcs_login_with_tickets_imported_after_the_migration_gets_chat_too(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $user = $whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $user->execute([7, 'Ada', 'Owner', 'ada@login.test', 'x', '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $user->execute([8, 'Bob', 'Helper', 'bob@login.test', 'x', '', null, null, '2024-02-01 00:00:00', '2025-01-01 00:00:00']);
        $whmcs->exec("INSERT INTO tblclients VALUES (300, 'Ada', 'Owner', 'Ada Ltd', 'billing@company.test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')");
        $whmcs->exec("INSERT INTO tblusers_clients VALUES (1, 7, 300, 1, NULL, NULL, '2024-01-01 00:00:00', NULL),
            (2, 8, 300, 0, 'invoices,tickets', NULL, '2024-05-01 00:00:00', NULL)");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);

        $helper = ClientUser::query()->where('client_id', 300)->where('user_id', 8)->sole();
        $this->assertTrue($helper->allows('tickets'));
        $this->assertTrue($helper->allows('chat'), 'the same rule as the migration: `tickets` brings `chat`');
    }

    /**
     * Round 2 (6fb93c8b): queue() now loads every active chat of the member's departments and, for each one not theirs,
     * runs orphaned(): Staff::find, then canReply for that operator (2FA, role, departmentsOf...). The queue polls every
     * 5 seconds on each operator's page, so the queries grow with the chats in progress. They should not.
     */
    public function test_the_queue_poll_does_not_grow_with_the_active_chats_of_colleagues(): void
    {
        $support = $this->department();
        $me = $this->operator($support);
        $holding = function (int $count) use ($support): void {
            for ($i = 0; $i < $count; $i++) {
                $colleague = $this->operator($support);
                LiveChat::query()->create([
                    'client_id' => User::factory()->create()->client()->id, 'department_id' => $support->id,
                    'status' => LiveChat::ACTIVE, 'assigned_to' => $colleague->id,
                ]);
            }
        };
        $queries = function () use ($me): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertCount(0, LiveChats::queue($me->fresh()), 'healthy chats of colleagues are not in my queue');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $holding(1);
        $one = $queries();
        $holding(9);
        $ten = $queries();

        $this->assertLessThanOrEqual($one + 2, $ten, "queue(): {$one} queries with 1 active chat, {$ten} with 10 (N+1 through orphaned())");
    }

    /**
     * Round 2: the queue lists an orphaned chat («no operator»), but the menu badge (LiveChats::waiting) counts only
     * `waiting`: a colleague on another admin page is never told that a customer is now writing to no one.
     */
    public function test_the_badge_counts_an_orphaned_chat_as_the_queue_does(): void
    {
        [$chat, $taker, $colleague] = $this->startedAndTaken();
        $taker->forceFill(['active' => false])->save();

        $this->assertCount(1, LiveChats::queue($colleague));
        $this->assertSame(1, LiveChats::waiting($colleague), 'the badge and the queue agree');
    }
}
