<?php

namespace Tests\Feature;

use App\Actions\Auth\Login;
use App\Admin\Resources\ClientResource\Pages\ShowChats;
use App\Admin\Resources\ClientResource\Pages\ViewChat;
use App\Enums\StaffPresenceStatus;
use App\Livewire\Admin\ChatQueue;
use App\Livewire\Admin\CustomerChat;
use App\Livewire\ChatPopup;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\ChatVisitor;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use App\Support\VisitorChats;
use App\Support\VisitorChatTools;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** Live chat with anonymous visitors, step 4, PR 3: the operator's tools and the move to a customer's chat (docs/livechat.md §3 Δ, §3 Ε, §6α). */
class LiveChatVisitorOperatorTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply', 'admin.staff_chat.view', 'admin.staff_online.view'];

    private TicketDepartment $sales;

    private Staff $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true, 'livechat_sites' => ['www.example.com']]);
        $this->sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $this->operator = $this->operator(self::OPERATOR);
    }

    private function operator(array $permissions): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'O' . uniqid(), 'permissions' => $permissions])->id,
            'password' => Hash::make('staff-secret')]);
        $this->sales->chatStaff()->attach($staff->id);
        StaffPresence::setStatus($staff, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($staff);

        return $staff;
    }

    /** A visitor's chat, started in the current session (ALTCHA is the popup's, PR 2), taken by the operator. */
    private function visitorChat(array $input = [], bool $take = true): LiveChat
    {
        $this->assertNull(VisitorChats::start($input + ['message' => 'Why was my invoice not paid?']));
        $chat = LiveChat::query()->latest('id')->firstOrFail();
        if ($take) {
            $this->assertTrue(LiveChats::claim($this->operator, $chat));
        }

        return $chat->fresh();
    }

    private function customer(string $email = 'ann@example.com'): User
    {
        return User::factory()->create(['first_name' => 'Ann', 'email' => $email]);
    }

    private function asStaff(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');
    }

    public function test_ask_to_log_in_writes_the_servers_line_and_the_popup_offers_a_sign_in(): void
    {
        $chat = $this->visitorChat();

        $this->assertTrue(VisitorChatTools::askLogin($this->operator, $chat));
        $this->assertNotNull($chat->fresh()->login_requested_at);
        $this->assertSame(LiveChatMessage::SYSTEM, $chat->messages()->latest('id')->first()->author_type, 'staff only (F20)');
        Livewire::test(ChatPopup::class)->assertSee(__('For account matters you need to sign in.'))->assertSee(__('Sign in and continue'));
    }

    public function test_ask_to_log_in_only_by_the_operator_holding_a_visitors_chat(): void
    {
        $other = $this->operator(self::OPERATOR);
        $waiting = $this->visitorChat(take: false);
        $this->assertFalse(VisitorChatTools::askLogin($this->operator, $waiting), 'not taken');
        $this->assertTrue(LiveChats::claim($this->operator, $waiting));
        $this->assertFalse(VisitorChatTools::askLogin($other, $waiting->fresh()), 'someone else\'s');

        $customerChat = LiveChat::query()->create(['client_id' => $this->customer()->client()->id, 'department_id' => $this->sales->id,
            'status' => LiveChat::ACTIVE, 'assigned_to' => $this->operator->id]);
        $this->assertFalse(VisitorChatTools::askLogin($this->operator, $customerChat), 'a customer is logged in already');
    }

    public function test_a_login_in_the_session_freezes_the_chat_and_the_customer_continues_in_a_new_one(): void
    {
        $chat = $this->visitorChat();
        $user = $this->customer();

        app(Login::class)->execute($user);

        $frozen = $chat->fresh();
        $this->assertSame($user->id, $frozen->handoff_user_id);
        $this->assertNull(ChatVisitor::chat(), 'the token reaches nothing any more (G2)');
        $this->assertSame(__('Live chat is not available.'), VisitorChats::start(['message' => 'as a visitor again']));
        $this->assertSame(__('You may not write here.'), VisitorChats::post($frozen, 'as a visitor'));
        Livewire::test(ChatPopup::class)->assertSee('ann@example.com')->assertSee('Why was my invoice not paid?')
            ->assertSee(__('Continue'))->call('continueAsCustomer')->assertHasNoErrors();

        $old = $chat->fresh();
        $new = LiveChat::query()->where('client_id', $user->client()->id)->sole();
        $this->assertSame(LiveChat::CLOSED, $old->status);
        $this->assertNull($old->client_id, 'the visitor\'s chat stays a visitor\'s (G1)');
        $this->assertNull($old->visitor_token_hash);
        $this->assertSame([LiveChat::ACTIVE, $this->operator->id, $old->id, $user->id], [$new->status, $new->assigned_to, $new->continued_from, $new->user_id]);
        $this->assertSame([], LiveChats::customerMessages($new)->all(), 'nothing of before reaches the account, only a staff note');
        $this->assertSame(1, $new->messages()->where('author_type', LiveChatMessage::SYSTEM)->count());
        $this->assertFalse(ChatVisitor::holds());
        $this->assertNull(LiveChats::postAsCustomer($user, $new, 'now as a customer'));
    }

    public function test_a_login_not_by_the_form_moves_nothing(): void
    {
        $chat = $this->visitorChat();
        $user = $this->customer();
        // The mycs_remember cookie or «Login as client» set the guard without Login::execute (G3).
        $this->actingAs($user, 'web');
        app(CurrentClient::class)->switchTo($user->client()->id);

        $this->assertNull($chat->fresh()->handoff_user_id);
        $this->assertNull(ChatVisitor::chat(), 'still no visitor\'s chat beside a customer');
        $this->assertNull(VisitorChatTools::pending($user));
        Livewire::test(ChatPopup::class)->assertDontSee(__('Continue with the same operator as :account (:email)?', ['account' => (string) $user->client()->name, 'email' => $user->email]));
    }

    public function test_a_login_in_another_session_moves_nothing(): void
    {
        $chat = $this->visitorChat();
        ChatVisitor::forget(); // another browser: another session, no token
        session()->regenerate(true);

        app(Login::class)->execute($this->customer());

        $this->assertNull($chat->fresh()->handoff_user_id);
        $this->assertSame(LiveChat::ACTIVE, $chat->fresh()->status);
    }

    public function test_declined_or_unconfirmed_the_visitors_chat_closes(): void
    {
        $chat = $this->visitorChat();
        $user = $this->customer();
        app(Login::class)->execute($user);
        Livewire::test(ChatPopup::class)->call('declineHandoff');
        $this->assertSame(LiveChat::CLOSED, $chat->fresh()->status);
        $this->assertSame(0, LiveChat::query()->whereNotNull('client_id')->count());

        auth('web')->logout();
        session()->regenerate(true);
        $second = $this->visitorChat();
        app(Login::class)->execute($user);
        $this->travel(VisitorChatTools::HANDOFF_MINUTES + 1)->minutes();
        $this->assertNull(VisitorChatTools::pending($user));
        $this->artisan('mycs:livechat-close-idle')->assertSuccessful();
        $this->assertSame(LiveChat::CLOSED, $second->fresh()->status);
        $this->assertSame(__('This chat can no longer be continued.'), VisitorChatTools::confirm($user));
        $this->assertSame(0, LiveChat::query()->whereNotNull('client_id')->count());
    }

    public function test_a_blocked_visitor_gets_no_move_after_a_login(): void
    {
        $chat = $this->visitorChat();
        $user = $this->customer();
        app(Login::class)->execute($user);
        $this->assertTrue(VisitorChatTools::block($this->operator, $chat->fresh()));

        // The block closed the chat: nothing to continue (G7).
        $this->assertSame(__('This chat can no longer be continued.'), VisitorChatTools::confirm($user));
        $this->assertSame(0, LiveChat::query()->whereNotNull('client_id')->count());
    }

    public function test_a_customer_without_the_chat_permission_gets_no_move(): void
    {
        $chat = $this->visitorChat();
        $user = $this->customer();
        $this->storeSettings(['livechat_enabled' => false]);
        app(Login::class)->execute($user);

        $this->assertSame(__('This chat can no longer be continued.'), VisitorChatTools::confirm($user));
        $this->assertSame(LiveChat::CLOSED, $chat->fresh()->status);
        $this->assertSame(0, LiveChat::query()->whereNotNull('client_id')->count());
    }

    public function test_block_closes_the_chat_and_keeps_the_address_out(): void
    {
        $chat = $this->visitorChat();
        $this->asStaff($this->operator);

        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->call('block')->assertHasNoErrors();

        $this->assertSame(LiveChat::CLOSED, $chat->fresh()->status);
        $this->assertTrue(VisitorChatTools::blocked((string) $chat->ip));
        auth('staff')->logout();
        ChatVisitor::forget();
        $this->assertFalse(VisitorChats::may());
        $this->assertSame(__('Live chat is not available.'), VisitorChats::start(['message' => 'again']));
        $this->travel(VisitorChatTools::BLOCK_HOURS + 1)->hours();
        $this->assertTrue(VisitorChats::may());
    }

    public function test_block_only_a_visitors_chat_by_who_may_answer_it(): void
    {
        $chat = $this->visitorChat();
        $viewer = Staff::factory()->create(['role_id' => Role::create(['name' => 'V', 'permissions' => ['admin.chats.view']])->id]);
        $this->sales->chatStaff()->attach($viewer->id);
        $this->assertFalse(VisitorChatTools::block($viewer, $chat));

        $customerChat = LiveChat::query()->create(['client_id' => $this->customer()->client()->id, 'department_id' => $this->sales->id,
            'status' => LiveChat::ACTIVE, 'assigned_to' => $this->operator->id, 'ip' => '203.0.113.9']);
        $this->assertFalse(VisitorChatTools::block($this->operator, $customerChat));
        $this->assertFalse(VisitorChatTools::blocked('203.0.113.9'));
    }

    public function test_the_manual_link_needs_the_permission_the_password_and_a_closed_chat(): void
    {
        $linker = $this->operator([...self::OPERATOR, 'admin.chats.link']);
        $chat = $this->visitorChat();
        $client = $this->customer()->client();

        $this->assertSame(__('You may not link chats.'), VisitorChatTools::link($this->operator, $chat->id, $client, 'staff-secret'));
        $this->assertSame(__('The password is not right.'), VisitorChatTools::link($linker, $chat->id, $client, 'wrong'));
        $this->assertSame(__('Only a closed chat may be linked.'), VisitorChatTools::link($linker, $chat->id, $client, 'staff-secret'));
        LiveChats::closeAsStaff($this->operator, $chat);

        $this->assertNull(VisitorChatTools::link($linker, $chat->id, $client, 'staff-secret'));
        $linked = $chat->fresh();
        $this->assertSame([$client->id, $linker->id, null], [$linked->client_id, $linked->linked_by, $linked->visitor_token_hash]);
        $this->assertTrue($linked->started_anonymously);
        $this->assertNull(ChatVisitor::chat(), 'the token is revoked (F2)');
        $audit = Audit::query()->where('event', 'live_chat_linked')->sole();
        $this->assertSame(['live_chat_id' => $chat->id, 'staff_id' => $linker->id], $audit->new_values);
        $this->assertStringNotContainsString('invoice', json_encode($audit->toArray()), 'ids only (F12)');
        // Never the visitor's words for the customer (G1, F2).
        $this->assertSame([], LiveChats::customerMessages($linked)->where('author_type', LiveChatMessage::VISITOR)->all());
        $this->assertSame(__('No visitor\'s chat with this number.'), VisitorChatTools::link($linker, $chat->id, $client, 'staff-secret'), 'once');
    }

    public function test_the_link_tries_of_the_password_are_limited(): void
    {
        $linker = $this->operator([...self::OPERATOR, 'admin.chats.link']);
        $client = $this->customer()->client();
        for ($i = 0; $i < VisitorChatTools::LINK_TRIES; $i++) {
            VisitorChatTools::link($linker, 1, $client, 'wrong');
        }

        $this->assertNotSame(__('The password is not right.'), VisitorChatTools::link($linker, 1, $client, 'staff-secret'));
    }

    public function test_a_linked_chat_shows_in_the_clients_tab_marked_as_started_anonymously(): void
    {
        $linker = $this->operator([...self::OPERATOR, 'admin.chats.link', 'admin.clients.viewAny', 'admin.clients.view']);
        $chat = $this->visitorChat(['name' => 'Γιώργος']);
        LiveChats::closeAsStaff($this->operator, $chat);
        $client = $this->customer()->client();
        $this->assertNull(VisitorChatTools::link($linker, $chat->id, $client, 'staff-secret'));
        $this->asStaff($linker);

        Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertCanSeeTableRecords([$chat->fresh()])
            ->assertSee(__('Visitor (linked)'));
        Livewire::test(ViewChat::class, ['record' => $client->getKey(), 'chat' => $chat->id])->assertSee(__('started anonymously'))
            ->assertSee('Γιώργος')->assertSee(__('Visitor'));
    }

    public function test_the_labels_are_escaped_text_never_a_link(): void
    {
        $this->storeSettings(['livechat_sites' => ['www.example.com']]);
        $chat = $this->visitorChat(['name' => '<script>alert(1)</script>', 'email' => 'a@example.com', 'src' => 'www.example.com',
            'page' => '/<img/src=x/onerror=alert(1)>'], take: false);
        $this->assertSame('/<img', substr((string) $chat->page, 0, 5), 'kept as typed, a label');
        $this->asStaff($this->operator);

        foreach ([Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->html(), Livewire::test(ChatQueue::class)->html()] as $html) {
            $this->assertStringNotContainsString('<script>alert', $html);
            $this->assertStringNotContainsString('<img/src=x', $html);
            $this->assertStringNotContainsString('href="/&lt;img', $html);
            $this->assertStringNotContainsString('href="www.example.com', $html);
            $this->assertStringNotContainsString('mailto:', $html);
            $this->assertStringContainsString('&lt;img/src=x/onerror=alert(1)&gt;', $html);
        }
    }
}
