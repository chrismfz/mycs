<?php

namespace Tests\Feature;

use App\Actions\Auth\Login;
use App\Admin\Resources\ClientResource\Pages\ViewChat;
use App\Enums\StaffPresenceStatus;
use App\Livewire\Admin\CustomerChat;
use App\Livewire\ChatPopup;
use App\Models\LiveChat;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\ChatVisitor;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use App\Support\VisitorChats;
use App\Support\VisitorChatTools;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #889 (live chat, step 4, PR 3: the operator's tools and the move to a customer's chat).
 *
 * - ADV-889-1: the block is put on the address only, not on the session (§3 Ε: «μπαίνει στο session και στην IP»); a
 *   blocked visitor who changes network (mobile data, VPN) starts a new visitor's chat at once from the same browser.
 * - ADV-889-2: a blocked visitor logs in (or registers: Login::execute is the same) and chats as a customer at once,
 *   from the very address that is blocked (G7: «Το block μένει και μετά το login»; only the move checks the block).
 * - ADV-889-3 (described, not tested: needs MariaDB row locks and an interleaving): block() puts the cache mark and closes
 *   the chat outside the chat's lock, while confirm() reads blocked() under it. A confirm that read «not blocked» just
 *   before the mark lands opens the customer's chat with the same operator; the block then only finds the visitor's
 *   chat «already closed».
 *
 * Controls (attacks tried and blocked) are the test_control_* ones.
 */
class LiveChatVisitorOperatorAdversarialTest extends TestCase
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
        $this->operator = $this->operator(self::OPERATOR, $this->sales);
    }

    private function operator(array $permissions, TicketDepartment $department): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'O' . uniqid(), 'permissions' => $permissions])->id,
            'password' => Hash::make('staff-secret')]);
        $department->chatStaff()->attach($staff->id);
        StaffPresence::setStatus($staff, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($staff);

        return $staff;
    }

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

    private function fromAddress(string $ip): void
    {
        request()->server->set('REMOTE_ADDR', $ip);
    }

    public function test_adv_889_1_a_block_holds_for_the_session_not_only_the_address(): void
    {
        $this->fromAddress('203.0.113.10');
        $chat = $this->visitorChat();
        $this->assertTrue(VisitorChatTools::block($this->operator, $chat));

        // Same browser, same session, another network (mobile data, a VPN): §3 Ε puts the block on the session too.
        $this->fromAddress('198.51.100.20');

        $this->assertSame(__('Live chat is not available.'), VisitorChats::start(['message' => 'I am back']),
            'the blocked visitor starts a new chat from the same session at once');
    }

    public function test_adv_889_2_a_blocked_visitor_who_logs_in_chats_at_once_from_the_blocked_address(): void
    {
        $this->fromAddress('203.0.113.11');
        $chat = $this->visitorChat();
        $user = $this->customer();
        $this->assertTrue(VisitorChatTools::block($this->operator, $chat));
        $this->assertTrue(VisitorChatTools::blocked('203.0.113.11'));

        // A fresh register goes through the same Login::execute.
        app(Login::class)->execute($user);

        $this->assertNotNull(LiveChats::start($user, $this->sales->id, 'Hello again, it is me'),
            'G7: the block holds after a login; here the blocked address chats with the same operators within a minute');
        $this->assertSame(0, LiveChat::query()->whereNotNull('client_id')->count());
    }

    public function test_control_block_and_ask_login_with_a_forged_chat_id_of_another_department(): void
    {
        $support = TicketDepartment::query()->create(['name' => 'Support']);
        $outsider = $this->operator(self::OPERATOR, $support);
        $chat = $this->visitorChat();
        $this->asStaff($outsider);

        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->call('block')->call('askLogin');

        $this->assertSame(LiveChat::ACTIVE, $chat->fresh()->status);
        $this->assertNull($chat->fresh()->login_requested_at);
        $this->assertFalse(VisitorChatTools::blocked((string) $chat->ip));
        $this->assertFalse(VisitorChatTools::askLogin($outsider, $chat));
        $this->assertFalse(VisitorChatTools::block($outsider, $chat));
    }

    public function test_control_the_token_reaches_nothing_from_any_session_after_the_freeze(): void
    {
        $chat = $this->visitorChat();
        $token = session(ChatVisitor::SESSION_KEY);
        $this->assertIsString($token);
        app(Login::class)->execute($this->customer());

        // The token carried into another (old or copied) session, without a customer in it (G2).
        auth('web')->logout();
        session()->flush();
        session()->put(ChatVisitor::SESSION_KEY, $token);

        $this->assertNull(ChatVisitor::chat());
        $this->assertSame(__('You may not write here.'), VisitorChats::post($chat->fresh(), 'still me, the visitor'));
    }

    public function test_control_a_frozen_chat_is_not_continued_by_another_login_or_twice(): void
    {
        $chat = $this->visitorChat();
        $ann = $this->customer();
        app(Login::class)->execute($ann);

        // Another login reaching the same session without Login::execute (mycs_remember, a guard switch).
        $bob = $this->customer('bob@example.com');
        $this->actingAs($bob, 'web');
        $this->assertNull(VisitorChatTools::pending($bob));
        $this->assertSame(__('This chat can no longer be continued.'), VisitorChatTools::confirm($bob));
        $this->assertSame(0, LiveChat::query()->whereNotNull('client_id')->count());

        // The intended login still cannot replay a confirm for a second chat.
        $this->assertSame($ann->id, $chat->fresh()->handoff_user_id);
    }

    public function test_control_confirm_twice_opens_one_chat(): void
    {
        $chat = $this->visitorChat();
        $user = $this->customer();
        app(Login::class)->execute($user);
        $this->assertNull(VisitorChatTools::confirm($user));
        $this->assertSame(__('This chat can no longer be continued.'), VisitorChatTools::confirm($user));
        $this->assertSame(1, LiveChat::query()->whereNotNull('client_id')->count());
    }

    public function test_control_hostile_login_email_and_visitor_labels_are_escaped_in_every_staff_view(): void
    {
        $chat = $this->visitorChat(['name' => '"><svg onload=alert(1)>', 'page' => '/x"><script>alert(2)</script>']);
        $user = $this->customer('"<img src=x onerror=alert(3)>"@example.com');
        app(Login::class)->execute($user);
        Livewire::test(ChatPopup::class)->assertDontSeeHtml('<img src=x onerror=alert(3)>');
        $this->assertNull(VisitorChatTools::confirm($user));
        $new = LiveChat::query()->whereNotNull('client_id')->sole();

        $linker = $this->operator([...self::OPERATOR, 'admin.chats.link', 'admin.clients.viewAny', 'admin.clients.view'], $this->sales);
        $this->assertNull(VisitorChatTools::link($linker, $chat->id, $user->client(), 'staff-secret'));
        $this->asStaff($linker);

        $pages = [
            Livewire::test(CustomerChat::class, ['chatId' => $new->id])->html(),
            Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->html(),
            Livewire::test(ViewChat::class, ['record' => $user->client()->getKey(), 'chat' => $chat->id])->html(),
        ];
        foreach ($pages as $html) {
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('<svg onload', $html);
            $this->assertStringNotContainsString('<script>alert(2)', $html);
            $this->assertStringNotContainsString('mailto:', $html);
        }
    }

    public function test_control_the_link_refuses_an_open_frozen_chat_and_another_departments_chat(): void
    {
        $support = TicketDepartment::query()->create(['name' => 'Support']);
        $outsideLinker = $this->operator([...self::OPERATOR, 'admin.chats.link'], $support);
        $linker = $this->operator([...self::OPERATOR, 'admin.chats.link'], $this->sales);
        $chat = $this->visitorChat();
        $client = $this->customer('carol@example.com')->client();
        app(Login::class)->execute($this->customer());

        $this->assertSame(__('Only a closed chat may be linked.'), VisitorChatTools::link($linker, $chat->id, $client, 'staff-secret'));
        LiveChats::closeAsStaff($this->operator, $chat->fresh());
        $this->assertSame(__('No visitor\'s chat with this number.'), VisitorChatTools::link($outsideLinker, $chat->id, $client, 'staff-secret'));
        $this->assertNull($chat->fresh()->client_id);
    }
}
