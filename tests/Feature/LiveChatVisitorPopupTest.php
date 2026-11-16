<?php

namespace Tests\Feature;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLibrary;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Enums\StaffPresenceStatus;
use App\Livewire\Admin\ChatQueue;
use App\Livewire\Admin\CustomerChat;
use App\Livewire\ChatPopup;
use App\Livewire\Contact;
use App\Models\ClientUser;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\Captcha\Altcha;
use App\Support\ChatVisitor;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use App\Support\VisitorChats;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Live chat with anonymous visitors, step 4, PR 2: the popup /chat (docs/livechat.md §3, §4, §7 «Επισκέπτης»). */
class LiveChatVisitorPopupTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply', 'admin.staff_chat.view', 'admin.staff_online.view'];

    private const XSS = ['x" onmouseover="alert(1)" data-x="', '<script>alert(1)</script>', '<img src=x onerror=alert(1)>'];

    private TicketDepartment $sales;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true, 'livechat_sites' => ['www.example.com']]);
        $this->sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $this->online($this->operator($this->sales));
        // The real difficulty takes seconds in PHP: a tiny one, the same code otherwise.
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

    /** A real solution of a challenge from the chat's own route (F14). */
    private function solved(): string
    {
        $challenge = Challenge::fromArray($this->getJson(route('chat.altcha'))->assertOk()->json());
        $solution = (new AltchaLibrary)->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2));
        $this->assertNotNull($solution);

        return (new Payload($challenge, $solution))->toBase64();
    }

    /** @return Testable */
    private function popup(array $query = [])
    {
        return Livewire::withQueryParams($query)->test(ChatPopup::class);
    }

    private function start(array $fields = [], array $query = [])
    {
        $popup = $this->popup($query)->call('asGuest');
        foreach ($fields + ['message' => 'Does the VPS 2 have IPv6?', 'captcha' => $this->solved()] as $field => $value) {
            $popup->set($field, $value);
        }

        return $popup->call('start');
    }

    /** The operators still connected after time passed in a test. */
    private function heartbeats(): void
    {
        foreach (Staff::query()->get() as $staff) {
            StaffPresence::heartbeat($staff);
        }
        Cache::forget('live-chat-available');
    }

    /** A new visitor: another browser session. */
    private function newVisitor(): void
    {
        ChatVisitor::forget();
        session()->regenerate(true);
    }

    public function test_opening_the_popup_changes_nothing_and_carries_the_headers(): void
    {
        $response = $this->get(route('chat', ['src' => 'www.example.com', 'page' => '/x', 'dept' => $this->sales->id]));

        $response->assertOk()->assertSee(__('Continue as a visitor'));
        $this->assertSame(0, LiveChat::query()->count());
        $this->assertFalse(session()->has(ChatVisitor::SESSION_KEY));
        $this->assertContains("frame-ancestors 'none'", $response->headers->all('content-security-policy'));
        $response->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_a_visitor_starts_a_chat_with_the_labels_checked_and_the_token_only_in_the_session(): void
    {
        $before = session()->getId();
        $popup = $this->start(['name' => 'Γιώργος', 'email' => 'G@Example.com'], ['src' => 'WWW.example.com', 'page' => '/hosting/vps?utm=1#x']);

        $popup->assertHasNoErrors()->assertRedirect(route('chat'));
        $chat = LiveChat::query()->sole();
        $this->assertSame(LiveChat::WAITING, $chat->status);
        $this->assertNull($chat->client_id);
        $this->assertTrue($chat->started_anonymously);
        $this->assertSame($this->sales->id, $chat->department_id);
        $this->assertSame(['Γιώργος', 'g@example.com', 'www.example.com', '/hosting/vps'], [$chat->visitor_name, $chat->visitor_email, $chat->src, $chat->page]);
        $this->assertSame(LiveChatMessage::VISITOR, $chat->messages()->sole()->author_type);
        $this->assertNotSame($before, session()->getId(), 'the session was renewed (F8)');

        $token = (string) session()->get(ChatVisitor::SESSION_KEY);
        $this->assertNotSame('', $token);
        $this->assertSame(hash('sha256', $token), $chat->getRawOriginal('visitor_token_hash'));
        $page = $this->get(route('chat'))->assertOk()->assertSee('Does the VPS 2 have IPv6?');
        $this->assertStringNotContainsString($token, $page->getContent());
        $this->assertStringNotContainsString(hash('sha256', $token), $page->getContent());
        $later = $this->popup();
        $this->assertStringNotContainsString($token, json_encode($later->snapshot));
        $this->assertStringNotContainsString($token, $later->html());
    }

    public function test_the_altcha_is_required_and_spent_once_whatever_captcha_the_other_forms_use(): void
    {
        $this->storeSettings(['captcha' => 'recaptcha-v2']);
        $this->popup()->call('asGuest')->set('message', 'hi')->set('captcha', '')->call('start')->assertHasErrors('captcha');
        $this->popup()->call('asGuest')->set('message', 'hi')->set('captcha', 'forged')->call('start')->assertHasErrors('captcha');
        $this->assertSame(0, LiveChat::query()->count());

        $payload = $this->solved();
        $this->popup()->call('asGuest')->set('message', 'hi')->set('captcha', $payload)->call('start')->assertHasNoErrors();
        $this->newVisitor();
        $this->popup()->call('asGuest')->set('message', 'hi')->set('captcha', $payload)->call('start')->assertHasErrors('captcha');
        $this->assertSame(1, LiveChat::query()->count());
    }

    public function test_the_challenge_route_answers_only_while_visitors_may_chat(): void
    {
        $this->getJson(route('chat.altcha'))->assertOk();
        $this->storeSettings(['livechat_guest_enabled' => false]);
        $this->getJson(route('chat.altcha'))->assertNotFound();
    }

    public function test_the_department_is_chosen_on_the_server(): void
    {
        $hidden = TicketDepartment::query()->create(['name' => 'Hidden', 'hidden' => true]);
        $clients = TicketDepartment::query()->create(['name' => 'Billing', 'clients_only' => true]);
        $nobody = TicketDepartment::query()->create(['name' => 'Nobody']);
        $this->online($this->operator($hidden, $clients));
        StaffPresence::setStatus($this->operator($nobody), StaffPresenceStatus::Away);

        foreach ([$hidden->id, $clients->id, $nobody->id, 999999] as $wanted) {
            $this->start([], ['dept' => (string) $wanted])->assertHasNoErrors();
            $this->assertSame($this->sales->id, LiveChat::query()->latest('id')->first()->department_id, "dept $wanted");
            LiveChat::query()->update(['status' => LiveChat::CLOSED]);
            $this->newVisitor();
            $this->travel(4)->minutes(); // past the limit of starts
            $this->heartbeats();
        }
        // A department the browser sets, not offered: the default too.
        $this->start(['department' => $clients->id])->assertHasNoErrors();
        $this->assertSame($this->sales->id, LiveChat::query()->latest('id')->first()->department_id);
    }

    public function test_no_one_available_shows_the_guest_ticket_form_with_the_message(): void
    {
        StaffPresence::setStatus(Staff::query()->first(), StaffPresenceStatus::Away);
        LiveChats::availableDepartmentIds();

        $this->popup()->call('asGuest')->set('message', 'Leave this for later')->assertSee(__('No one is available right now. Leave us a message and we will answer by email.'))
            ->assertSeeHtml('wire:name="contact"');
        Livewire::test(Contact::class, ['prefill' => 'Leave this for later', 'embedded' => true])
            ->assertSet('message', 'Leave this for later')->assertDontSeeHtml('<h1');
        $this->start()->assertHasErrors('message');
        $this->assertSame(0, LiveChat::query()->count());
    }

    public function test_the_name_and_email_are_checked_and_never_kept_when_wrong(): void
    {
        $this->start(['email' => '"x\" onmouseover=alert(1)"@a.gr'])->assertHasErrors('message');
        $this->start(['email' => 'not-an-email'])->assertHasErrors('message');
        $this->start(['name' => "Mal\u{202E}lory"])->assertHasErrors('message');
        $this->start(['name' => str_repeat('a', 61)])->assertHasErrors('message');
        $this->assertSame(0, LiveChat::query()->count());

        $this->start(['name' => '', 'email' => ''])->assertHasNoErrors();
        $this->assertSame([null, null], [LiveChat::query()->sole()->visitor_name, LiveChat::query()->sole()->visitor_email]);
    }

    public function test_starts_are_limited_per_address_counted_first(): void
    {
        for ($i = 0; $i < VisitorChats::STARTS_PER_10_MINUTES; $i++) {
            $this->start()->assertHasNoErrors();
            LiveChat::query()->update(['status' => LiveChat::CLOSED]);
            $this->newVisitor();
        }
        $this->start()->assertHasErrors('message');
        $this->assertSame(VisitorChats::STARTS_PER_10_MINUTES, LiveChat::query()->count());
    }

    public function test_open_chats_are_limited_per_address(): void
    {
        for ($i = 0; $i < VisitorChats::OPEN_PER_IP; $i++) {
            $this->start()->assertHasNoErrors();
            $this->newVisitor();
            $this->travel(4)->minutes(); // past the limit of starts
            $this->heartbeats();
        }
        $this->start()->assertHasErrors('message');
        $this->assertSame(VisitorChats::OPEN_PER_IP, LiveChat::query()->where('status', '!=', LiveChat::CLOSED)->count());
    }

    public function test_one_open_chat_per_session(): void
    {
        $this->start()->assertHasNoErrors();
        $this->assertSame(__('Your chat is already open.'), VisitorChats::start(['message' => 'again']));
        $this->assertSame(1, LiveChat::query()->count());
    }

    public function test_messages_are_limited_per_chat(): void
    {
        $this->start()->assertHasNoErrors();
        $chat = LiveChat::query()->sole();
        for ($i = 0; $i < VisitorChats::PER_MINUTE; $i++) {
            $this->assertNull(VisitorChats::post($chat, "m$i"));
        }
        $this->assertSame(__('Too many messages, wait a moment.'), VisitorChats::post($chat, 'one more'));
        $this->assertSame(VisitorChats::PER_MINUTE + 1, $chat->messages()->count());
    }

    public function test_the_visitor_writes_and_reads_only_their_own_chat(): void
    {
        $this->start()->assertHasNoErrors();
        $mine = LiveChat::query()->sole();
        $theirs = LiveChat::query()->create(['department_id' => $this->sales->id, 'status' => LiveChat::ACTIVE, 'started_anonymously' => true]);
        $theirs->messages()->create(['author_type' => LiveChatMessage::VISITOR, 'body' => 'someone else\'s secret']);
        $theirs->messages()->create(['author_type' => LiveChatMessage::NOTE, 'body' => 'a note']);
        $mine->messages()->create(['author_type' => LiveChatMessage::NOTE, 'body' => 'internal only']);
        $mine->messages()->create(['author_type' => LiveChatMessage::SYSTEM, 'body' => 'system only']);

        $this->assertSame(__('You may not write here.'), VisitorChats::post($theirs, 'into theirs'));
        VisitorChats::end($theirs);
        $this->assertSame(LiveChat::ACTIVE, $theirs->fresh()->status);
        $this->popup()->set('body', 'mine')->call('send')->assertHasNoErrors()
            ->assertSee('mine')->assertDontSee('someone else')->assertDontSee('internal only')->assertDontSee('system only');
        $this->assertSame(0, $theirs->messages()->where('body', 'into theirs')->count());
    }

    public function test_the_end_and_a_new_chat(): void
    {
        $this->start()->assertHasNoErrors();
        $this->popup()->call('end')->assertSee(__('The chat has ended.'));
        $this->assertSame(LiveChat::CLOSED, LiveChat::query()->sole()->status);
        $this->popup()->set('body', 'after')->call('send');
        $this->assertSame(0, LiveChatMessage::query()->where('body', 'after')->count());

        $this->popup()->call('newChat')->assertSee(__('Start chat'));
        $this->assertFalse(session()->has(ChatVisitor::SESSION_KEY));
    }

    public function test_an_idle_close_says_the_chat_ended(): void
    {
        $this->storeSettings(['livechat_idle_minutes' => 30]);
        $this->start()->assertHasNoErrors();
        $this->travel(31)->minutes();
        $this->assertSame(1, LiveChats::closeIdle());

        $this->popup()->assertSee(__('The chat has ended.'));
    }

    public function test_a_signed_in_customer_gets_their_accounts_chat_and_never_a_visitors(): void
    {
        $user = User::factory()->create(['first_name' => 'Ann', 'email' => 'ann@example.com']);
        $client = $user->client();
        ClientUser::query()->where('client_id', $client->id)->where('user_id', $user->id)->update(['permissions' => ['*']]);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));

        $this->get(route('chat'))->assertOk()->assertSee('ann@example.com')->assertSee(__('Not you?'))->assertSeeLivewire('client.chat-bubble')
            ->assertDontSee(__('Continue as a visitor'));
        $this->assertSame(__('Live chat is not available.'), VisitorChats::start(['message' => 'as a visitor']));
        $this->assertSame(0, LiveChat::query()->count());
    }

    public function test_login_as_client_gets_nothing(): void
    {
        $staff = Staff::factory()->withPermissions(['*'])->create();
        $customer = User::factory()->create();
        $this->actingAs($staff, 'staff')->withSession(['impersonating' => $customer->id, CurrentClient::IMPERSONATING_KEY => $customer->client()->id,
            CurrentClient::SESSION_KEY => $customer->client()->id]);

        $this->get(route('chat'))->assertOk()->assertSee(__('The chat is not available while you are logged in as a client.'))
            ->assertDontSeeLivewire('client.chat-bubble');
    }

    public function test_sign_in_goes_to_the_login_and_back_here(): void
    {
        $this->popup()->call('login')->assertRedirect(route('login'));
        $this->assertSame(route('chat'), session('url.intended'));
    }

    public function test_what_a_visitor_wrote_is_escaped_in_the_popup_and_for_the_staff(): void
    {
        $this->start(['message' => self::XSS[0] . self::XSS[1] . self::XSS[2]], ['page' => '/' . urlencode(self::XSS[1])])->assertHasNoErrors();
        $chat = LiveChat::query()->sole();
        $chat->messages()->create(['author_type' => LiveChatMessage::VISITOR, 'body' => 'javascript:alert(1)']);

        $html = $this->get(route('chat'))->getContent();
        $operator = Staff::query()->first();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($operator, 'staff');
        $console = Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->html();
        $queue = Livewire::test(ChatQueue::class)->html();
        foreach ([$html, $console, $queue] as $screen) {
            $this->assertStringNotContainsString('<script>alert', $screen);
            $this->assertStringNotContainsString('<img src=x', $screen);
            $this->assertStringNotContainsString('" onmouseover="', $screen);
            $this->assertStringNotContainsString('href="javascript:', $screen);
        }
        $this->assertStringContainsString(e(__('Visitor')), $console);
        $this->assertStringContainsString(e(__('Visitor')), $queue);
    }

    public function test_no_token_is_ever_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message . json_encode($event->context);
        });
        $this->start()->assertHasNoErrors();
        $token = (string) session()->get(ChatVisitor::SESSION_KEY);
        $this->popup()->set('body', 'x')->call('send')->call('end');
        $this->get(route('chat'));

        $this->assertNotSame('', $token);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    public function test_nothing_while_visitors_may_not_chat(): void
    {
        $this->storeSettings(['livechat_guest_enabled' => false]);

        $this->get(route('chat'))->assertOk()->assertSee(__('Live chat is not available right now.'));
        $this->assertSame(__('Live chat is not available.'), VisitorChats::start(['message' => 'hi']));
    }
}
