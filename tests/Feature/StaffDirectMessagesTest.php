<?php

namespace Tests\Feature;

use App\Admin\Pages\LiveChat;
use App\Livewire\Admin\StaffOnline;
use App\Livewire\Admin\StaffRoom;
use App\Livewire\Admin\StaffTeam;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffMessage;
use App\Models\StaffThread;
use App\Support\StaffChat;
use App\Support\StaffPresence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Private threads of two members of staff (docs/livechat.md §7γ): read by the two of them only. */
class StaffDirectMessagesTest extends TestCase
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

    public function test_two_members_talk_privately_and_the_other_one_reads_it(): void
    {
        $haris = $this->member(attributes: ['first_name' => 'Haris']);
        $chris = $this->member(attributes: ['first_name' => 'Chris']);

        $this->actAs($chris);
        Livewire::test(StaffRoom::class)->call('open', $haris->id)->set('body', 'Χάρη, δες λίγο αυτό')->call('send')->assertHasNoErrors();

        $this->actAs($haris);
        Livewire::test(StaffRoom::class)->call('open', $chris->id)
            ->assertSee('Χάρη, δες λίγο αυτό')->assertSee(__('Private with :name', ['name' => $chris->name]));
        $this->assertSame(0, StaffMessage::query()->where('thread_id', StaffChat::room()->id)->count(), 'nothing went to the room');
    }

    public function test_a_third_member_and_an_admin_with_every_permission_cannot_read_a_thread_of_two_others(): void
    {
        $a = $this->member();
        $b = $this->member();
        StaffChat::post($a, StaffChat::direct($a, $b), 'between us');

        foreach ([$this->member(), $this->member(['*'])] as $outsider) {
            $this->actAs($outsider);
            // Whatever member it names, the thread is the outsider's own with that member, never the pair's.
            Livewire::test(StaffRoom::class)->call('open', $a->id)->assertDontSee('between us');
            Livewire::test(StaffRoom::class)->call('open', $b->id)->assertDontSee('between us');
            Livewire::test(StaffRoom::class)->assertDontSee('between us');
            $this->assertFalse(StaffChat::isMember($outsider, StaffChat::direct($a, $b)));
            $this->assertNotNull(StaffChat::post($outsider, StaffChat::direct($a, $b), 'butting in'));
        }
        $this->assertSame(1, StaffMessage::query()->count());
    }

    public function test_a_pair_has_one_thread_whoever_opens_it_and_however_often(): void
    {
        $a = $this->member();
        $b = $this->member();

        $first = StaffChat::direct($a, $b);
        $this->assertSame($first->id, StaffChat::direct($b, $a)->id);
        $this->assertSame($first->id, StaffChat::direct($a, $b)->id);
        $this->assertSame(1, StaffThread::query()->where('kind', StaffChat::DIRECT)->count());
        $this->assertSame(min($a->id, $b->id) . '-' . max($a->id, $b->id), $first->direct_key);
    }

    public function test_no_thread_with_oneself_or_with_someone_without_the_chat(): void
    {
        $me = $this->member();
        $noChat = $this->member(['admin.tickets.viewAny']);
        $gone = $this->member(attributes: ['active' => false]);

        $this->assertNull(StaffChat::direct($me, $me));
        $this->assertNull(StaffChat::direct($me, $noChat));
        $this->assertNull(StaffChat::direct($me, $gone));

        $this->actAs($me);
        $component = Livewire::test(StaffRoom::class)->call('open', $noChat->id);
        $component->assertSet('with', null)->assertSee(__('Staff room'));
        Livewire::test(StaffRoom::class)->call('open', 999999)->assertSet('with', null);
    }

    public function test_a_member_who_left_takes_no_more_messages_and_their_old_ones_stay(): void
    {
        $me = $this->member();
        $peer = $this->member();
        $thread = StaffChat::direct($me, $peer);
        StaffChat::post($peer, $thread, 'before I go');

        $peer->forceFill(['active' => false])->save();

        $this->assertSame(__('This member can no longer receive messages.'), StaffChat::post($me, $thread, 'hello?'));
        $this->assertSame(['before I go'], StaffMessage::query()->where('thread_id', $thread->id)->pluck('body')->all());
    }

    public function test_unread_counts_per_thread_and_in_the_badge_and_only_for_the_reader(): void
    {
        $me = $this->member();
        $haris = $this->member();
        $nikos = $this->member();
        StaffChat::post($haris, StaffChat::direct($haris, $me), 'one');
        StaffChat::post($haris, StaffChat::direct($haris, $me), 'two');
        StaffChat::post($nikos, StaffChat::room(), 'room');
        StaffChat::post($nikos, StaffChat::direct($nikos, $haris), 'not for me');

        $this->assertEquals(['room' => 1, $haris->id => 2], StaffChat::unreadByThread($me), 'per thread, in any order');
        $this->actAs($me);
        $this->assertSame('3', LiveChat::getNavigationBadge());

        Livewire::test(StaffTeam::class)->assertSeeInOrder([__('Staff room'), '1', $haris->name, '2']);

        Livewire::test(StaffRoom::class)->call('open', $haris->id)->call('markSeen', StaffChat::direct($me, $haris)->id, PHP_INT_MAX);
        $this->assertSame(['room' => 1], StaffChat::unreadByThread($me), 'reading one thread leaves the others');
    }

    public function test_reading_marks_only_the_open_thread_and_never_another_pairs(): void
    {
        $me = $this->member();
        $a = $this->member();
        $b = $this->member();
        $theirs = StaffChat::direct($a, $b);
        StaffChat::post($a, $theirs, 'x');

        StaffChat::markRead($me, $theirs, PHP_INT_MAX);

        $this->assertFalse(StaffChat::isMember($me, $theirs), 'markRead does not slip the outsider in');
        $this->assertSame(1, StaffChat::unread($b));
    }

    public function test_the_conversation_follows_the_with_parameter_of_the_page(): void
    {
        $me = $this->member();
        $peer = $this->member(attributes: ['first_name' => 'Peer']);
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'from the link');
        $this->actAs($me);

        $this->get(LiveChat::getUrl(['with' => $peer->id]))->assertOk()->assertSee('from the link');
    }

    public function test_staff_online_names_lead_to_a_private_thread_but_not_ones_own(): void
    {
        $me = $this->member(attributes: ['first_name' => 'Me']);
        $peer = $this->member(attributes: ['first_name' => 'Peer']);
        StaffPresence::heartbeat($me);
        StaffPresence::heartbeat($peer);
        $this->actAs($me);

        $html = Livewire::test(StaffOnline::class)->html();

        $this->assertStringContainsString(e(LiveChat::getUrl(['with' => $peer->id])), $html);
        $this->assertStringNotContainsString(e(LiveChat::getUrl(['with' => $me->id])), $html);
    }

    public function test_one_sending_limit_covers_every_thread(): void
    {
        $me = $this->member();
        $peer = $this->member();
        for ($i = 0; $i < StaffChat::PER_MINUTE; $i++) {
            StaffChat::post($me, $i % 2 ? StaffChat::room() : StaffChat::direct($me, $peer), 'm' . $i);
        }

        $this->assertNotNull(StaffChat::post($me, StaffChat::direct($me, $peer), 'one too many'));
    }

    /** Review of #377: the thread with a member who left opens read-only, its history there, from the Team list. */
    public function test_the_thread_with_a_member_who_left_opens_read_only_with_its_history(): void
    {
        $me = $this->member();
        $peer = $this->member(attributes: ['first_name' => 'Gone']);
        StaffChat::post($peer, StaffChat::direct($peer, $me), 'see you');
        $peer->forceFill(['active' => false])->save();
        $this->actAs($me);

        Livewire::test(StaffTeam::class)->assertSee('Gone');
        Livewire::test(StaffRoom::class)->call('open', $peer->id)
            ->assertSee('see you')->assertSee(__('This member can no longer receive messages.'))
            ->assertDontSeeHtml('wire:submit="send"');
        $this->assertSame(1, StaffThread::query()->where('kind', StaffChat::DIRECT)->count(), 'opening it made nothing new');
    }

    /** Owner 2026-10-23: a new colleague who has not set up 2FA yet can be written to, and reads it once they have. */
    public function test_a_colleague_before_their_2fa_set_up_receives_and_reads_once_set_up(): void
    {
        $me = $this->member();
        $new = Staff::factory()->withoutTwoFactor()->create(['role_id' => Role::create(['name' => 'N' . uniqid(), 'permissions' => self::ALL])->id]);
        $this->actAs($me);

        Livewire::test(StaffRoom::class)->call('open', $new->id)->assertHasNoErrors()
            ->assertSee(__('Private with :name', ['name' => $new->name]))
            ->set('body', 'welcome aboard')->call('send')->assertHasNoErrors();

        $this->assertFalse(StaffChat::canView($new), 'not past 2FA yet: they read nothing');
        $this->assertSame([], StaffChat::unreadByThread($new));

        $new->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->assertSame([$me->id => 1], StaffChat::unreadByThread($new->fresh()));
    }

    /** Owner 2026-10-23: opening someone who cannot be written to says so, instead of quietly staying in the room. */
    public function test_opening_someone_without_the_chat_says_why(): void
    {
        $me = $this->member();
        $noChat = $this->member(['admin.tickets.viewAny']);
        $this->actAs($me);

        Livewire::test(StaffRoom::class)->call('open', $noChat->id)
            ->assertHasErrors('with')->assertSee(__('This member cannot receive messages in the staff chat.'));
    }
}
