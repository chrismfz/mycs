<?php

namespace Tests\Feature;

use App\Admin\Widgets\StaffAndSessions;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserSession;
use App\Support\StaffPresence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** «Staff and sessions» (B2, owner 2026-11-04): Staff online and Active Users in one card, each by its permission. */
class DashboardStaffAndSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const BOTH = ['admin.staff_online.view', 'admin.widgets.active_users'];

    private function member(array $permissions = self::BOTH, array $attributes = []): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id] + $attributes);
    }

    private function actAs(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');
    }

    private function customerSession(array $user, string $ip, int $minutesAgo): void
    {
        UserSession::query()->create(['user_id' => User::factory()->create($user)->id, 'ip_address' => $ip, 'user_agent' => 'x',
            'last_activity' => now()->subMinutes($minutesAgo)]);
    }

    public function test_the_staff_with_status_and_the_customers_sessions_of_the_last_hour_with_their_ip(): void
    {
        $viewer = $this->member(attributes: ['first_name' => 'Viewer']);
        StaffPresence::heartbeat($viewer);
        $this->member(attributes: ['first_name' => 'Away']);
        $this->actAs($viewer);
        $this->customerSession(['first_name' => 'Recent', 'email' => 'recent@example.test'], '192.0.2.10', 4);
        $this->customerSession(['first_name' => 'Older', 'email' => 'older@example.test'], '192.0.2.11', 30);
        $this->customerSession(['first_name' => 'Stale', 'email' => 'stale@example.test'], '192.0.2.12', StaffAndSessions::SESSION_MINUTES + 5);

        Livewire::test(StaffAndSessions::class)
            ->assertSee('1 online · 2 customer sessions')
            ->assertSeeInOrder(['Viewer', __('Available'), __('Connected now'), 'Away', __('Offline'), __('Never'),
                'Recent', 'recent@example.test', '4 minutes ago', '192.0.2.10', 'Older', '192.0.2.11'])
            ->assertDontSee('Stale')->assertDontSee('192.0.2.12');
    }

    /** docs/livechat.md §7β: who is in, never from where. The staff's own sessions never show an address. */
    public function test_no_staff_email_or_ip_is_shown(): void
    {
        $viewer = $this->member(attributes: ['email' => 'viewer@staff.example.test']);
        StaffPresence::heartbeat($viewer);
        $this->actAs($viewer);

        $html = Livewire::test(StaffAndSessions::class)->html();

        $this->assertStringNotContainsString('viewer@staff.example.test', $html);
        $this->assertStringNotContainsString('127.0.0.1', $html);
        $this->assertStringNotContainsString('gravatar', strtolower($html));
    }

    public function test_each_part_by_its_own_permission(): void
    {
        $this->customerSession(['first_name' => 'Customer'], '192.0.2.20', 1);
        $other = $this->member(attributes: ['first_name' => 'Colleague']);
        StaffPresence::heartbeat($other);

        $this->actAs($this->member(['admin.staff_online.view']));
        Livewire::test(StaffAndSessions::class)->assertSee('Colleague')->assertSee('1 online')
            ->assertDontSee('Customer')->assertDontSee('192.0.2.20')->assertDontSee('customer session');

        $this->actAs($this->member(['admin.widgets.active_users']));
        Livewire::test(StaffAndSessions::class)->assertSee('Customer')->assertSee('192.0.2.20')
            ->assertDontSee('Colleague')->assertDontSee('online');

        $this->actAs($this->member(['admin.tickets.viewAny']));
        $this->assertFalse(StaffAndSessions::canView());
    }

    public function test_the_list_shows_five_sessions_and_the_heading_counts_all(): void
    {
        $this->actAs($this->member());
        foreach (range(1, 7) as $i) {
            $this->customerSession(['first_name' => "Client{$i}"], "192.0.2.{$i}", $i);
        }

        $component = Livewire::test(StaffAndSessions::class)->assertSee('7 customer sessions')
            ->assertSee('Client1')->assertSee('Client5');
        $component->assertDontSee('Client6')->assertDontSee('Client7');
    }

    public function test_without_customers_it_says_so(): void
    {
        $this->actAs($this->member());

        Livewire::test(StaffAndSessions::class)->assertSee('0 customer sessions')->assertSee(__('No customer is signed in.'));
    }

    public function test_an_ip_is_copied_from_a_data_attribute_and_names_are_escaped(): void
    {
        $this->actAs($this->member());
        $this->customerSession(['first_name' => '<b>Bold</b>'], "2001:db8::1'", 1);

        $html = Livewire::test(StaffAndSessions::class)->html();

        $this->assertStringContainsString('&lt;b&gt;Bold', $html);
        $this->assertStringContainsString('data-ip="2001:db8::1&#039;"', $html);
        $this->assertStringContainsString('writeText($el.dataset.ip)', $html);
    }

    public function test_it_polls_and_is_greek_for_greek_staff(): void
    {
        $this->actAs($this->member());
        app()->setLocale('el');

        Livewire::test(StaffAndSessions::class)->assertSeeHtml('wire:poll.30s')
            ->assertSee('Staff και συνδέσεις')->assertSee('0 συνδέσεις πελατών');
    }
}
