<?php

namespace Tests\Feature;

use App\Admin\Pages\LiveChat as LiveChatPage;
use App\Enums\StaffPresenceStatus;
use App\Livewire\Admin\ChatQueue;
use App\Livewire\Admin\CustomerChat;
use App\Livewire\Admin\StaffChatNotifier;
use App\Livewire\Client\ChatBubble;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Currency;
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
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** Live chat with customers, step 3α (docs/livechat.md §3, §6, §7, §7α). */
class LiveChatCustomerTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply', 'admin.staff_chat.view', 'admin.staff_online.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enable(true);
    }

    private function enable(bool $on): void
    {
        Setting::query()->updateOrCreate(['key' => 'livechat_enabled', 'settingable_type' => null], ['value' => $on ? '1' : '0', 'type' => 'boolean']);
        config(['settings.livechat_enabled' => $on]);
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

    /** Connected and «Available»: the department answers now. */
    private function online(Staff $staff): Staff
    {
        StaffPresence::setStatus($staff, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($staff);

        return $staff;
    }

    /** A customer login working on its own account. */
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

    public function test_a_customer_starts_a_chat_an_operator_takes_it_and_they_talk(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        [$user, $client] = $this->customer();

        Livewire::test(ChatBubble::class)->call('toggle')->assertSee(__('Start chat'))
            ->set('body', 'Το site μου είναι κάτω')->call('start')->assertHasNoErrors()
            ->assertSee('Το site μου είναι κάτω')->assertSee(__('Someone from our team will be with you shortly.'));
        $chat = LiveChat::query()->sole();
        $this->assertSame([$client->id, $user->id, $support->id, LiveChat::WAITING], [$chat->client_id, $chat->user_id, $chat->department_id, $chat->status]);

        $this->asStaff($operator);
        Livewire::test(ChatQueue::class)->assertSee($client->name)->call('claim', $chat->id)->assertDispatched('customer-chat-open');
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->set('body', 'Το κοιτάζω τώρα')->call('send')->assertHasNoErrors();

        $this->asCustomer($user, $client);
        Livewire::test(ChatBubble::class)->call('toggle')->assertSee('Το κοιτάζω τώρα')->assertSee($operator->first_name)
            ->assertDontSee($operator->last_name)->assertDontSee($operator->email);
    }

    public function test_the_bubble_is_off_without_the_setting_the_permission_or_as_staff_in_login_as_client(): void
    {
        $this->department()->chatStaff()->attach($this->online($this->operator())->id);
        [$user, $client] = $this->customer();
        $this->assertTrue(LiveChats::customerMay($user));

        $this->enable(false);
        $this->assertFalse(LiveChats::customerMay($user));
        $this->enable(true);

        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => ['tickets']]);
        $this->asCustomer($member, $client);
        $this->assertFalse(LiveChats::customerMay($member), 'a login without `chat`');

        $this->asCustomer($user, $client);
        session(['impersonating' => $user->id]);
        $this->assertFalse(LiveChats::customerMay($user), 'staff in «Login as client» do not chat as the customer (F9)');
        $this->assertNotNull(LiveChats::start($user, 1, 'hi'));
    }

    public function test_no_one_available_offers_a_ticket_and_starts_nothing(): void
    {
        $support = $this->department();
        $this->operator($support); // listed, but not connected
        [$user] = $this->customer();

        Livewire::test(ChatBubble::class)->assertDontSee(__('Chat'))
            ->set('department', $support->id)->set('body', 'hello')->call('start')->assertHasErrors('body');
        $this->assertSame(0, LiveChat::query()->count());
    }

    public function test_a_busy_or_away_operator_is_not_available(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        $this->assertTrue(LiveChats::availableIn($support->id));

        StaffPresence::setStatus($operator, StaffPresenceStatus::Busy);
        $this->assertFalse(LiveChats::availableIn($support->id));
    }

    public function test_a_hidden_department_or_one_with_no_one_listed_takes_no_chats(): void
    {
        $hidden = $this->department('Internal', ['hidden' => true]);
        $empty = $this->department('Sales');
        $this->online($this->operator($hidden));
        [$user] = $this->customer();

        $this->assertSame([], LiveChats::departments()->pluck('id')->all());
        $this->assertNotNull(LiveChats::start($user, $hidden->id, 'hi'));
        $this->assertNotNull(LiveChats::start($user, $empty->id, 'hi'));
        $this->assertSame(0, LiveChat::query()->count());
    }

    public function test_one_open_chat_per_account_and_a_start_limit(): void
    {
        $support = $this->department();
        $this->online($this->operator($support));
        [$user] = $this->customer();

        $this->assertNull(LiveChats::start($user, $support->id, 'first'));
        $this->assertNotNull(LiveChats::start($user, $support->id, 'second'));
        $this->assertSame(1, LiveChat::query()->count());

        LiveChat::query()->update(['status' => LiveChat::CLOSED]);
        $this->assertNull(LiveChats::start($user, $support->id, 'again'));
        LiveChat::query()->update(['status' => LiveChat::CLOSED]);
        $this->assertNull(LiveChats::start($user, $support->id, 'third'));
        LiveChat::query()->update(['status' => LiveChat::CLOSED]);
        $this->assertNotNull(LiveChats::start($user, $support->id, 'fourth'), 'three starts in 10 minutes at most');
    }

    public function test_an_operator_sees_only_the_chats_of_departments_that_list_them(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $this->online($this->operator($support));
        [$user] = $this->customer();
        LiveChats::start($user, $support->id, 'support question');
        $chat = LiveChat::query()->sole();

        $salesOnly = $this->operator($sales);
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'A' . uniqid(), 'permissions' => ['*']])->id]);
        foreach ([$salesOnly, $admin] as $outsider) {
            $this->asStaff($outsider);
            Livewire::test(ChatQueue::class)->assertDontSee($user->client()->name);
            Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->assertDontSee('support question')
                ->assertSee(__('This chat is not available to you.'));
            $this->assertFalse(LiveChats::claim($outsider, $chat));
        }
        $this->assertSame(LiveChat::WAITING, $chat->fresh()->status);
    }

    public function test_two_operators_take_the_same_chat_and_one_wins(): void
    {
        $support = $this->department();
        $first = $this->online($this->operator($support));
        $second = $this->operator($support);
        [$user] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');
        $chat = LiveChat::query()->sole();

        $this->assertTrue(LiveChats::claim($first, $chat));
        $this->assertFalse(LiveChats::claim($second, $chat));
        $this->assertSame($first->id, $chat->fresh()->assigned_to);
    }

    public function test_only_the_operator_who_took_it_writes_and_another_reads_it(): void
    {
        $support = $this->department();
        $taker = $this->online($this->operator($support));
        $colleague = $this->operator($support);
        [$user] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');
        $chat = LiveChat::query()->sole();
        LiveChats::claim($taker, $chat);

        $this->assertNotNull(LiveChats::postAsStaff($colleague, $chat, 'me too'));
        $this->asStaff($colleague);
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->assertSee('hi')
            ->assertSee(__('Only the operator who took this chat writes in it.'))->assertDontSeeHtml('wire:submit="send"');
        $this->assertNull(LiveChats::postAsStaff($taker, $chat, 'hello'));
    }

    public function test_a_customer_writes_only_in_their_own_accounts_chat(): void
    {
        $support = $this->department();
        $this->online($this->operator($support));
        [$owner] = $this->customer();
        LiveChats::start($owner, $support->id, 'mine');
        $chat = LiveChat::query()->sole();

        [$stranger] = $this->customer();
        $this->assertNotNull(LiveChats::postAsCustomer($stranger, $chat, 'butting in'));
        Livewire::test(ChatBubble::class)->call('toggle')->assertDontSee('mine');
        $this->assertSame(1, LiveChatMessage::query()->count());
    }

    public function test_after_close_nothing_more_goes_in_and_the_customer_can_start_again(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        [$user, $client] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');
        $chat = LiveChat::query()->sole();
        LiveChats::claim($operator, $chat);

        $this->asStaff($operator);
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->call('close');
        $this->assertSame(LiveChat::CLOSED, $chat->fresh()->status);
        $this->assertNotNull(LiveChats::postAsStaff($operator, $chat, 'late'));

        $this->asCustomer($user, $client);
        $this->assertNotNull(LiveChats::postAsCustomer($user, $chat, 'late too'));
        Livewire::test(ChatBubble::class)->call('toggle')->assertSee(__('Start chat'));
    }

    public function test_what_anyone_writes_comes_out_escaped_on_both_sides(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        [$user, $client] = $this->customer();
        LiveChats::start($user, $support->id, '<img src=x onerror=alert(1)> x" onmouseover="alert(1)');
        $chat = LiveChat::query()->sole();
        LiveChats::claim($operator, $chat);
        LiveChats::postAsStaff($operator, $chat, '<script>alert(2)</script>');

        $this->asStaff($operator);
        $admin = Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->html();
        $this->asCustomer($user, $client);
        $bubble = Livewire::test(ChatBubble::class)->call('toggle')->html();

        foreach ([$admin, $bubble] as $html) {
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('<script>alert(2)', $html);
            $this->assertStringContainsString('&lt;img src=x', $html);
        }
    }

    /** Owner 2026-10-27: when we are offline the bubble hides, but not in the middle of an open chat. */
    public function test_the_bubble_hides_when_no_one_is_available_but_not_during_an_open_chat(): void
    {
        $support = $this->department();
        $operator = $this->operator($support);
        [$user] = $this->customer();

        Livewire::test(ChatBubble::class)->assertDontSee(__('Chat'));

        $this->online($operator);
        Cache::forget('live-chat-available');
        Livewire::test(ChatBubble::class)->assertSee(__('Chat'));
        LiveChats::start($user, $support->id, 'hi');

        StaffPresence::setStatus($operator, StaffPresenceStatus::Away);
        Cache::forget('live-chat-available');
        Livewire::test(ChatBubble::class)->call('toggle')->assertSee('hi');
    }

    public function test_only_departments_with_someone_available_are_offered(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $this->online($this->operator($support));
        $this->operator($sales);
        $this->customer();

        Livewire::test(ChatBubble::class)->call('toggle')->assertSee(__('Start chat'))->assertDontSee('Sales');
    }

    public function test_the_closed_bubble_polls_nothing_and_asks_nothing(): void
    {
        $this->online($this->operator($this->department()));
        $this->customer();

        $html = Livewire::test(ChatBubble::class)->html();
        $this->assertStringNotContainsString('wire:poll', $html);
    }

    public function test_messages_write_no_audit_and_an_operator_who_wrote_is_disabled_not_deleted(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        [$user] = $this->customer();
        $audits = Audit::query()->count();
        LiveChats::start($user, $support->id, 'hi');
        $chat = LiveChat::query()->sole();
        LiveChats::claim($operator, $chat);
        LiveChats::postAsStaff($operator, $chat, 'hello');

        $this->assertSame($audits, Audit::query()->count());
        $this->assertTrue($operator->hasHistory());
    }

    public function test_the_live_chat_page_shows_the_queue_and_counts_it_in_the_badge(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        [$user] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');

        $this->asStaff($operator);
        $this->assertSame('1', LiveChatPage::getNavigationBadge());
        $this->get(LiveChatPage::getUrl())->assertOk()->assertSee(__('Customers'))->assertSee($user->client()->name);
    }

    public function test_the_client_layout_carries_the_bubble_only_for_a_login_that_may_chat(): void
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        Setting::query()->updateOrCreate(['key' => 'default_currency', 'settingable_type' => null], ['value' => 'EUR', 'type' => 'string']);
        $this->online($this->operator($this->department()));
        [$user, $client] = $this->customer();
        $this->withSession($this->loginUser($user, ['client_id' => $client->id, 'currency' => 'EUR']))->get('/dashboard')->assertOk()->assertSee('client.chat-bubble', false);

        $this->enable(false);
        $this->withSession($this->loginUser($user, ['client_id' => $client->id, 'currency' => 'EUR']))->get('/dashboard')->assertOk()->assertDontSee('client.chat-bubble', false);
    }

    /** Review of #475: a chat whose operator left shows in a colleague's queue, to take over. */
    public function test_a_chat_whose_operator_left_is_in_the_colleagues_queue_to_take_over(): void
    {
        $support = $this->department();
        $taker = $this->online($this->operator($support));
        $colleague = $this->operator($support);
        [$user, $client] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');
        $chat = LiveChat::query()->sole();
        LiveChats::claim($taker, $chat);
        $taker->forceFill(['active' => false])->save();

        $this->asStaff($colleague);
        Livewire::test(ChatQueue::class)->assertSee($client->name)->assertSee(__('Take over'))->call('claim', $chat->id);
        $this->assertSame($colleague->id, $chat->fresh()->assigned_to);
        $this->assertNull(LiveChats::postAsStaff($colleague, $chat, 'I am taking this over'));
    }

    /** Review of #475: a member who only reads chats gets no «Take». */
    public function test_a_member_without_reply_sees_the_queue_without_take(): void
    {
        $support = $this->department();
        $this->online($this->operator($support));
        $reader = Staff::factory()->create(['role_id' => Role::create(['name' => 'V' . uniqid(), 'permissions' => ['admin.chats.view']])->id]);
        $support->chatStaff()->attach($reader->id);
        [$user] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');

        $this->asStaff($reader);
        Livewire::test(ChatQueue::class)->assertSee(__('View'))->assertDontSee(__('Take'));
    }

    /** Re-review of #475: a take-over leaves a note for the staff, which the customer does not see. */
    public function test_a_take_over_leaves_a_staff_only_note(): void
    {
        $support = $this->department();
        $taker = $this->online($this->operator($support));
        $colleague = $this->operator($support);
        [$user, $client] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');
        $chat = LiveChat::query()->sole();
        LiveChats::claim($taker, $chat);
        $taker->forceFill(['active' => false])->save();
        $this->assertTrue(LiveChats::claim($colleague, $chat->fresh()));

        $note = __('Taken over by :name from :from.', ['name' => $colleague->name, 'from' => $taker->name]);
        $this->asStaff($colleague);
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->assertSee($note);
        $this->asCustomer($user, $client);
        Livewire::test(ChatBubble::class)->call('toggle')->assertSee('hi')->assertDontSee($note);
    }

    /** Owner 2026-10-27: a new customer's chat rings for the department's operators, on every admin page (F21). */
    public function test_a_waiting_chat_and_a_customer_message_reach_the_operators_alert_without_names_or_words(): void
    {
        $support = $this->department('Support');
        $operator = $this->online($this->operator($support));
        $other = $this->operator($this->department('Sales'));
        [$user, $client] = $this->customer();
        LiveChats::start($user, $support->id, 'my secret problem');
        $chat = LiveChat::query()->sole();

        $this->asStaff($operator);
        $html = Livewire::test(StaffChatNotifier::class)->html();
        $this->assertStringContainsString('data-waiting="1"', $html);
        $this->assertStringContainsString('data-chat-latest="' . $chat->id . '"', $html);
        $this->assertStringContainsString(e(__('New chat: :department', ['department' => 'Support'])), $html);
        $this->assertStringNotContainsString('my secret problem', $html);
        $this->assertStringNotContainsString(e($client->name), $html);

        LiveChats::claim($operator, $chat);
        $this->asCustomer($user, $client);
        LiveChats::postAsCustomer($user, $chat, 'are you there?');
        $this->asStaff($operator);
        $html = Livewire::test(StaffChatNotifier::class)->html();
        $this->assertStringContainsString(e(__('New message in a customer chat')), $html);
        $this->assertStringContainsString(e(LiveChatPage::getUrl(['chat' => $chat->id])), $html);
        $this->assertStringNotContainsString('are you there', $html);

        $this->asStaff($other);
        $html = Livewire::test(StaffChatNotifier::class)->html();
        $this->assertStringContainsString('data-waiting="0"', $html);
        $this->assertStringContainsString('data-msg-latest="0"', $html);
    }

    /** An operator without the staff chat still gets the alert on every admin page. */
    public function test_an_operator_without_the_staff_chat_has_the_alert_on_admin_pages(): void
    {
        $support = $this->department();
        $operator = Staff::factory()->create(['role_id' => Role::create(['name' => 'C' . uniqid(), 'permissions' => ['admin.chats.view', 'admin.chats.reply']])->id]);
        $support->chatStaff()->attach($operator->id);
        $this->asStaff($operator);

        $this->get('/sysadmin')->assertOk()->assertSee('admin.staff-chat-notifier', false);
    }

    /** Owner 2026-10-27: the bubble was white on white; the client theme has no numbered primary shades. */
    public function test_the_bubble_uses_the_client_themes_colours(): void
    {
        $view = (string) file_get_contents(resource_path('views/livewire/client/chat-bubble.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/primary-\d{2,3}/', $view);
        $this->assertStringContainsString('bg-primary px-4', $view);
    }

    /** Review of #479: the alert re-checks whenever a poll changes any of its counts or ids, not only the staff chat's. */
    public function test_the_alert_watches_every_count_and_id_it_carries(): void
    {
        $view = (string) file_get_contents(resource_path('views/livewire/admin/staff-chat-notifier.blade.php'));
        preg_match('/attributeFilter: \[([^\]]*)\]/', $view, $filter);
        preg_match_all('/(data-[a-z-]*(?:latest|total|waiting))="/', $view, $attributes);

        foreach (array_unique($attributes[1]) as $attribute) {
            $this->assertStringContainsString("'{$attribute}'", $filter[1] ?? '', "{$attribute} is watched");
        }
    }

    /** Review of #479: a member unlisted from a department is no longer told of its customers' messages. */
    public function test_the_message_alert_is_limited_to_the_members_departments(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        [$user, $client] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');
        $chat = LiveChat::query()->sole();
        LiveChats::claim($operator, $chat);
        $this->asCustomer($user, $client);
        LiveChats::postAsCustomer($user, $chat, 'still there?');
        $support->chatStaff()->detach($operator->id);

        $this->assertSame(0, LiveChats::alerts($operator)['message_latest']);
    }

    /** Owner 2026-10-27: the reminder of a waiting chat rings for an «Available» member only. */
    public function test_the_reminder_follows_the_members_status(): void
    {
        $operator = $this->online($this->operator($this->department()));
        $this->asStaff($operator);
        $this->assertStringContainsString('data-available="1"', Livewire::test(StaffChatNotifier::class)->html());

        StaffPresence::setStatus($operator, StaffPresenceStatus::Busy);
        $this->asStaff($operator->fresh());
        $this->assertStringContainsString('data-available="0"', Livewire::test(StaffChatNotifier::class)->html());
    }

    /** Owner 2026-10-27: «Customers waiting» in the sidebar of every admin page, fed by the alert's own count. */
    public function test_the_sidebar_shows_customers_waiting_from_the_alerts_count(): void
    {
        $operator = $this->online($this->operator($this->department()));
        $this->asStaff($operator);

        $this->get('/sysadmin')->assertOk()->assertSee('mycs-chat-waiting', false)->assertSee(__('Customers waiting'));
        $notifier = (string) file_get_contents(resource_path('views/livewire/admin/staff-chat-notifier.blade.php'));
        $sidebar = (string) file_get_contents(resource_path('views/filament/admin/sidebar-work.blade.php'));
        $this->assertStringContainsString("new CustomEvent('mycs-chat-waiting'", $notifier);
        $this->assertStringContainsString('x-on:mycs-chat-waiting.window=', $sidebar);
        // Review of #480: the reminder follows the same count as the link, a chat whose operator left included.
        $this->assertStringContainsString("if (!(Number(d.waiting) > 0) || d.available !== '1' || this.reading()) return;", $notifier);
        $this->assertStringContainsString('if (Date.now() - reminded < 5000) times = 0;', $notifier);
    }
}
