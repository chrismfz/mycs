<?php

namespace Tests\Feature;

use App\Admin\Pages\ChatLauncherCode;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Support\ChatLauncher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Live chat with anonymous visitors, step 4, PR 4: the launcher's code from the admin (docs/livechat.md §4). */
class LiveChatLauncherTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true, 'livechat_sites' => ['www.example.com', 'unblock.example.com']]);
        $this->sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $this->sales->chatStaff()->attach(Staff::factory()->create()->id);
    }

    private function choices(array $changes = []): array
    {
        return $changes + ['site' => 'www.example.com', 'department' => (string) $this->sales->id, 'text' => 'Chat', 'position' => 'right', 'colour' => '#2563EB'];
    }

    public function test_only_checked_choices_make_code(): void
    {
        $this->assertNotNull(ChatLauncher::checked($this->choices()));
        $this->assertNotNull(ChatLauncher::checked($this->choices(['department' => ''])));
        $hidden = TicketDepartment::query()->create(['name' => 'Hidden', 'hidden' => true]);
        $clients = TicketDepartment::query()->create(['name' => 'Billing', 'clients_only' => true]);
        foreach ([
            ['site' => 'evil.example'], ['site' => 'www.example.com" onclick="x'], ['department' => (string) $hidden->id],
            ['department' => (string) $clients->id], ['department' => 'x'], ['text' => ''], ['text' => str_repeat('a', 31)],
            ['text' => "Chat\u{202E}"], ['text' => "a\nb"], ['position' => 'top'], ['colour' => 'red'],
            ['colour' => '#fff;background:url(x)'], ['colour' => '#12345'],
        ] as $bad) {
            $this->assertNull(ChatLauncher::checked($this->choices($bad)), json_encode($bad));
        }
    }

    public function test_the_code_escapes_what_staff_typed_and_holds_no_secret(): void
    {
        $checked = ChatLauncher::checked($this->choices(['text' => '<script>x</script>"\'&']));
        foreach (ChatLauncher::FORMS as $form) {
            $code = ChatLauncher::snippet($checked, $form);
            $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;&quot;&#039;&amp;', $code, $form);
            $this->assertStringNotContainsString('<script>x', $code, $form);
            $this->assertStringContainsString('data-mycs-chat="' . e(route('chat', ['src' => 'www.example.com', 'dept' => $this->sales->id])) . '"', $code);
            $this->assertStringContainsString('background:#2563eb', $code);
            $this->assertStringNotContainsString((string) config('app.key'), $code);
            $this->assertStringNotContainsString('token', strtolower($code));
        }
        $this->assertStringContainsString('<script is:inline>', ChatLauncher::snippet($checked, 'astro'));
        $this->assertStringContainsString('integrity="' . ChatLauncher::integrity() . '" crossorigin="anonymous"', ChatLauncher::snippet($checked, 'script'));
    }

    public function test_the_script_reads_only_the_path_and_opens_the_popup(): void
    {
        $script = ChatLauncher::script();
        $code = substr($script, (int) strpos($script, '*/') + 2); // its comment may name what it never does

        $this->assertStringContainsString("window.open(url.toString(), 'mycs-chat', 'noopener,popup,width=420,height=640')", $script);
        $this->assertStringContainsString("url.searchParams.set('page', location.pathname)", $script);
        $this->assertStringContainsString("window.gtag('event', 'chat_opened')", $script);
        foreach (['fetch', 'XMLHttpRequest', 'cookie', 'localStorage', 'sessionStorage', 'location.href', 'location.search', 'referrer', 'innerHTML', 'eval', 'postMessage'] as $never) {
            $this->assertStringNotContainsString($never, $code, $never);
        }
    }

    public function test_the_versioned_script_is_served_as_its_integrity_says_without_a_session(): void
    {
        $response = $this->get(ChatLauncher::scriptUrl());

        $response->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringStartsWith('application/javascript', (string) $response->headers->get('Content-Type'));
        $this->assertSame(ChatLauncher::integrity(), 'sha384-' . base64_encode(hash('sha384', $response->getContent(), true)));
        $this->assertSame([], $response->headers->getCookies(), 'no cookie, no session for every site visitor');
        $this->get('/chat-launcher-v' . (ChatLauncher::VERSION + 1) . '.js')->assertNotFound();
    }

    public function test_the_admin_page_makes_the_code_for_who_may_see_settings(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'N', 'permissions' => ['admin.chats.view']])->id]), 'staff');
        $this->assertFalse(ChatLauncherCode::canAccess());

        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'S', 'permissions' => ['admin.settings.view']])->id]), 'staff');
        Livewire::test(ChatLauncherCode::class)->set('department', (string) $this->sales->id)->set('text', '<b>Talk</b>')
            ->assertSee('data-mycs-chat=')->assertSeeHtml('&lt;b&gt;Talk&lt;/b&gt;')->assertDontSeeHtml('<b>Talk</b>')
            ->set('form', 'script')->assertSee(ChatLauncher::integrity())
            ->set('site', 'evil.example')->assertSee(__('Choose a listed site, a text of up to :max characters on one line, and a colour.', ['max' => ChatLauncher::TEXT_MAX]));
    }

    /** §3 Α: in every chat view the one raw output is ChatText::render (the launcher's preview: ChatLauncher's own escaped button). */
    public function test_the_chat_views_print_nothing_raw_but_the_chat_text(): void
    {
        $views = ['livewire/chat-popup', 'livewire/client/chat-bubble', 'livewire/admin/customer-chat', 'livewire/admin/chat-queue',
            'livewire/admin/partials/visitor-label', 'admin/resources/client-resource/pages/view-chat', 'admin/pages/chat-launcher-code'];
        foreach (array_filter($views, fn (string $view) => is_file(resource_path("views/$view.blade.php"))) as $view) {
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path("views/$view.blade.php")));
            preg_match_all('/\{!!(.*?)!!\}/s', $source, $raw);
            foreach ($raw[1] as $expression) {
                $this->assertContains(trim($expression), $view === 'admin/pages/chat-launcher-code' ? ['$preview'] : [
                    '\App\Support\ChatText::render($message->body)', '\App\Support\ChatText::render($line->body)',
                ], "$view: {!! $expression !!}");
            }
        }
    }
}
