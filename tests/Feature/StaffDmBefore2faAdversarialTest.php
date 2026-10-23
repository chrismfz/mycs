<?php

namespace Tests\Feature;

use App\Admin\Pages\LiveChat;
use App\Livewire\Admin\StaffRoom;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffMessage;
use App\Support\StaffChat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #387: messaging a colleague before their 2FA set-up opens no read path. */
class StaffDmBefore2faAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['admin.staff_chat.view', 'admin.staff_chat.post', 'admin.staff_online.view'];

    private function member(array $permissions = self::ALL, array $attributes = [], bool $twoFactor = true): Staff
    {
        $factory = $twoFactor ? Staff::factory() : Staff::factory()->withoutTwoFactor();

        return $factory->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id] + $attributes);
    }

    private function actAs(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');
    }

    public function test_a_pending_member_reads_nothing_and_writes_nothing_through_the_component(): void
    {
        $me = $this->member();
        $new = $this->member(twoFactor: false);
        $thread = StaffChat::direct($me, $new);
        $this->assertNotNull($thread);
        $this->assertNull(StaffChat::post($me, $thread, 'secret hello'));
        $this->assertNull(StaffChat::post($me, StaffChat::room(), 'room secret'));

        $this->actAs($new);
        Livewire::test(StaffRoom::class)->call('open', $me->id)->assertDontSee('secret hello')
            ->set('body', 'from pending')->call('send')
            ->call('markSeen', $thread->id, PHP_INT_MAX);
        Livewire::test(StaffRoom::class)->assertDontSee('room secret');
        $this->assertFalse(StaffChat::isMember($new, $thread));
        $this->assertSame([], StaffChat::peers($new));
        $this->assertSame(0, StaffChat::unread($new));
        $this->assertSame(0, (int) DB::table('staff_thread_members')->where(['thread_id' => $thread->id, 'staff_id' => $new->id])->value('last_read_message_id'));
        $this->assertSame(0, StaffMessage::query()->where('staff_id', $new->id)->count());
        $this->assertNotNull(StaffChat::post($new, $thread, 'direct post'));
        $this->get(LiveChat::getUrl(['with' => $me->id]))->assertDontSee('secret hello');
    }

    public function test_post_still_refuses_a_peer_who_left_or_lost_the_chat_or_the_role(): void
    {
        foreach (['active' => false, 'perm' => null, 'role' => null] as $how => $_) {
            $me = $this->member();
            $peer = $this->member();
            $thread = StaffChat::direct($me, $peer);
            match ($how) {
                'active' => $peer->forceFill(['active' => false])->save(),
                'perm' => $peer->role->forceFill(['permissions' => ['admin.tickets.viewAny']])->save(),
                'role' => $peer->forceFill(['role_id' => null])->save(),
            };
            $this->assertSame(__('This member can no longer receive messages.'), StaffChat::post($me, $thread, 'x'), $how);
        }
    }

    public function test_opening_a_former_peer_with_history_shows_no_error(): void
    {
        $me = $this->member();
        $peer = $this->member();
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'bye');
        $peer->forceFill(['active' => false])->save();
        $this->actAs($me);

        Livewire::test(StaffRoom::class)->call('open', $peer->id)->assertHasNoErrors()->assertSee('bye');
    }

    public function test_opening_the_room_shows_no_error(): void
    {
        $this->actAs($this->member());
        Livewire::test(StaffRoom::class)->call('open', null)->assertHasNoErrors();
    }

    public function test_the_error_does_not_stick_to_the_room_after_a_poll_and_a_send(): void
    {
        $me = $this->member();
        $noChat = $this->member(['admin.tickets.viewAny']);
        $this->actAs($me);

        Livewire::test(StaffRoom::class)->call('open', $noChat->id)->assertHasErrors('with')
            ->call('$refresh')->set('body', 'in the room')->call('send')
            ->assertDontSee(__('This member cannot receive messages in the staff chat.'));
    }

    public function test_opening_oneself_or_a_deleted_id(): void
    {
        $me = $this->member();
        $this->actAs($me);
        Livewire::test(StaffRoom::class)->call('open', $me->id)->assertSet('with', null)->assertHasErrors('with');
        Livewire::test(StaffRoom::class)->call('open', 999999)->assertSet('with', null)->assertHasErrors('with');
    }

    public function test_the_english_string_exists(): void
    {
        app()->setLocale('en');
        $this->assertSame('This member cannot receive messages in the staff chat.', __('This member cannot receive messages in the staff chat.'));
    }
}
