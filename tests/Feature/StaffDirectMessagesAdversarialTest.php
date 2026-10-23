<?php

namespace Tests\Feature;

use App\Admin\Pages\LiveChat;
use App\Livewire\Admin\StaffRoom;
use App\Livewire\Admin\StaffTeam;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffThread;
use App\Support\StaffChat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffDirectMessagesAdversarialTest extends TestCase
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

    /** Peer deactivated with unread messages: the badge must not count what the reader can never open. */
    public function test_unread_from_a_deactivated_peer_does_not_stick_in_the_badge(): void
    {
        $me = $this->member();
        $peer = $this->member();
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'before I go');
        $peer->forceFill(['active' => false])->save();

        $this->actAs($me);
        Livewire::test(StaffRoom::class)->call('open', $peer->id)->call('markSeen', (int) StaffThread::query()->where('direct_key', min($me->id, $peer->id) . '-' . max($me->id, $peer->id))->value('id'), PHP_INT_MAX);

        $this->assertSame(0, StaffChat::unread($me->fresh()), 'unread that no screen can clear');
    }

    /** Peer loses the chat permission but stays active: same stuck count, shown next to a name that opens the room. */
    public function test_unread_from_a_peer_without_the_chat_any_more_can_be_cleared(): void
    {
        $me = $this->member();
        $peer = $this->member();
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'hi');
        $peer->role->update(['permissions' => ['admin.staff_online.view']]);

        $this->actAs($me);
        Livewire::test(StaffRoom::class)->call('open', $peer->id)->call('markSeen', (int) StaffThread::query()->where('direct_key', min($me->id, $peer->id) . '-' . max($me->id, $peer->id))->value('id'), PHP_INT_MAX);

        $this->assertSame([], StaffChat::unreadByThread($me->fresh()));
    }

    /** Message ids are global: an id seen in thread A must not mark thread B read. */
    public function test_mark_seen_with_an_id_from_another_thread_does_not_mark_the_open_one_read(): void
    {
        $me = $this->member();
        $a = $this->member();
        $b = $this->member();
        StaffChat::post($b, StaffChat::direct($b, $me), 'unseen in B');
        StaffChat::post($a, StaffChat::direct($a, $me), 'seen in A'); // higher id
        $idInA = (int) StaffChat::direct($a, $me)->messages()->max('id');

        $this->actAs($me);
        // The old thread's element tells its last id after the switch to B has reached the server.
        Livewire::test(StaffRoom::class)->call('open', $b->id)->call('markSeen', StaffChat::direct($a, $me)->id, $idInA);

        $this->assertArrayHasKey($b->id, StaffChat::unreadByThread($me), 'B was never shown, yet it is marked read');
    }

    public function test_a_tampered_with_does_not_error(): void
    {
        $me = $this->member();
        $this->actAs($me);

        $this->get(LiveChat::getUrl() . '?with=abc')->assertOk();
        $this->get(LiveChat::getUrl() . '?with[]=1')->assertOk();
        Livewire::test(StaffRoom::class)->set('with', -5)->assertSet('with', null);
        Livewire::test(StaffRoom::class)->set('with', 0)->assertSet('with', null);
    }

    public function test_the_team_click_event_opens_the_thread(): void
    {
        $me = $this->member();
        $peer = $this->member();
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'via event');
        $this->actAs($me);

        Livewire::test(StaffRoom::class)->dispatch('staff-chat-open', with: $peer->id)->assertSee('via event');
        Livewire::test(StaffRoom::class)->dispatch('staff-chat-open', with: $me->id)->assertSet('with', null);
    }

    /** A member with the chat but not «Staff online» gets private messages counted, and has no list to open them from. */
    public function test_a_member_without_staff_online_can_reach_who_wrote_to_them(): void
    {
        $me = $this->member(['admin.staff_chat.view', 'admin.staff_chat.post']);
        $peer = $this->member(attributes: ['first_name' => 'Peerzz']);
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'x');
        $this->actAs($me);

        $this->assertSame(1, StaffChat::unread($me));
        Livewire::test(StaffTeam::class)->assertSee('Peerzz');
    }

    /** Re-review of #377: opening a thread without writing does not show the opener to the other member. */
    public function test_merely_opening_a_thread_does_not_reveal_the_opener(): void
    {
        $me = $this->member(['admin.staff_chat.view', 'admin.staff_chat.post']);
        $looker = $this->member(attributes: ['first_name' => 'Lookerzz']);
        $this->actAs($looker);
        Livewire::test(StaffRoom::class)->call('open', $me->id);

        $this->actAs($me);
        Livewire::test(StaffTeam::class)->assertDontSee('Lookerzz');
    }
}
