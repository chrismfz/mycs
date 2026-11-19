<?php

namespace Tests\Feature;

use App\Admin\Pages\LiveChat;
use App\Livewire\Admin\StaffChatNotifier;
use App\Livewire\Admin\StaffRoom;
use App\Models\Role;
use App\Models\Staff;
use App\Support\StaffChat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The staff chat's alert on every admin page (docs/livechat.md §7γ, F21): who wrote, never what. */
class StaffChatNotifyTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['admin.staff_chat.view', 'admin.staff_chat.post', 'admin.staff_online.view'];

    private function member(array $permissions = self::ALL, array $attributes = []): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id] + $attributes);
    }

    private function actAs(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');
    }

    public function test_nothing_new_says_nothing(): void
    {
        $me = $this->member();

        $this->assertSame(['total' => 0, 'latest' => 0, 'title' => null, 'with' => null], StaffChat::news($me));
    }

    public function test_a_private_message_names_its_author_and_opens_its_thread(): void
    {
        $me = $this->member();
        $haris = $this->member(attributes: ['first_name' => 'Haris', 'last_name' => 'A']);
        StaffChat::post($haris, StaffChat::direct($haris, $me), 'the vault code is 1234');

        $news = StaffChat::news($me);

        $this->assertSame(1, $news['total']);
        $this->assertSame(__('Message from :name', ['name' => 'Haris A']), $news['title']);
        $this->assertSame($haris->id, $news['with']);
        $this->assertSame((int) StaffChat::direct($haris, $me)->messages()->max('id'), $news['latest']);
    }

    public function test_the_room_and_several_threads_say_how_many_only(): void
    {
        $me = $this->member();
        StaffChat::post($this->member(), StaffChat::room(), 'hello');
        $this->assertSame(__('New message in the staff room'), StaffChat::news($me)['title']);
        $this->assertNull(StaffChat::news($me)['with']);

        $other = $this->member();
        StaffChat::post($other, StaffChat::direct($other, $me), 'psst');
        $news = StaffChat::news($me);
        $this->assertSame(2, $news['total']);
        $this->assertSame(trans_choice('{1} :count new message in the staff chat|[2,*] :count new messages in the staff chat', 2, ['count' => 2]), $news['title']);
    }

    public function test_the_alert_carries_no_message_text_and_nothing_of_others_threads(): void
    {
        $me = $this->member();
        $a = $this->member();
        $b = $this->member();
        StaffChat::post($a, StaffChat::direct($a, $me), 'the vault code is 1234');
        StaffChat::post($a, StaffChat::direct($a, $b), 'between a and b');
        $this->actAs($me);

        $html = Livewire::test(StaffChatNotifier::class)->html();

        $this->assertStringNotContainsString('vault code', $html);
        $this->assertStringNotContainsString('between a and b', $html);
        $this->assertStringContainsString('data-total="1"', $html);
        $this->assertStringContainsString(e(LiveChat::getUrl(['with' => $a->id])), $html);
        $this->assertStringContainsString('wire:poll.10s.keep-alive', $html);
    }

    public function test_a_name_in_the_alert_is_escaped(): void
    {
        $me = $this->member();
        $evil = $this->member(attributes: ['first_name' => '"><img src=x onerror=alert(1)>', 'last_name' => 'X']);
        StaffChat::post($evil, StaffChat::direct($evil, $me), 'hi');
        $this->actAs($me);

        $html = Livewire::test(StaffChatNotifier::class)->html();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x', $html);
    }

    public function test_reading_clears_the_alert(): void
    {
        $me = $this->member();
        $peer = $this->member();
        $thread = StaffChat::direct($peer, $me);
        StaffChat::post($peer, $thread, 'hi');

        StaffChat::markRead($me, $thread);

        $this->assertSame(0, StaffChat::news($me)['total']);
    }

    public function test_every_admin_page_carries_the_alert_for_members_of_the_chat_only(): void
    {
        $member = $this->member(['*']);
        $this->actAs($member);
        $this->get('/sysadmin')->assertOk()->assertSee('admin.staff-chat-notifier', false);

        $outsider = $this->member(['admin.tickets.viewAny']);
        $this->actAs($outsider);
        $this->get('/sysadmin')->assertOk()->assertDontSee('admin.staff-chat-notifier', false);
    }

    public function test_the_live_chat_page_offers_to_turn_on_and_test_notifications(): void
    {
        $this->actAs($this->member());

        $this->get(LiveChat::getUrl())->assertOk()
            ->assertSee(__('Turn on notifications'))->assertSee(__('Test notification'))->assertSee(__('Sound: on'));
    }

    /** Owner 2026-11-19: a single tone is easy to miss, so unread messages show in the sidebar of every admin page. */
    public function test_the_sidebar_shows_unread_staff_messages_from_the_alerts_count(): void
    {
        $me = $this->member();
        $haris = $this->member(attributes: ['first_name' => 'Haris', 'last_name' => 'A']);
        StaffChat::post($haris, StaffChat::direct($haris, $me), 'the vault code is 1234');
        $this->actAs($me);

        $html = $this->get('/sysadmin')->assertOk()->assertSee('mycs-chat-news', false)->getContent();
        // Who wrote reaches the page through the alert's attributes; what they wrote never does (F21).
        $this->assertStringContainsString('data-title="' . e(__('Message from :name', ['name' => 'Haris A'])) . '"', (string) $html);
        $this->assertStringNotContainsString('vault code', (string) $html);
        $notifier = (string) file_get_contents(resource_path('views/livewire/admin/staff-chat-notifier.blade.php'));
        $sidebar = (string) file_get_contents(resource_path('views/filament/admin/sidebar-work.blade.php'));
        $this->assertStringContainsString("new CustomEvent('mycs-chat-news'", $notifier);
        $this->assertStringContainsString('x-on:mycs-chat-news.window=', $sidebar);
        // The title goes in as text, never as markup.
        $this->assertStringContainsString('x-text="news.title"', $sidebar);
        $this->assertStringNotContainsString('x-html', $sidebar);
    }

    /** Review of #917: reading the thread clears the sidebar link at once, not at the notifier's next poll. */
    public function test_reading_a_thread_redraws_the_alert(): void
    {
        $me = $this->member();
        $haris = $this->member();
        $thread = StaffChat::direct($haris, $me);
        StaffChat::post($haris, $thread, 'hello');
        $this->actAs($me);

        Livewire::test(StaffRoom::class)->call('open', $haris->id)->call('markSeen', $thread->id, PHP_INT_MAX)
            ->assertDispatchedTo(StaffChatNotifier::class, 'staff-chat-read');
        Livewire::test(StaffChatNotifier::class)->dispatch('staff-chat-read')->assertSeeHtml('data-total="0"');
    }
}
