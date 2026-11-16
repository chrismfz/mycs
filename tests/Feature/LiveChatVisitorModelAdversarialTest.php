<?php

namespace Tests\Feature;

use App\Actions\Auth\Login;
use App\Admin\Resources\UserResource\Pages\EditUser;
use App\Enums\StaffPresenceStatus;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\ChatVisitor;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #887 (live chat, anonymous visitors' model, PR 1 of 4): failing tests for what the review found,
 * and passing controls for attacks that are blocked.
 *
 * - ADV-887-1: the token still opens a chat after it was closed, for the rest of the 24 hours (§3 Γ / F22: «το token
 *   σβήνεται από το session στο κλείσιμο»). On a shared computer, the next person reads the previous visitor's
 *   transcript, name and email.
 * - ADV-887-2: a customer's login in the same session keeps the visitor's token working (G2: «από εκείνη τη στιγμή το
 *   token του επισκέπτη δεν δουλεύει»; §3 Δ: a session never holds a customer and a visitor's token together).
 * - ADV-887-3: CUSTOMER_VISIBLE now includes `visitor`, so a visitor chat linked to a client by staff (§6α) shows the
 *   visitor's words through customerMessages(), the customer's reader (G1, F2: never in the client area).
 * - ADV-887-4: a visitor's message in an operator's taken chat rings no alert: alerts() counts only `client` messages.
 */
class LiveChatVisitorModelAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply', 'admin.staff_chat.view', 'admin.staff_online.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true]);
    }

    private function chat(array $attributes = []): LiveChat
    {
        $department = TicketDepartment::query()->create(['name' => 'Sales ' . uniqid()]);

        return LiveChat::query()->create($attributes + ['department_id' => $department->id, 'status' => LiveChat::WAITING,
            'last_participant_message_at' => now()]);
    }

    private function store(): Session
    {
        $session = app('session')->driver();
        $session->start();

        return $session;
    }

    public function test_adv_887_1_a_closed_chat_is_no_longer_reachable_by_the_token(): void
    {
        $this->storeSettings(['livechat_idle_minutes' => 30]);
        $session = $this->store();
        $chat = $this->chat(['visitor_name' => 'Alice', 'visitor_email' => 'alice@example.com']);
        $chat->messages()->create(['author_type' => LiveChatMessage::VISITOR, 'body' => 'my private question']);
        ChatVisitor::issue($chat, $session);

        $this->travel(31)->minutes();
        $this->assertSame(1, LiveChats::closeIdle());
        $this->travel(5)->minutes();

        // The next person at the same browser, well within the 24 hours.
        $this->assertNull(ChatVisitor::chat($session)?->id, 'the token goes on close (§3 Γ, F22): a closed chat is not readable by the session');
    }

    public function test_adv_887_2_a_customer_login_in_the_session_stops_the_visitors_token(): void
    {
        $session = $this->store();
        $chat = $this->chat();
        ChatVisitor::issue($chat, $session);
        $this->assertSame($chat->id, ChatVisitor::chat($session)?->id);

        // Someone logs in as a customer in this browser (here, another tab; or the mycs_remember cookie).
        $user = User::factory()->create();
        app(Login::class)->execute($user);
        $this->assertTrue(auth('web')->check());

        $this->assertNull(ChatVisitor::chat(app('session')->driver())?->id, 'G2: the visitor\'s token works no more once a customer is logged in');
    }

    public function test_adv_887_3_a_linked_visitor_chat_never_shows_the_visitors_words_to_the_customer(): void
    {
        $user = User::factory()->create();
        $chat = $this->chat(['started_anonymously' => true]);
        $chat->messages()->create(['author_type' => LiveChatMessage::VISITOR, 'body' => 'written by whoever sat at the browser']);
        $chat->messages()->create(['author_type' => LiveChatMessage::STAFF, 'body' => 'operator reply']);
        // Staff link the closed chat to the client by hand (§6α): token revoked, the client set.
        $chat->forceFill(['status' => LiveChat::CLOSED, 'closed_at' => now(), 'client_id' => $user->client()->id])->save();
        ChatVisitor::revoke($chat);

        $types = LiveChats::customerMessages($chat->fresh())->pluck('author_type')->all();

        $this->assertNotContains(LiveChatMessage::VISITOR, $types, 'G1/F2: a visitor\'s text never reaches the client area');
    }

    public function test_adv_887_4_a_visitors_message_rings_the_operator(): void
    {
        $department = TicketDepartment::query()->create(['name' => 'Sales']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'O' . uniqid(), 'permissions' => self::OPERATOR])->id]);
        $department->chatStaff()->attach($staff->id);
        StaffPresence::setStatus($staff, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($staff);

        $chat = LiveChat::query()->create(['department_id' => $department->id, 'status' => LiveChat::WAITING, 'last_participant_message_at' => now()]);
        $chat->forceFill(['started_anonymously' => true])->save();
        $chat->messages()->create(['author_type' => LiveChatMessage::VISITOR, 'body' => 'hello']);
        $this->assertTrue(LiveChats::claim($staff, $chat));
        $later = $chat->messages()->create(['author_type' => LiveChatMessage::VISITOR, 'body' => 'are you there?']);

        $this->assertSame($later->id, LiveChats::alerts($staff)['message_latest'], 'the visitor\'s later message rings as a customer\'s does');
    }

    // ---- controls: attacks tried and blocked

    public function test_control_the_chat_id_alone_or_a_guessed_hash_reaches_nothing(): void
    {
        $session = $this->store();
        $chat = $this->chat();
        ChatVisitor::issue($chat, $session);
        $hash = (string) LiveChat::query()->whereKey($chat->id)->value('visitor_token_hash');

        $other = $this->store();
        foreach ([(string) $chat->id, $hash, strtoupper($hash), ' ' . $session->get(ChatVisitor::SESSION_KEY)] as $guess) {
            $other->put(ChatVisitor::SESSION_KEY, $guess);
            $this->assertNull(ChatVisitor::chat($other), $guess);
        }
    }

    public function test_control_the_old_session_id_keeps_no_token(): void
    {
        $session = $this->store();
        $session->save();
        $old = $session->getId();
        ChatVisitor::issue($this->chat(), $session);
        $session->save();

        $this->assertStringNotContainsString(ChatVisitor::SESSION_KEY, (string) $session->getHandler()->read($old));
    }

    public function test_control_login_as_client_from_the_admin_clears_the_token(): void
    {
        $session = $this->store();
        ChatVisitor::issue($this->chat(), $session);
        $this->assertTrue(ChatVisitor::holds($session));

        EditUser::impersonate(User::factory()->create());

        $this->assertFalse(ChatVisitor::holds(app('session')->driver()));
    }

    public function test_control_the_hash_never_leaves_the_model_in_arrays(): void
    {
        $session = $this->store();
        $chat = $this->chat();
        ChatVisitor::issue($chat, $session);
        $fresh = LiveChat::query()->find($chat->id);

        $this->assertArrayNotHasKey('visitor_token_hash', $fresh->toArray());
        $this->assertArrayNotHasKey('visitor_token_hash', $fresh->attributesToArray());
        $this->assertArrayNotHasKey('visitor_token_hash', LiveChat::query()->get()->toArray()[0]);
        $this->assertStringNotContainsString((string) $fresh->visitor_token_hash, json_encode(LiveChat::query()->paginate()));
    }

    public function test_control_the_hash_cannot_be_mass_assigned(): void
    {
        $chat = $this->chat(['visitor_token_hash' => hash('sha256', 'known')]);

        $this->assertNull($chat->fresh()->visitor_token_hash);
    }

    public function test_control_page_and_site_reject_more_payloads(): void
    {
        foreach (["/a\u{2028}b", "/a\u{00A0}b", "/a\u{0085}b", "/a\u{FEFF}b", "/a\u{E0041}b", "/a\u{2066}b", "/a\u{3000}b",
            "\u{FF0F}evil", '/\\evil.example', "/a\x00b", "\t/a", ' /a'] as $page) {
            $this->assertNull(ChatVisitor::page($page), bin2hex($page));
        }
        $this->assertSame('/%0d%0aSet-Cookie:x', ChatVisitor::page('/%0d%0aSet-Cookie:x'), 'percent-escapes stay text');
        $this->assertSame('/a', ChatVisitor::page('/a#?b'));

        $this->storeSettings(['livechat_sites' => ['www.example.com']]);
        $this->assertSame('www.example.com', ChatVisitor::site("www.example.com\0"), 'trim takes the NUL; the label is the listed value, not the input');
        foreach (['www.example.com:443', 'www.example.com.', 'WWW.EXAMPLE.COM/x', 'www.exampIe.com', "www.ex\u{0430}mple.com"] as $src) {
            $this->assertNull(ChatVisitor::site($src), bin2hex($src));
        }
    }

    public function test_control_an_expired_token_is_not_revived_by_a_staff_reply(): void
    {
        $this->storeSettings(['livechat_visitor_hours' => 1]);
        $session = $this->store();
        $chat = $this->chat();
        ChatVisitor::issue($chat, $session);
        $this->travel(61)->minutes();
        $chat->messages()->create(['author_type' => LiveChatMessage::STAFF, 'body' => 'still there?']);

        $this->assertNull(ChatVisitor::chat($session));
    }
}
