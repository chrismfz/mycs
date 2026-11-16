<?php

namespace Tests\Feature;

use App\Enums\StaffPresenceStatus;
use App\Livewire\Admin\CustomerChat;
use App\Livewire\Client\ChatBubble;
use App\Models\Client;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/** Live chat, step 3β part 2: idle close and the «chat ended» notice in the bubble (docs/livechat.md §7). */
class LiveChatIdleCloseTest extends TestCase
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

    /** @return array{LiveChat, Staff, User, Client} */
    private function chat(bool $taken = true): array
    {
        $department = TicketDepartment::query()->create(['name' => 'Support']);
        $operator = $this->operator($department);
        [$user, $client] = $this->customer();
        $this->assertNull(LiveChats::start($user, $department->id, 'hello'));
        $chat = LiveChat::query()->latest('id')->firstOrFail();
        if ($taken) {
            $this->assertTrue(LiveChats::claim($operator, $chat));
        }

        return [$chat->fresh(), $operator, $user, $client];
    }

    public function test_an_active_chat_closes_after_the_minutes_without_a_message_and_not_before(): void
    {
        [$chat, $operator] = $this->chat();
        $this->travel(20)->minutes();
        $this->assertNull(LiveChats::postAsStaff($operator, $chat, 'still looking'));

        $this->travel(29)->minutes();
        $this->artisan('mycs:livechat-close-idle')->assertSuccessful();
        $this->assertSame(LiveChat::ACTIVE, $chat->fresh()->status, '29 minutes since the operator wrote');

        $this->travel(1)->minutes();
        $this->artisan('mycs:livechat-close-idle')->expectsOutputToContain('1 idle chat(s) closed.')->assertSuccessful();
        $chat->refresh();
        $this->assertSame(LiveChat::CLOSED, $chat->status);
        $this->assertSame(now()->toDateTimeString(), $chat->closed_at?->toDateTimeString());
        $note = $chat->messages()->latest('id')->firstOrFail();
        $this->assertSame([LiveChatMessage::SYSTEM, null], [$note->author_type, $note->staff_id]);
        $this->assertSame(__('Closed after :minutes minutes without a message.', ['minutes' => 30]), $note->body);

        $this->assertNotNull(LiveChats::postAsStaff($operator, $chat, 'late'));
        $this->artisan('mycs:livechat-close-idle')->expectsOutputToContain('0 idle chat(s) closed.');
        $this->assertSame(1, $chat->messages()->where('author_type', LiveChatMessage::SYSTEM)->count(), 'closed once, one note');
    }

    public function test_a_waiting_chat_nobody_took_closes_by_the_same_rule(): void
    {
        [$chat] = $this->chat(taken: false);
        $this->travel(30)->minutes();

        $this->assertSame(1, LiveChats::closeIdle());
        $this->assertSame(LiveChat::CLOSED, $chat->fresh()->status);
    }

    public function test_the_customers_message_keeps_it_open_and_notes_do_not(): void
    {
        [$chat, $operator, $user, $client] = $this->chat();
        $this->travel(25)->minutes();
        $this->asCustomer($user, $client);
        $this->assertNull(LiveChats::postAsCustomer($user, $chat, 'any news?'));
        $this->travel(10)->minutes();
        $this->assertSame(0, LiveChats::closeIdle(), '10 minutes since the customer wrote');

        LiveChats::note($operator, $chat, 'checking the logs');
        $this->travel(20)->minutes();
        $this->assertSame(1, LiveChats::closeIdle(), 'a note keeps no chat open');
    }

    public function test_the_setting_sets_the_minutes_and_zero_never_closes(): void
    {
        [$chat] = $this->chat();
        $this->storeSettings(['livechat_idle_minutes' => 0]);
        $this->travel(1)->days();
        $this->assertSame(0, LiveChats::closeIdle());
        $this->assertSame(LiveChat::ACTIVE, $chat->fresh()->status);

        $this->storeSettings(['livechat_idle_minutes' => 5]);
        $this->assertSame(1, LiveChats::closeIdle());
        $this->assertSame(__('Closed after :minutes minutes without a message.', ['minutes' => 5]), $chat->messages()->latest('id')->value('body'));
    }

    /** F16: a message that lands while the job is choosing which chats to close keeps its chat open. */
    public function test_a_message_written_as_the_job_runs_keeps_the_chat_open(): void
    {
        [$chat, $operator] = $this->chat();
        $this->travel(31)->minutes();
        $done = false;
        // Right after the job reads its list of idle chats, before it locks this one, the operator writes.
        DB::listen(function ($query) use (&$done, $operator, $chat) {
            if (!$done && preg_match('/^select [`"]id[`"] from [`"]live_chats[`"]/', $query->sql) === 1) {
                $done = true;
                $this->assertNull(LiveChats::postAsStaff($operator, LiveChat::query()->findOrFail($chat->id), 'here!'));
            }
        });

        $this->assertSame(0, LiveChats::closeIdle());
        $this->assertSame(LiveChat::ACTIVE, $chat->fresh()->status);
        $this->assertTrue($done);
    }

    public function test_the_job_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'mycs:livechat-close-idle'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_the_bubble_says_the_chat_ended_then_offers_a_new_one(): void
    {
        [$chat, $operator, $user, $client] = $this->chat();
        $bubble = Livewire::test(ChatBubble::class)->call('toggle')->assertSee('hello')->assertDontSee(__('The chat has ended.'));

        $this->asStaff($operator);
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->call('close');

        $this->asCustomer($user, $client);
        $bubble->call('$refresh')->assertSee(__('The chat has ended.'))->assertDontSee(__('Start chat'))->assertDontSee('hello')
            ->call('$refresh')->assertSee(__('The chat has ended.'), 'it stays until the customer moves on')
            ->call('newChat')->assertDontSee(__('The chat has ended.'))->assertSee(__('Start chat'));
    }

    public function test_the_bubble_says_so_after_an_idle_close_too(): void
    {
        [, , $user, $client] = $this->chat();
        $bubble = Livewire::test(ChatBubble::class)->call('toggle');

        $this->travel(30)->minutes();
        LiveChats::closeIdle();

        $this->asCustomer($user, $client);
        $bubble->call('$refresh')->assertSee(__('The chat has ended.'))->assertDontSee(__('Closed after :minutes minutes without a message.', ['minutes' => 30]));
    }

    public function test_a_fresh_bubble_or_another_account_never_sees_an_ended_notice(): void
    {
        [$chat] = $this->chat();
        LiveChat::query()->whereKey($chat->id)->update(['status' => LiveChat::CLOSED]);

        Livewire::test(ChatBubble::class)->call('toggle')->assertDontSee(__('The chat has ended.'))->assertSee(__('Start chat'));
        // The panel's chat id is locked: the browser cannot point it at a chat, its own or another account's.
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ChatBubble::class)->set('shown', $chat->id);
    }

    public function test_the_ended_notice_belongs_to_the_account_it_was_shown_to(): void
    {
        [$chat] = $this->chat();
        $this->assertNull(LiveChats::endedChatOf($chat->client_id, $chat->id), 'still open');
        LiveChat::query()->whereKey($chat->id)->update(['status' => LiveChat::CLOSED]);
        [, $other] = $this->customer();

        $this->assertNull(LiveChats::endedChatOf($other->id, $chat->id));
        $this->assertNotNull(LiveChats::endedChatOf($chat->client_id, $chat->id));
    }
}
