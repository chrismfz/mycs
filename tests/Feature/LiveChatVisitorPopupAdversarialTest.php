<?php

namespace Tests\Feature;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLibrary;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Enums\StaffPresenceStatus;
use App\Livewire\ChatPopup;
use App\Livewire\Contact;
use App\Models\LiveChat;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\Captcha\Altcha;
use App\Support\ChatVisitor;
use App\Support\StaffPresence;
use App\Support\VisitorChats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #888 (live chat, step 4, PR 2: the popup /chat), written without the author's context.
 *
 * - ADV-888-1: with tickets turned off and no one available, «Continue as a visitor» embeds the Contact form, whose
 *   DisabledIf aborts 404 inside the popup's render: the whole popup breaks instead of saying no one is available.
 * - ADV-888-2: the popup's layout promises «no script but ours» where a visitor writes (§11 PR 2), but the embedded
 *   Contact form brings the site captcha's third-party script (Cloudflare Turnstile, Google reCAPTCHA, hCaptcha).
 * - ADV-888-3: «1 open chat per session» (§3 Ε) is checked outside the lock, from the session as the request loaded it:
 *   two requests of one session at once (two tabs, a double submit) both start a chat; the first stays open in the
 *   operators' queue with no one holding it.
 * - ADV-888-4: the visitor's email may be our own department's address, which the Contact form refuses
 *   (Contact::ours): kept on the chat, it is where a guest ticket from the chat (§7, step 5) would mail us ourselves.
 *
 * test_control_* are attacks tried that are blocked.
 */
class LiveChatVisitorPopupAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply', 'admin.staff_chat.view', 'admin.staff_online.view'];

    private TicketDepartment $sales;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true, 'livechat_sites' => ['www.example.com']]);
        $this->sales = TicketDepartment::query()->create(['name' => 'Sales', 'email' => 'sales@ourcompany.example']);
        $this->online($this->operator($this->sales));
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            protected function difficulty(): array
            {
                return ['cost' => 1, 'counter' => [1, 5]];
            }
        });
    }

    private function operator(TicketDepartment ...$departments): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'O' . uniqid(), 'permissions' => self::OPERATOR])->id]);
        foreach ($departments as $department) {
            $department->chatStaff()->attach($staff->id);
        }

        return $staff;
    }

    private function online(Staff $staff): Staff
    {
        StaffPresence::setStatus($staff, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($staff);

        return $staff;
    }

    private function nobody(): void
    {
        foreach (Staff::query()->get() as $staff) {
            StaffPresence::setStatus($staff, StaffPresenceStatus::Away);
        }
        Cache::forget('live-chat-available');
    }

    private function solved(): string
    {
        $challenge = Challenge::fromArray($this->getJson(route('chat.altcha'))->assertOk()->json());
        $solution = (new AltchaLibrary)->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2));

        return (new Payload($challenge, $solution))->toBase64();
    }

    private function popup(array $query = [])
    {
        return Livewire::withQueryParams($query)->test(ChatPopup::class);
    }

    private function start(array $fields = [], array $query = [])
    {
        $popup = $this->popup($query)->call('asGuest');
        foreach ($fields + ['message' => 'Hello', 'captcha' => $this->solved()] as $field => $value) {
            $popup->set($field, $value);
        }

        return $popup->call('start');
    }

    private function newVisitor(): void
    {
        ChatVisitor::forget();
        session()->regenerate(true);
    }

    private function fromAddress(string $ip): void
    {
        request()->server->set('REMOTE_ADDR', $ip);
    }

    // ---- findings

    public function test_adv_888_1_tickets_off_and_no_one_available_still_leaves_a_working_popup(): void
    {
        $this->storeSettings(['tickets_disabled' => true]);
        $this->nobody();

        // Expected: the popup says no one is available (no guest ticket form to offer), not a 404 of the whole popup.
        $this->popup()->call('asGuest')->assertOk()->assertSee(__('Live chat'));
    }

    public function test_adv_888_2_no_third_party_script_in_the_popup_where_a_visitor_writes(): void
    {
        $this->storeSettings(['captcha' => 'turnstile', 'captcha_site_key' => 'k', 'captcha_secret_key' => 's']);
        $this->nobody();

        // The popup's «no one available» screen embeds the Contact form as its only child (Livewire's test stubs children).
        $this->popup()->call('asGuest')->set('message', 'my words')->assertSeeHtml('wire:name="contact"');
        $contact = Livewire::test(Contact::class, ['prefill' => 'my words', 'embedded' => true]);
        // Its script (challenges.cloudflare.com) goes into the page's head as a Livewire @assets; the widget's code is here.
        $this->assertStringNotContainsString('turnstile', strtolower($contact->html()));
    }

    public function test_adv_888_3_one_open_chat_per_session_even_when_two_requests_of_it_start_at_once(): void
    {
        // Request 1 and request 2 of one session both loaded it before either started a chat: request 2 still sees a
        // session without a token when it calls start, and only the address's limit (3) is checked under the lock.
        $loaded = session()->all();
        $this->assertNull(VisitorChats::start(['message' => 'tab one']));
        session()->flush();
        session()->put($loaded); // what request 2 read when it began
        $second = VisitorChats::start(['message' => 'tab two']);

        $this->assertNotNull($second, 'the second start of one session is refused');
        $this->assertSame(1, LiveChat::query()->where('status', '!=', LiveChat::CLOSED)->count());
    }

    public function test_adv_888_4_our_own_address_is_not_kept_as_the_visitors_email(): void
    {
        $this->start(['email' => 'Sales@OurCompany.example'])->assertHasErrors('message');
        $this->assertSame(0, LiveChat::query()->count());
    }

    // ---- controls

    public function test_control_a_bot_without_altcha_starts_nothing(): void
    {
        $this->popup()->call('asGuest')->set('message', 'spam')->call('start')->assertHasErrors('captcha');
        $this->popup()->call('asGuest')->set('message', 'spam')->set('captcha', base64_encode('{"algorithm":"SHA-256"}'))->call('start')->assertHasErrors('captcha');
        $this->assertSame(0, LiveChat::query()->count());
    }

    public function test_control_locked_labels_and_wanted_department_cannot_be_set(): void
    {
        foreach (['src' => 'evil.example', 'page' => '/x', 'wanted' => 1] as $field => $value) {
            try {
                $this->popup()->set($field, $value);
                $this->fail("$field was set");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_control_crafted_link_labels_are_dropped(): void
    {
        $this->start([], ['src' => 'evil.example', 'page' => 'javascript:alert(1)', 'dept' => '1 OR 1=1'])->assertHasNoErrors();
        $chat = LiveChat::query()->sole();
        $this->assertSame([null, null, $this->sales->id], [$chat->src, $chat->page, $chat->department_id]);
        $this->newVisitor();
        $this->travel(4)->minutes();
        StaffPresence::heartbeat(Staff::query()->first());
        Cache::forget('live-chat-available');
        $this->start([], ['src' => 'www.example.com', 'page' => "//evil.example/\u{202E}x"])->assertHasNoErrors();
        $this->assertNull(LiveChat::query()->latest('id')->first()->page);
    }

    public function test_control_an_ipv6_64_counts_as_one_address(): void
    {
        foreach (['2001:db8:1:1::1', '2001:db8:1:1::2', '2001:db8:1:1:ffff::3'] as $ip) {
            $this->fromAddress($ip);
            $this->assertNull(VisitorChats::start(['message' => 'hi']));
            LiveChat::query()->update(['status' => LiveChat::CLOSED]);
            $this->newVisitor();
        }
        $this->fromAddress('2001:db8:1:1:abcd::9');
        $this->assertSame(__('Too many chats started, wait a few minutes.'), VisitorChats::start(['message' => 'hi']));
        $this->fromAddress('2001:db8:1:2::1');
        $this->assertNull(VisitorChats::start(['message' => 'another network']));
    }

    public function test_control_message_flood_across_chats_of_one_address_stops_at_its_limit(): void
    {
        $sent = 0;
        for ($i = 0; $i < VisitorChats::OPEN_PER_IP; $i++) {
            $this->assertNull(VisitorChats::start(['message' => 'hi']));
            $chat = ChatVisitor::chat();
            for ($j = 0; $j < VisitorChats::PER_MINUTE; $j++) {
                $sent += VisitorChats::post($chat, "m$j") === null ? 1 : 0;
            }
            $this->newVisitor();
        }
        $this->assertLessThanOrEqual(VisitorChats::PER_MINUTE_PER_IP, $sent);
    }

    public function test_control_a_customer_or_login_as_client_cannot_write_into_the_sessions_visitor_chat(): void
    {
        $this->start()->assertHasNoErrors();
        $chat = LiveChat::query()->sole();
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->assertNull(ChatVisitor::chat());
        $this->assertSame(__('You may not write here.'), VisitorChats::post($chat, 'as the customer'));
        auth('web')->logout();
        session()->put('impersonating', $user->id);
        $this->assertSame(__('You may not write here.'), VisitorChats::post($chat, 'as staff'));
        $this->popup()->set('body', 'x')->call('send')->call('end');
        $this->assertSame(1, $chat->messages()->count());
        $this->assertNotSame(LiveChat::CLOSED, $chat->fresh()->status);
    }

    public function test_control_a_department_name_and_the_customers_names_are_escaped(): void
    {
        $other = TicketDepartment::query()->create(['name' => '<img src=x onerror=alert(1)>']);
        $this->online($this->operator($other));
        Cache::forget('live-chat-available');
        $html = $this->popup()->call('asGuest')->html();
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }
}
