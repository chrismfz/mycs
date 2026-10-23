<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Staff;
use App\Services\Ops\Health;
use App\Support\StaffPresence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Require 2FA for staff» (owner 2026-10-23: off for a while). On, by default, a member without 2FA sets it up first;
 * off, they use the admin as they are. Approving an MCP connector needs 2FA either way, and Automation Status warns
 * while it is off.
 */
class StaffTwoFactorOptionalTest extends TestCase
{
    use RefreshDatabase;

    private function required(bool $on): void
    {
        Setting::query()->updateOrCreate(['key' => 'staff_2fa_required', 'settingable_type' => null, 'settingable_id' => null], ['value' => $on ? '1' : '0', 'type' => 'boolean']);
        config(['settings.staff_2fa_required' => $on]);
    }

    private function withoutTwoFactor(): Staff
    {
        return Staff::factory()->admin()->create(['app_authentication_secret' => null]);
    }

    public function test_by_default_a_member_without_2fa_sets_it_up_first(): void
    {
        $this->actingAs($this->withoutTwoFactor(), 'staff')->get(Filament::getPanel('admin')->getUrl())
            ->assertRedirect('/sysadmin/multi-factor-authentication/set-up');
    }

    public function test_off_a_member_without_2fa_uses_the_admin(): void
    {
        $this->required(false);
        $staff = $this->withoutTwoFactor();

        $this->actingAs($staff, 'staff')->get(Filament::getPanel('admin')->getUrl())->assertOk();
        $this->assertTrue(StaffPresence::mayBeOnline($staff));
    }

    public function test_off_the_mcp_consent_still_needs_2fa(): void
    {
        $this->required(false);
        Setting::query()->updateOrCreate(['key' => 'mcp_oauth_redirect_hosts', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'example.test', 'type' => 'string']);
        config(['settings.mcp_oauth_redirect_hosts' => 'example.test']);
        $clientId = $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.example.test/cb']])->json('client_id');

        $response = $this->actingAs($this->withoutTwoFactor(), 'staff')->post('/mcp/oauth/authorize', ['response_type' => 'code', 'client_id' => $clientId,
            'redirect_uri' => 'https://claude.example.test/cb', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
            'state' => 's', 'resource' => url('/mcp'), 'decision' => 'approve']);

        $this->assertStringNotContainsString('code=', (string) $response->headers->get('Location'));
    }

    public function test_off_automation_status_warns(): void
    {
        $this->required(false);

        $check = Health::report()['checks']['runtime'];

        $this->assertSame(Health::WARN, $check['status']);
        $this->assertStringContainsString('Staff 2FA is optional', $check['detail']);
    }
}
