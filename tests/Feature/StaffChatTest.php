<?php

namespace Tests\Feature;

use App\Admin\Pages\LiveChat;
use App\Livewire\Admin\StaffRoom;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffMessage;
use App\Models\StaffThread;
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
        $this->assertSame(2, StaffChat::unread($me), 'rendering (a poll from a tab in the background) marks nothing read');

        Livewire::test(StaffRoom::class)->call('markSeen', StaffChat::room()->id, PHP_INT_MAX);
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

    /** ChatText::render is the one unescaped output the chat's views may use (§3 Α, F5). */
    public function test_the_chat_views_print_raw_html_only_through_chattext(): void
    {
        foreach ([...glob(resource_path('views/livewire/admin/staff-*.blade.php')), resource_path('views/admin/pages/live-chat.blade.php')] as $view) {
            preg_match_all('/\{!!(.*?)!!\}/s', (string) file_get_contents($view), $raw);
            foreach ($raw[1] as $expression) {
                $this->assertMatchesRegularExpression('/^\s*\\\\App\\\\Support\\\\ChatText::render\(/', $expression, basename($view));
            }
        }
    }

    /** Review of #373, R1: an address touched by a quote, a bracket or `&` links to itself and shows as written. */
    public function test_a_quoted_or_bracketed_address_links_to_itself_and_shows_as_written(): void
    {
        foreach (['"https://example.com"', '<https://example.com>', "'https://example.com'", 'https://example.com/&'] as $text) {
            $html = ChatText::render($text)->toHtml();
            $this->assertStringContainsString('href="https://example.com' . (str_ends_with($text, '&') ? '/&amp;' : '') . '"', $html, $text);
            $this->assertSame($text, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5), $text);
        }
        $this->assertStringNotContainsString('<svg', ChatText::render('http://a.b/<svg onload=alert(1)>')->toHtml());
    }

    /** Review of #373, R2: the room listens only to events Livewire really dispatches. */
    public function test_the_room_listens_only_to_events_livewire_dispatches(): void
    {
        $view = (string) file_get_contents(resource_path('views/livewire/admin/staff-room.blade.php'));
        $js = (string) file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.js'));
        $this->assertStringNotContainsString('livewire:update', $view);
        $this->assertStringContainsString('MutationObserver', $view, 'follows new messages without a Livewire event');
        preg_match_all('/x-on:(livewire:[a-z-]+)/', $view, $events);
        foreach ($events[1] as $event) {
            $this->assertStringContainsString('"' . $event . '"', $js, "Livewire never dispatches {$event}");
        }
    }

    /** Review of #373, R3: a member still on the mandatory 2FA set-up neither reads nor writes. */
    public function test_a_member_without_2fa_set_up_does_not_read_the_room(): void
    {
        StaffChat::post($this->member(), StaffChat::room(), 'the new root password is in the vault');
        $pending = Staff::factory()->withoutTwoFactor()->create(['role_id' => Role::create(['name' => 'P' . uniqid(), 'permissions' => self::ALL])->id]);
        $this->actAs($pending);

        $this->assertFalse(StaffChat::canView($pending));
        Livewire::test(StaffRoom::class)->assertDontSee('root password');
        $this->assertNotNull(StaffChat::post($pending, StaffChat::room(), 'hi'));
    }

    /** Review of #373, R5: an emoji sequence keeps its joiner; a joiner anywhere else goes. */
    public function test_emoji_sequences_survive_and_a_lone_joiner_goes(): void
    {
        $this->assertSame("👩\u{200D}💻", ChatText::clean("👩\u{200D}💻"));
        $this->assertSame('ab', ChatText::clean("a\u{200D}b"));
    }

    /** Review of #373, R6: the badge stops counting at 99. */
    public function test_the_badge_stops_counting_at_99(): void
    {
        $me = $this->member();
        $room = StaffChat::room();
        $other = $this->member();
        foreach (range(1, StaffChat::UNREAD_CAP + 5) as $i) {
            $room->messages()->create(['staff_id' => $other->id, 'body' => 'm' . $i]);
        }
        $this->actAs($me);

        $this->assertSame(StaffChat::UNREAD_CAP + 1, StaffChat::unread($me));
        $this->assertSame('99+', LiveChat::getNavigationBadge());
    }

    /** Review of #373, R7: post() writes only to the room until private threads check their members (step 2b). */
    public function test_post_refuses_any_thread_but_the_room(): void
    {
        $pair = StaffThread::query()->create(['kind' => 'direct', 'direct_key' => '1-2']);

        $this->assertNotNull(StaffChat::post($this->member(), $pair, 'hi'));
        $this->assertSame(0, StaffMessage::query()->count());
    }

    /** Re-review of #373: a sequence whose joiner follows an emoji selector or a skin tone keeps it too. */
    public function test_zwj_sequences_with_a_selector_or_skin_tone_survive(): void
    {
        foreach (["❤\u{FE0F}\u{200D}🔥", "🏳\u{FE0F}\u{200D}🌈", "👩\u{1F3FD}\u{200D}💻"] as $emoji) {
            $this->assertSame($emoji, ChatText::clean($emoji), (string) json_encode($emoji));
        }
        $this->assertSame('👩💻', ChatText::clean("👩\u{202E}\u{200D}💻"), 'a bidi character between them still goes, and so does the joiner');
    }

    /** Re-review of #373: an address needs a host, not just `https://`. */
    public function test_a_bare_scheme_is_not_a_link(): void
    {
        $this->assertStringNotContainsString('<a ', ChatText::render('see https://). or https://')->toHtml());
        $this->assertStringContainsString('href="https://a.b"', ChatText::render('(https://a.b)')->toHtml());
    }
}
