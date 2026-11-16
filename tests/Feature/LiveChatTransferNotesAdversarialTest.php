<?php

namespace Tests\Feature;

use App\Enums\StaffPresenceStatus;
use App\Models\Client;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Adversarial review of #884 (live chat transfer and internal notes): failing tests for what the review found. */
class LiveChatTransferNotesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply', 'admin.staff_chat.view', 'admin.staff_online.view', 'admin.clients.viewAny', 'admin.clients.view'];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'livechat_enabled', 'settingable_type' => null], ['value' => '1', 'type' => 'boolean']);
        config(['settings.livechat_enabled' => true]);
    }

    private function department(string $name = 'Support', array $attributes = []): TicketDepartment
    {
        return TicketDepartment::query()->create(['name' => $name] + $attributes);
    }

    private function operator(TicketDepartment ...$departments): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'O' . uniqid(), 'permissions' => self::OPERATOR])->id]);
        foreach ($departments as $department) {
            $department->chatStaff()->attach($staff->id);
        }

        return $staff;
    }

    private function online(Staff $staff): Staff
    {
        StaffPresence::setStatus($staff, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($staff);

        return $staff;
    }

    /** @return array{User, Client} */
    private function customer(): array
    {
        $user = User::factory()->create();
        $client = $user->client();
        $this->asCustomer($user, $client);

        return [$user, $client];
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

    /** A customer's chat in $department, taken by $operator. @return array{LiveChat, User, Client} */
    private function activeChat(TicketDepartment $department, Staff $operator, string $first = 'hi'): array
    {
        [$user, $client] = $this->customer();
        $this->assertNull(LiveChats::start($user, $department->id, $first));
        $chat = LiveChat::query()->latest('id')->firstOrFail();
        $this->assertTrue(LiveChats::claim($operator, $chat));

        return [$chat->fresh(), $user, $client];
    }

    /**
     * Runs $other once, right after the next request reads the chat: a second request landing between this one's read
     * and its write (F16).
     */
    private function meanwhile(callable $other): void
    {
        $done = false;
        LiveChat::retrieved(function () use (&$done, $other) {
            if (!$done) {
                $done = true;
                $other();
            }
        });
    }

    /**
     * ADV-884-1: a transfer to another department is accepted though no one there can answer now (its only listed
     * operator is offline) or ever (its only listed member lacks admin.chats.reply). The chat leaves the operator who
     * was answering and waits in a queue no one reads; a customer could not have started a chat there (availableIn),
     * and a transfer to a colleague requires them connected and able to answer.
     */
    public function test_adv_884_1_a_transfer_to_a_department_where_no_one_can_answer_is_refused(): void
    {
        $support = $this->department('Support');
        $offlineDept = $this->department('Sales');
        $readersDept = $this->department('Billing');
        $from = $this->online($this->operator($support));
        $this->operator($offlineDept); // listed, never connected
        $reader = $this->online(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.chats.view']])->id]));
        $readersDept->chatStaff()->attach($reader->id);
        [$chat] = $this->activeChat($support, $from);

        $this->assertFalse(LiveChats::availableIn($offlineDept->id));
        $this->assertFalse(LiveChats::availableIn($readersDept->id));
        $this->assertNotNull(LiveChats::transfer($from, $chat, toDepartmentId: $offlineDept->id), 'no one connected in Sales');
        $this->assertNotNull(LiveChats::transfer($from, $chat->fresh(), toDepartmentId: $readersDept->id), 'no one in Billing may reply');

        $chat->refresh();
        $this->assertSame([LiveChat::ACTIVE, $from->id, $support->id], [$chat->status, $chat->assigned_to, $chat->department_id]);
    }

    /**
     * ADV-884-2 (F3, F16): note() checks the department on the chat it read, but write() re-checks only that the chat is
     * open. A transfer to another department between the two lets a Support-only operator write a note into a chat that
     * is now Sales' and that they can no longer see.
     */
    public function test_adv_884_2_a_note_read_before_a_department_transfer_does_not_land_after_it(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $holder = $this->online($this->operator($support));
        $colleague = $this->online($this->operator($support));
        $this->online($this->operator($sales));
        [$chat] = $this->activeChat($support, $holder);

        // $colleague reads the chat in Support; meanwhile $holder sends it to Sales.
        $this->meanwhile(fn () => $this->assertNull(LiveChats::transfer($holder, LiveChat::query()->findOrFail($chat->id), toDepartmentId: $sales->id)));
        LiveChats::note($colleague, $chat, 'written into another department');

        $this->assertFalse(LiveChats::canSee($colleague, $chat->fresh()), 'the chat is Sales\' now');
        $this->assertSame(0, LiveChatMessage::query()->where('author_type', LiveChatMessage::NOTE)->count(),
            'a note landed in a chat outside its author\'s department');
    }

    /**
     * ADV-884-3: the «new chat» channel now counts message ids of every waiting chat, so each follow-up message the
     * waiting customer types advances chat_latest: every operator of the department gets «New chat: Support» (3 tones
     * and a desktop notification) again, up to PER_MINUTE times a minute, for a chat that already rang. Before #884 it
     * rang once per chat.
     */
    public function test_adv_884_3_a_waiting_customers_follow_up_does_not_ring_as_a_new_chat(): void
    {
        $support = $this->department('Support');
        $operator = $this->online($this->operator($support));
        [$user, $client] = $this->customer();
        $this->assertNull(LiveChats::start($user, $support->id, 'hello'));
        $chat = LiveChat::query()->sole();
        $rung = LiveChats::alerts($operator)['chat_latest'];
        $this->assertGreaterThan(0, $rung);

        $this->asCustomer($user, $client);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(LiveChats::postAsCustomer($user, $chat, 'anyone? ' . $i));
        }

        $this->assertSame($rung, LiveChats::alerts($operator)['chat_latest'], 'each follow-up rings the department as a new chat');
    }

    /**
     * ADV-884-4: the «transferred to you» ring keys on any SYSTEM line by someone else in the member's chats, so a
     * member who TAKES OVER an orphaned chat is told «A chat was transferred to you» because of an older transfer line
     * between two other operators. No one transferred anything to them.
     */
    public function test_adv_884_4_a_take_over_is_not_announced_as_a_transfer_to_you(): void
    {
        $support = $this->department('Support');
        $first = $this->online($this->operator($support));
        $second = $this->online($this->operator($support));
        $taker = $this->online($this->operator($support));
        [$chat] = $this->activeChat($support, $first);
        $this->assertNull(LiveChats::transfer($first, $chat, toStaffId: $second->id));
        // $second leaves the department: the chat is orphaned, and $taker takes it over.
        $support->chatStaff()->detach($second->id);
        $this->assertTrue(LiveChats::claim($taker, $chat->fresh()));

        $this->assertNotSame(__('A chat was transferred to you'), LiveChats::alerts($taker)['message_title']);
    }
}
