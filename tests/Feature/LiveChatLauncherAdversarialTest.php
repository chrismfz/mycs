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

/**
 * Adversarial review of #890 (the chat launcher's code generator, docs/livechat.md §4).
 *
 * - ADV-890-1: the Astro form puts the staff text in an Astro template, where `{…}` is a JavaScript expression run at
 *   build/SSR time on the marketing site. e() does not touch braces, so a text like `{import.meta.env.DB_PASS}` (≤ 30)
 *   leaks a build secret into the public page, and any `{` alone breaks the site's build. The same braces are template
 *   syntax for Vue/Angular/Alpine-style sites that take the HTML form.
 * - ADV-890-2: the colour check `/^#[0-9a-f]{6}$/` lets a trailing newline through (`$` matches before a final "\n"),
 *   so "#2563eb\n" is accepted and carried into the style attribute.
 * - ADV-890-3: «one line» lets U+2028/U+2029 (line/paragraph separator, \p{Zl}/\p{Zp}) through.
 *
 * test_control_* are attacks that are blocked.
 */
class LiveChatLauncherAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true, 'livechat_sites' => ['www.example.com']]);
        $this->sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $this->sales->chatStaff()->attach(Staff::factory()->create()->id);
    }

    private function choices(array $changes = []): array
    {
        return $changes + ['site' => 'www.example.com', 'department' => '', 'text' => 'Chat', 'position' => 'right', 'colour' => '#2563eb'];
    }

    public function test_adv_890_1_braces_in_the_text_do_not_become_astro_expressions(): void
    {
        $text = '{import.meta.env.DB_PASS}';
        $this->assertLessThanOrEqual(ChatLauncher::TEXT_MAX, mb_strlen($text));
        $checked = ChatLauncher::checked($this->choices(['text' => $text]));
        if ($checked === null) {
            $this->assertTrue(true); // refused: fine

            return;
        }
        $astro = ChatLauncher::snippet($checked, 'astro');
        $template = substr($astro, (int) strpos($astro, "---\n", 4) + 4);
        $template = substr($template, 0, (int) strpos($template, '<script'));
        $this->assertStringNotContainsString('{', $template, 'an Astro template runs {…} as JavaScript at build time');
    }

    public function test_adv_890_2_a_colour_with_a_trailing_newline_is_refused(): void
    {
        $this->assertNull(ChatLauncher::checked($this->choices(['colour' => "#2563eb\n"])));
    }

    public function test_adv_890_3_a_line_separator_is_not_one_line(): void
    {
        $this->assertNull(ChatLauncher::checked($this->choices(['text' => "Chat\u{2028}now"])));
        $this->assertNull(ChatLauncher::checked($this->choices(['text' => "Chat\u{2029}now"])));
    }

    public function test_control_a_compromised_site_label_is_encoded_in_every_form(): void
    {
        $evil = 'x"><img src=x onerror=alert(1)>{process.env}';
        $this->storeSettings(['livechat_sites' => [$evil]]);
        $checked = ChatLauncher::checked($this->choices(['site' => $evil]));
        $this->assertNotNull($checked);
        foreach (ChatLauncher::FORMS as $form) {
            $code = ChatLauncher::snippet($checked, $form);
            $this->assertStringNotContainsString('<img', $code, $form);
            $this->assertStringNotContainsString('{process', $code, $form);
            $this->assertSame(1, substr_count($code, '<button'), $form);
        }
    }

    public function test_control_a_department_name_is_escaped_in_the_admin(): void
    {
        $this->sales->update(['name' => '<img src=x onerror=alert(1)>']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'S', 'permissions' => ['admin.settings.view']])->id]), 'staff');
        Livewire::test(ChatLauncherCode::class)->assertDontSeeHtml('<img src=x')
            ->set('form', '<script>x</script>')->assertDontSeeHtml('<script>x')
            ->set('colour', '#fff;background:url(//evil)')->assertDontSeeHtml('url(//evil)')
            ->set('department', '999999')->assertSee(__('Choose a listed site, a text of up to :max characters on one line, and a colour.', ['max' => ChatLauncher::TEXT_MAX]));
    }

    public function test_control_staff_without_the_permission_cannot_mount(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'N', 'permissions' => ['admin.chats.view']])->id]), 'staff');
        Livewire::test(ChatLauncherCode::class)->assertForbidden();
    }

    public function test_control_the_script_route_sets_no_cookie_and_serves_only_its_version(): void
    {
        $response = $this->withCookie(config('session.cookie'), 'abc')->get(ChatLauncher::scriptUrl());
        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringNotContainsString('private', (string) $response->headers->get('Cache-Control'));
        foreach (['/chat-launcher-v0.js', '/chat-launcher-v2.js', '/chat-launcher-v1.jsx', '/chat-launcher-v1a.js',
            '/chat-launcher-v-1.js', '/chat-launcher-v1.js%00', '/chat-launcher-v..%2f1.js'] as $path) {
            $this->assertContains($this->get($path)->status(), [301, 302, 404], $path);
        }
    }
}
