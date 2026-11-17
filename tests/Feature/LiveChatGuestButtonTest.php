<?php

namespace Tests\Feature;

use App\Enums\StaffPresenceStatus;
use App\Models\ClientUser;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\ChatVisitor;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use App\Support\Tickets\GuestLinks;
use App\Support\VisitorChats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The «Chat» button on MyCS's own pages for a visitor who is not signed in (docs/livechat.md §4, step 4, owner 2026-11-17). */
class LiveChatGuestButtonTest extends TestCase
{
    use RefreshDatabase;

    private const MARK = 'data-mycs-guest-chat=';

    private TicketDepartment $sales;

    private Staff $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true]);
        $this->sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $this->operator = Staff::factory()->create(['role_id' => Role::create(['name' => 'O', 'permissions' => ['admin.chats.view', 'admin.chats.reply']])->id]);
        $this->sales->chatStaff()->attach($this->operator->id);
        StaffPresence::setStatus($this->operator, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($this->operator);
    }

    public function test_a_visitor_sees_the_button_opening_the_popup_with_this_host_and_the_path(): void
    {
        $html = $this->get(route('contact'))->assertOk()->getContent();

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $this->assertStringContainsString(self::MARK . '"' . e(route('chat', ['src' => $host])) . '"', $html);
        $this->assertStringContainsString("url.searchParams.set('page', location.pathname)", $html);
        $this->assertStringContainsString("'noopener,popup,width=420,height=640'", $html);
        $this->assertStringContainsString('bg-primary', $html);
        $this->get(route('home'))->assertSee(self::MARK, false);
    }

    public function test_hidden_while_visitors_may_not_chat(): void
    {
        $this->storeSettings(['livechat_guest_enabled' => false]);
        $this->get(route('contact'))->assertDontSee(self::MARK, false);

        $this->storeSettings(['livechat_guest_enabled' => true, 'livechat_enabled' => false]);
        $this->get(route('contact'))->assertDontSee(self::MARK, false);
    }

    public function test_hidden_when_no_one_is_available_for_visitors(): void
    {
        StaffPresence::setStatus($this->operator, StaffPresenceStatus::Away);
        Cache::forget('live-chat-available');
        $this->get(route('contact'))->assertDontSee(self::MARK, false);

        // Someone available, but only in a department for clients only: still none for a visitor.
        $this->sales->update(['clients_only' => true]);
        StaffPresence::setStatus($this->operator, StaffPresenceStatus::Available);
        Cache::forget('live-chat-available');
        $this->get(route('contact'))->assertDontSee(self::MARK, false);
    }

    public function test_a_signed_in_customer_has_the_bubble_not_the_button(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));

        $this->get(route('dashboard'))->assertOk()->assertDontSee(self::MARK, false)->assertSeeLivewire('client.chat-bubble');
    }

    /** Hidden by the signed-in guard alone here (onceUsingId); VisitorChats::may() refuses «Login as client» too. */
    public function test_never_during_login_as_client(): void
    {
        $customer = User::factory()->create();
        $this->actingAs(Staff::factory()->withPermissions(['*'])->create(), 'staff')->withSession(['impersonating' => $customer->id,
            CurrentClient::IMPERSONATING_KEY => $customer->client()->id, CurrentClient::SESSION_KEY => $customer->client()->id]);

        $this->get(route('dashboard'))->assertOk()->assertDontSee(self::MARK, false)->assertDontSeeLivewire('client.chat-bubble');
    }

    public function test_never_on_a_private_links_page(): void
    {
        $ticket = Ticket::query()->create(['subject' => 'Hi', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->sales->id,
            'sender_vouched' => false, 'guest_name' => 'Ann', 'guest_email' => 'ann@example.com']);
        $this->get(GuestLinks::issue($ticket))->assertOk()->assertDontSee(self::MARK, false);
    }

    public function test_the_address_is_escaped_whatever_the_host(): void
    {
        config(['app.url' => 'https://x"onmouseover="alert(1).example']);

        $html = $this->get(route('contact'))->getContent();

        $this->assertStringNotContainsString('"onmouseover="', $html);
    }

    /** The layout's fall-through: a login the bubble refuses is never a visitor either. */
    public function test_a_customer_without_the_chat_permission_gets_neither(): void
    {
        $client = User::factory()->create()->client();
        $user = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'permissions' => ['tickets'], 'granted_at' => now()]);
        $this->actingAs($user, 'web');
        app(CurrentClient::class)->switchTo($client->id);

        $this->assertFalse(LiveChats::customerMay($user), 'no bubble');
        $this->assertFalse(VisitorChats::buttonShown(), 'and no visitor\'s button');
    }

    public function test_a_chat_from_the_button_keeps_this_instances_host_as_its_site(): void
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $this->storeSettings(['livechat_sites' => []]);

        $this->assertSame($host, ChatVisitor::site($host));
        $this->assertNull(ChatVisitor::site('evil.example'));
    }
}
