<?php

namespace Tests\Feature;

use App\Livewire\Admin\StaffChatNotifier;
use App\Models\Role;
use App\Models\Staff;
use App\Support\StaffChat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/** Independent review of #382 (the staff chat's alert on every admin page). */
class StaffChatNotifyReviewTest extends TestCase
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

    /** R2: a poll costs the same with one thread or ten. */
    public function test_news_costs_the_same_with_one_thread_or_ten(): void
    {
        $me = $this->member();
        $count = function () use ($me): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            StaffChat::news($me);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $peer = $this->member();
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'hi');
        $one = $count();
        for ($i = 0; $i < 9; $i++) {
            $p = $this->member();
            StaffChat::post($p, StaffChat::direct($p, $me), 'hi');
        }
        $ten = $count();

        $this->assertLessThanOrEqual($one + 2, $ten, "news(): {$one} queries with 1 thread, {$ten} with 10");
    }

    /** R3: past the cap the title says «99+», not an exact count. */
    public function test_the_title_does_not_state_the_cap_as_an_exact_count(): void
    {
        $me = $this->member();
        foreach ([$this->member(), $this->member()] as $peer) {
            $thread = StaffChat::direct($peer, $me);
            for ($i = 0; $i < 60; $i++) {
                RateLimiter::clear('staff-chat:' . $peer->id);
                StaffChat::post($peer, $thread, 'm' . $i);
            }
        }

        $title = (string) StaffChat::news($me)['title'];
        $this->assertStringNotContainsString('100 new messages', $title);
        $this->assertSame(__('99+ new messages in the staff chat'), $title);
    }

    public function test_a_member_without_two_factor_gets_no_notifier(): void
    {
        $member = $this->member(['*'], ['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null]);
        $this->actAs($member);

        $this->assertStringNotContainsString('admin.staff-chat-notifier', (string) $this->get('/sysadmin')->getContent());
    }

    public function test_a_poll_after_losing_the_chat_says_nothing(): void
    {
        $me = $this->member();
        $peer = $this->member();
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'hi');
        $this->actAs($me);
        $component = Livewire::test(StaffChatNotifier::class);
        $this->assertStringContainsString('data-total="1"', $component->html());

        $me->role->update(['permissions' => ['admin.tickets.viewAny']]);
        $me->refresh();
        $this->actAs($me);
        $html = $component->call('$refresh')->html();

        $this->assertStringContainsString('data-total="0"', $html);
        $this->assertStringContainsString('data-title=""', $html);
    }

    public function test_own_messages_in_the_room_never_alert(): void
    {
        $me = $this->member();
        StaffChat::post($me, StaffChat::room(), 'mine');

        $this->assertSame(0, StaffChat::news($me)['total']);
    }

    public function test_the_room_newest_among_several_threads_opens_no_private_thread(): void
    {
        $me = $this->member();
        $peer = $this->member();
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'first');
        StaffChat::post($this->member(), StaffChat::room(), 'later');

        $news = StaffChat::news($me);

        $this->assertNull($news['with']);
        $this->assertSame(2, $news['total']);
    }

    /** R1, R4, R5, R6: the browser side, as far as the markup shows it. */
    public function test_the_alert_uses_focus_a_front_tab_memory_and_renotify(): void
    {
        $view = (string) file_get_contents(resource_path('views/livewire/admin/staff-chat-notifier.blade.php'));

        $this->assertStringContainsString('document.hasFocus()', $view);
        $this->assertStringContainsString('mycs-chat-front', $view);
        $this->assertStringContainsString('window.mycsChatMemory', $view);
        $this->assertStringContainsString('renotify: true', $view);
        // Re-review of #382: the front mark is kept fresh by a timer, dropped on blur, and storage failure falls back to memory.
        $this->assertStringContainsString('setInterval(() => front(), 5000)', $view);
        $this->assertStringContainsString('destroy() { clearInterval(this.timer) }', $view);
        $this->assertStringContainsString('x-on:blur.window="front()"', $view);
        $this->assertStringContainsString('window.mycsChatNoStorage', $view);
    }

    /** Owner 2026-10-23: every click rang. No tone on a suspended context, one unlock listener, a tab's own memory. */
    public function test_the_tone_never_waits_for_a_click_and_a_tab_never_rings_twice(): void
    {
        $view = (string) file_get_contents(resource_path('views/livewire/admin/staff-chat-notifier.blade.php'));

        $this->assertStringContainsString("ctx.state === 'running'", $view);
        $this->assertStringNotContainsString("addEventListener('click'", $view);
        $this->assertStringContainsString('if (!window.mycsChatUnlock)', $view);
        $this->assertStringContainsString("removeEventListener('pointerdown', window.mycsChatUnlock)", $view);
        $this->assertStringContainsString('window.mycsChatRung', $view);
    }
}
