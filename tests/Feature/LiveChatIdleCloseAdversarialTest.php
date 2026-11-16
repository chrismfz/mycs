<?php

namespace Tests\Feature;

use App\Enums\StaffPresenceStatus;
use App\Models\Client;
use App\Models\LiveChat;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #885 (idle close of live chats). The idle clock counts only the customer's and the operators'
 * messages; an operator's other acts on the chat (taking it, passing it to another department) leave the clock where
 * it was, so the job closes a chat a minute after someone acted on it.
 */
class LiveChatIdleCloseAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_idle_minutes' => 30]);
    }

    private function operator(TicketDepartment $department): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'O' . uniqid(), 'permissions' => self::OPERATOR])->id]);
        $department->chatStaff()->attach($staff->id);
        $this->online($staff);

        return $staff;
    }

    private function online(Staff ...$staff): void
    {
        foreach ($staff as $member) {
            StaffPresence::setStatus($member, StaffPresenceStatus::Available);
            StaffPresence::heartbeat($member);
        }
    }

    /** @return array{User, Client} */
    private function customer(): array
    {
        $user = User::factory()->create();
        $client = $user->client();
        $this->actingAs($user, 'web');
        app(CurrentClient::class)->switchTo($client->id);

        return [$user, $client];
    }

    /**
     * ADV-885-1: an operator passes the chat to another department 29 minutes after the last message. The chat enters
     * that department's queue (and rings its «New chat» alert, via the system line), and one minute later the job closes
     * it: the new department never had a chance to take it, and the customer, who was just told they are being passed
     * on, sees «The chat has ended». The transfer's system line does not count as activity.
     */
    public function test_a_chat_just_transferred_to_another_department_is_not_closed_a_minute_later(): void
    {
        $sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $support = TicketDepartment::query()->create(['name' => 'Support']);
        $seller = $this->operator($sales);
        $engineer = $this->operator($support);
        [$user] = $this->customer();
        $this->assertNull(LiveChats::start($user, $sales->id, 'my site is down'));
        $chat = LiveChat::query()->latest('id')->firstOrFail();
        $this->assertTrue(LiveChats::claim($seller, $chat));
        $this->assertNull(LiveChats::postAsStaff($seller, $chat, 'I will pass you to our engineers'));

        $this->travel(29)->minutes();
        $this->online($seller, $engineer);
        $this->assertNull(LiveChats::transfer($seller, $chat, toDepartmentId: $support->id));
        $this->assertSame([LiveChat::WAITING, $support->id], [$chat->fresh()->status, $chat->fresh()->department_id]);

        $this->travel(1)->minutes();
        $this->assertSame(0, LiveChats::closeIdle(), 'closed one minute after it was transferred');
        $this->assertSame(LiveChat::WAITING, $chat->fresh()->status);
    }

    /**
     * ADV-885-2: a customer waited 29 minutes in the queue; an operator finally takes the chat and starts typing a
     * greeting. One minute later the job closes it under the operator's hands: taking the chat (a claim, or a take-over,
     * whose system line does not count either) does not restart the idle clock.
     */
    public function test_a_chat_an_operator_just_took_is_not_closed_a_minute_later(): void
    {
        $support = TicketDepartment::query()->create(['name' => 'Support']);
        $operator = $this->operator($support);
        [$user] = $this->customer();
        $this->assertNull(LiveChats::start($user, $support->id, 'hello, anyone?'));
        $chat = LiveChat::query()->latest('id')->firstOrFail();

        $this->travel(29)->minutes();
        $this->online($operator);
        $this->assertTrue(LiveChats::claim($operator, $chat));

        $this->travel(1)->minutes();
        $this->assertSame(0, LiveChats::closeIdle(), 'closed one minute after the operator took it');
        $this->assertSame(LiveChat::ACTIVE, $chat->fresh()->status);
        $this->assertNull(LiveChats::postAsStaff($operator, $chat, 'Hi, sorry for the wait'));
    }
}
