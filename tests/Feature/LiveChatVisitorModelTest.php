<?php

namespace Tests\Feature;

use App\Http\Middleware\ChatPopupHeaders;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\ChatVisitor;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Live chat with anonymous visitors, step 4, PR 1: the model, the visitor's token and the popup's headers (docs/livechat.md §3 Γ, §4, §6). */
class LiveChatVisitorModelTest extends TestCase
{
    use RefreshDatabase;

    private function chat(array $attributes = []): LiveChat
    {
        $department = TicketDepartment::query()->create(['name' => 'Sales']);

        return LiveChat::query()->create($attributes + ['department_id' => $department->id, 'status' => LiveChat::WAITING,
            'last_participant_message_at' => now()]);
    }

    private function store(): Session
    {
        $session = app('session')->driver();
        $session->start();

        return $session;
    }

    public function test_the_token_is_random_held_by_the_session_only_and_kept_hashed(): void
    {
        $session = $this->store();
        $chat = $this->chat();

        ChatVisitor::issue($chat, $session);

        $token = $session->get(ChatVisitor::SESSION_KEY);
        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(43, strlen($token), '256 bits');
        $row = (array) DB::table('live_chats')->find($chat->id);
        $this->assertSame(hash('sha256', $token), $row['visitor_token_hash']);
        $this->assertNotContains($token, array_map('strval', $row), 'the token itself is never stored');
        $this->assertTrue((bool) $row['started_anonymously']);
        $this->assertSame($chat->id, ChatVisitor::chat($session)?->id);
        // Never in an array, JSON or a Livewire snapshot.
        $this->assertArrayNotHasKey('visitor_token_hash', $chat->fresh()->toArray());
        $this->assertStringNotContainsString($token, $chat->fresh()->toJson());
    }

    public function test_starting_renews_the_session_and_destroys_the_old_id(): void
    {
        $session = $this->store();
        $session->put('planted', 'x');
        $session->save();
        $old = $session->getId();

        ChatVisitor::issue($this->chat(), $session);

        $this->assertNotSame($old, $session->getId());
        $this->assertStringNotContainsString('planted', (string) app('session')->driver()->getHandler()->read($old), 'the old session record is gone');
    }

    public function test_two_issues_two_tokens_and_another_token_reaches_nothing(): void
    {
        $first = $this->store();
        $mine = $this->chat();
        ChatVisitor::issue($mine, $first);
        $token = $first->get(ChatVisitor::SESSION_KEY);

        $other = $this->chat();
        ChatVisitor::issue($other, $first);

        $this->assertNotSame($token, $first->get(ChatVisitor::SESSION_KEY));
        $this->assertSame($other->id, ChatVisitor::chat($first)?->id);
        $first->put(ChatVisitor::SESSION_KEY, 'not-a-token');
        $this->assertNull(ChatVisitor::chat($first));
        $this->assertFalse(ChatVisitor::holds($first), 'an unknown token leaves the session');
        $first->put(ChatVisitor::SESSION_KEY, ['array']);
        $this->assertNull(ChatVisitor::chat($first));
    }

    public function test_the_token_expires_after_the_last_message_and_leaves_the_session(): void
    {
        $this->storeSettings(['livechat_visitor_hours' => 2]);
        $session = $this->store();
        $chat = $this->chat();
        ChatVisitor::issue($chat, $session);

        $this->travel(119)->minutes();
        $this->assertNotNull(ChatVisitor::chat($session));
        $chat->forceFill(['last_participant_message_at' => now()])->save();
        $this->travel(119)->minutes();
        $this->assertNotNull(ChatVisitor::chat($session), 'counted from the last message');
        $this->travel(2)->minutes();
        $this->assertNull(ChatVisitor::chat($session));
        $this->assertFalse(ChatVisitor::holds($session));
    }

    public function test_a_revoked_token_or_a_chat_linked_to_a_client_reaches_nothing(): void
    {
        $session = $this->store();
        $chat = $this->chat();
        ChatVisitor::issue($chat, $session);
        $token = $session->get(ChatVisitor::SESSION_KEY);

        $chat->forceFill(['client_id' => User::factory()->create()->client()->id])->save();
        $this->assertNull(ChatVisitor::chat($session), 'a chat with a client is never a visitor\'s');

        $chat->forceFill(['client_id' => null])->save();
        $session->put(ChatVisitor::SESSION_KEY, $token);
        ChatVisitor::revoke($chat);
        $this->assertNull(ChatVisitor::chat($session));
        $this->assertTrue($chat->fresh()->started_anonymously, 'the mark stays');
        $this->assertNull($chat->fresh()->visitor_token_hash);
    }

    public function test_the_token_is_never_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message . json_encode($event->context);
        });
        $session = $this->store();
        $chat = $this->chat();
        ChatVisitor::issue($chat, $session);
        ChatVisitor::chat($session);
        ChatVisitor::revoke($chat);
        $token = (string) $session->get(ChatVisitor::SESSION_KEY);

        $this->assertNotSame('', $token);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
            $this->assertStringNotContainsString(hash('sha256', $token), $line);
        }
    }

    public function test_the_site_only_from_the_list(): void
    {
        $this->storeSettings(['livechat_sites' => ['www.example.com', 'Unblock.Example.com']]);

        $this->assertSame('www.example.com', ChatVisitor::site('www.example.com'));
        $this->assertSame('Unblock.Example.com', ChatVisitor::site(' unblock.example.COM '));
        foreach ([null, '', 'evil.example', 'www.example.com.evil', '<script>alert(1)</script>', 'x" onmouseover="alert(1)" data-x="'] as $src) {
            $this->assertNull(ChatVisitor::site($src), (string) $src);
        }
        $this->storeSettings(['livechat_sites' => []]);
        $this->assertNull(ChatVisitor::site('www.example.com'));
    }

    public function test_the_page_is_a_clean_path_only(): void
    {
        $this->assertSame('/hosting/vps', ChatVisitor::page('/hosting/vps'));
        $this->assertSame('/pricing', ChatVisitor::page('/pricing?utm_source=x&token=secret#top'));
        $this->assertSame('/', ChatVisitor::page('/#x'));
        $this->assertSame(ChatVisitor::PAGE_MAX, mb_strlen((string) ChatVisitor::page('/' . str_repeat('α', 400))));
        foreach ([null, '', 'hosting', 'https://evil.example/x', '//evil.example/x', 'javascript:alert(1)', '/a b', "/a\nb", "/a\tb",
            "/a\u{202E}b", "/a\u{200B}b", "/a\x7Fb", '/x" onmouseover="alert(1)" data-x="', '/a\\b', "/\xC3"] as $page) {
            $this->assertNull(ChatVisitor::page($page), var_export($page, true));
        }
    }

    public function test_the_popup_headers_on_the_login(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $this->assertContains("frame-ancestors 'none'", $response->headers->all('content-security-policy'));
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_the_popup_headers_stay_stricter_than_the_app_wide_ones(): void
    {
        Route::middleware(['web', ChatPopupHeaders::class])->get('/__popup-probe', fn () => 'ok');

        $response = $this->get('/__popup-probe');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $this->assertContains("frame-ancestors 'none'", $response->headers->all('content-security-policy'));
    }

    public function test_login_as_client_clears_the_visitors_token(): void
    {
        $staff = Staff::factory()->withPermissions(['*'])->create();
        $customer = User::factory()->create();
        $this->actingAs($staff, 'staff')->withSession(['impersonating' => $customer->id, ChatVisitor::SESSION_KEY => 'some-token',
            CurrentClient::IMPERSONATING_KEY => $customer->client()->id, CurrentClient::SESSION_KEY => $customer->client()->id]);

        $this->get(route('dashboard'))->assertOk();

        $this->assertFalse(session()->has(ChatVisitor::SESSION_KEY));
    }

    public function test_a_visitors_message_keeps_the_chat_from_closing_idle(): void
    {
        $this->storeSettings(['livechat_idle_minutes' => 30]);
        $chat = $this->chat(['started_anonymously' => true]);
        $this->travel(31)->minutes();
        $chat->messages()->create(['author_type' => LiveChatMessage::VISITOR, 'body' => 'hello']);

        $this->assertSame(0, LiveChats::closeIdle());
        $this->travel(31)->minutes();
        $this->assertSame(1, LiveChats::closeIdle());
    }

    public function test_guest_chat_needs_both_settings(): void
    {
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => false]);
        $this->assertFalse(ChatVisitor::guestEnabled());
        $this->storeSettings(['livechat_enabled' => false, 'livechat_guest_enabled' => true]);
        $this->assertFalse(ChatVisitor::guestEnabled());
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true]);
        $this->assertTrue(ChatVisitor::guestEnabled());
    }
}
