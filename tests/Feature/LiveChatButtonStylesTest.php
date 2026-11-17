<?php

namespace Tests\Feature;

use App\Enums\StaffPresenceStatus;
use App\Livewire\Client\ChatBubble;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\ChatLauncher;
use App\Support\CurrentClient;
use App\Support\StaffPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The chat button's three looks (owner 2026-11-17): in the launcher's code and on MyCS's own pages. */
class LiveChatButtonStylesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeSettings(['livechat_enabled' => true, 'livechat_guest_enabled' => true, 'livechat_sites' => ['www.example.com']]);
        $sales = TicketDepartment::query()->create(['name' => 'Sales']);
        $operator = Staff::factory()->create(['role_id' => Role::create(['name' => 'O', 'permissions' => ['admin.chats.view', 'admin.chats.reply']])->id]);
        $sales->chatStaff()->attach($operator->id);
        StaffPresence::setStatus($operator, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($operator);
    }

    private function checked(string $style, string $text = 'Chat'): ?array
    {
        return ChatLauncher::checked(['site' => 'www.example.com', 'department' => '', 'text' => $text, 'position' => 'right', 'colour' => '#2563eb', 'style' => $style]);
    }

    public function test_the_icon_is_a_plain_fixed_svg(): void
    {
        $icon = ChatLauncher::icon();

        $this->assertStringStartsWith('<svg ', $icon);
        $this->assertSame(1, preg_match_all('/<path /', $icon));
        foreach (['<script', 'href', 'on', 'url(', '{', '<foreignObject', '<use'] as $never) {
            $this->assertDoesNotMatchRegularExpression('/' . preg_quote($never === 'on' ? ' on' : $never, '/') . '/i', $icon, $never);
        }
    }

    public function test_the_three_looks_in_the_launchers_code(): void
    {
        $text = ChatLauncher::button($this->checked('text'));
        $this->assertStringNotContainsString('<svg', $text);
        $this->assertStringContainsString('>Chat</button>', $text);

        $both = ChatLauncher::button($this->checked('icon-text'));
        $this->assertStringContainsString(ChatLauncher::icon() . '<span>Chat</span>', $both);

        $round = ChatLauncher::button($this->checked('icon', '<b>"Talk"</b>'));
        $this->assertStringContainsString('aria-label="&lt;b&gt;&quot;Talk&quot;&lt;/b&gt;"', $round);
        $this->assertStringContainsString('width:52px;height:52px', $round);
        $this->assertStringNotContainsString('<b>', $round);

        $this->assertNull($this->checked('fancy'));
        $this->assertNotNull(ChatLauncher::checked(['site' => 'www.example.com', 'text' => 'Chat', 'position' => 'right', 'colour' => '#2563eb']), 'text by default');
        $this->assertStringNotContainsString('{', ChatLauncher::button($this->checked('icon')), 'nothing Astro would run');
    }

    public function test_mycs_own_buttons_follow_the_setting(): void
    {
        $this->assertStringNotContainsString('<svg', $this->visitorButton());

        $this->storeSettings(['livechat_button_style' => 'icon']);
        $html = $this->visitorButton();
        $this->assertStringContainsString(ChatLauncher::icon(), $html);
        $this->assertStringContainsString('sr-only', $html);
        $this->assertStringContainsString('h-12 w-12', $html);

        $this->storeSettings(['livechat_button_style' => 'something-else']);
        $this->assertStringNotContainsString('<svg', $this->visitorButton(), 'an unknown value is the text');
    }

    public function test_the_customers_bubble_follows_the_setting(): void
    {
        $this->storeSettings(['livechat_button_style' => 'icon']);
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        app(CurrentClient::class)->switchTo($user->client()->id);

        Livewire::test(ChatBubble::class)
            ->assertSeeHtml(ChatLauncher::icon())->assertSeeHtml('sr-only')->assertSeeHtml('h-12 w-12')
            ->call('toggle')->assertSet('open', true);
    }

    private function visitorButton(): string
    {
        $html = $this->get(route('contact'))->assertOk()->getContent();
        $start = (int) strpos($html, 'data-mycs-guest-chat=');
        $this->assertGreaterThan(0, $start);

        return substr($html, $start, (int) strpos($html, '</button>', $start) - $start);
    }
}
