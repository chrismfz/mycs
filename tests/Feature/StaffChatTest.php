<?php

namespace Tests\Feature;

use App\Admin\Pages\LiveChat;
use App\Livewire\Admin\StaffRoom;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffMessage;
use App\Support\ChatText;
use App\Support\StaffChat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** The staff's shared room on the Live chat page (docs/livechat.md §7α, §7γ). */
class StaffChatTest extends TestCase
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

    public function test_a_member_writes_in_the_room_and_everyone_with_the_right_reads_it(): void
    {
        $me = $this->member(attributes: ['first_name' => 'Maria']);
        $this->actAs($me);
        Livewire::test(StaffRoom::class)->set('body', "Καλημέρα\nόλοι")->call('send')->assertHasNoErrors()->assertSet('body', '');

        $other = $this->member();
        $this->actAs($other);
        Livewire::test(StaffRoom::class)->assertSee('Maria')->assertSeeHtml("Καλημέρα<br>\nόλοι");
    }

    public function test_the_author_comes_from_the_session_never_from_the_browser(): void
    {
        $me = $this->member();
        $someoneElse = $this->member();
        $this->actAs($me);

        Livewire::test(StaffRoom::class)->set('body', 'hi')->call('send');

        $this->assertSame([$me->id], StaffMessage::query()->pluck('staff_id')->all());
        $this->assertNotSame($someoneElse->id, StaffMessage::query()->value('staff_id'));
    }

    public function test_what_anyone_writes_comes_out_escaped_with_no_image_and_safe_links(): void
    {
        $evil = $this->member();
        $this->actAs($evil);
        $payload = '<img src=x onerror=alert(1)> x" onmouseover="alert(1)" data-x=" ![p](https://e.example/p.png) [a](javascript:alert(1)) https://example.com/a?b=1&c=2.';
        Livewire::test(StaffRoom::class)->set('body', $payload)->call('send');

        $this->actAs($this->member());
        $html = Livewire::test(StaffRoom::class)->html();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString('src="https://e.example/p.png"', $html);
        $this->assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer nofollow"', $html);
    }

    public function test_control_and_format_characters_go_and_an_empty_message_is_refused(): void
    {
        $this->assertSame('ab', ChatText::clean("a\u{202E}\u{200B}\x07b"));
        $this->assertNull(ChatText::clean("\u{200B} \n\t "));
        $this->assertSame(ChatText::MAX, mb_strlen((string) ChatText::clean(str_repeat('α', ChatText::MAX + 50))));

        $me = $this->member();
        $this->actAs($me);
        Livewire::test(StaffRoom::class)->set('body', "\u{200B}")->call('send')->assertHasErrors('body');
        $this->assertSame(0, StaffMessage::query()->count());
    }

    public function test_sending_is_limited_per_member(): void
    {
        $me = $this->member();
        $this->actAs($me);
        $room = StaffChat::room();

        for ($i = 0; $i < StaffChat::PER_MINUTE; $i++) {
            $this->assertNull(StaffChat::post($me, $room, 'm' . $i));
        }
        $this->assertNotNull(StaffChat::post($me, $room, 'one too many'));
        $this->assertSame(StaffChat::PER_MINUTE, StaffMessage::query()->count());
    }

    public function test_without_the_permissions_there_is_no_page_no_reading_and_no_writing(): void
    {
        $reader = $this->member(['admin.staff_chat.view']);
        $this->actAs($reader);
        Livewire::test(StaffRoom::class)->assertDontSeeHtml('wire:submit="send"')->set('body', 'x')->call('send');
        $this->assertSame(0, StaffMessage::query()->count(), 'view without post cannot write');

        StaffChat::post($this->member(), StaffChat::room(), 'secret plans');
        $outsider = $this->member(['admin.tickets.viewAny']);
        $this->actAs($outsider);
        $this->assertFalse(LiveChat::canAccess());
        Livewire::test(StaffRoom::class)->assertDontSee('secret plans');
        $this->assertNull(LiveChat::getNavigationBadge());
    }

    public function test_a_deactivated_member_neither_reads_nor_writes(): void
    {
        $me = $this->member(attributes: ['active' => false]);
        $this->assertFalse(StaffChat::canView($me));
        $this->assertNotNull(StaffChat::post($me, StaffChat::room(), 'hi'));
    }

    public function test_unread_counts_others_messages_since_the_member_last_read(): void
    {
        $me = $this->member();
        $other = $this->member();
        $room = StaffChat::room();
        StaffChat::post($me, $room, 'mine');
        StaffChat::post($other, $room, 'one');
        StaffChat::post($other, $room, 'two');

        $this->assertSame(2, StaffChat::unread($me));
        $this->actAs($me);
        $this->assertSame('2', LiveChat::getNavigationBadge());

        Livewire::test(StaffRoom::class);
        $this->assertSame(0, StaffChat::unread($me));
        $this->assertSame(0, StaffChat::unread($other), 'their own messages never count');
    }

    public function test_the_room_is_one_thread_and_messages_write_no_audit(): void
    {
        $audits = Audit::query()->count();
        $this->assertSame(StaffChat::room()->id, StaffChat::room()->id);
        StaffChat::post($this->member(), StaffChat::room(), 'not in the audit log');

        $this->assertSame($audits, Audit::query()->count());
    }

    public function test_a_member_who_wrote_is_disabled_not_deleted(): void
    {
        $me = $this->member();
        StaffChat::post($me, StaffChat::room(), 'hello');

        $this->assertTrue($me->hasHistory());
        $this->expectException(LogicException::class);
        $me->delete();
    }

    public function test_the_page_opens_from_the_support_menu_with_the_team_and_the_room(): void
    {
        $me = $this->member(self::ALL, ['first_name' => 'Zoe']);
        $this->actAs($me);

        $this->get(LiveChat::getUrl())->assertOk()
            ->assertSee(__('Team'))->assertSee(__('Staff room'))->assertSee('Zoe');
    }

    public function test_the_migration_gives_every_role_the_chat_permissions_once(): void
    {
        $plain = Role::create(['name' => 'Plain' . uniqid(), 'permissions' => ['admin.tickets.viewAny', 'admin.staff_chat.view']]);
        $all = Role::create(['name' => 'All' . uniqid(), 'permissions' => ['*']]);
        $migration = require database_path('migrations/2026_12_28_000000_create_staff_chat.php');

        $migration->grantToEveryRole();
        $migration->grantToEveryRole();

        $this->assertSame(['admin.tickets.viewAny', 'admin.staff_chat.view', 'admin.staff_chat.post'], $plain->fresh()->permissions);
        $this->assertSame(['*'], $all->fresh()->permissions);
    }

    /** ChatText::render is the one unescaped output the chat's views may use (§3 Α, F5). */
    public function test_the_chat_views_print_raw_html_only_through_chattext(): void
    {
        foreach (glob(resource_path('views/livewire/admin/staff-*.blade.php')) as $view) {
            preg_match_all('/\{!!(.*?)!!\}/s', (string) file_get_contents($view), $raw);
            foreach ($raw[1] as $expression) {
                $this->assertMatchesRegularExpression('/^\s*\\\\App\\\\Support\\\\ChatText::render\(/', $expression, basename($view));
            }
        }
    }
}
