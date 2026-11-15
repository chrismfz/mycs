<?php

namespace Tests\Feature;

use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Mcp\Servers\MycsServer;
use App\Mcp\Tools\LicensesTool;
use App\Models\License;
use App\Models\LicenseInstall;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Licenses console's «Live» (the default) and «Dormant» tabs, and the MCP `licenses` that counts with the same
 * filters and says which versions run (docs/licensing.md, docs/mcp.md; owner 2026-11-15: hide the ended ones, never delete).
 */
class LicensesConsoleMcpTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function license(string $key, string $status, ?\DateTimeInterface $lastAccess): License
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1]);

        return License::query()->create(['service_id' => $service->id, 'license_key' => $key, 'status' => $status, 'reissues' => 0, 'last_access' => $lastAccess]);
    }

    private function install(License $license, string $product, string $version, string $id): void
    {
        LicenseInstall::query()->create(['license_id' => $license->id, 'ip' => '198.51.100.7', 'product' => $product, 'install_id' => $id,
            'version' => $version, 'checks' => 1, 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subHour()]);
    }

    public function test_live_is_the_default_and_the_long_ended_ones_wait_in_dormant(): void
    {
        $active = $this->license('ZCWeb-0000000000000001', License::STATUS_ACTIVE, null);
        $calling = $this->license('ZCWeb-0000000000000002', License::STATUS_EXPIRED, now()->subDays(10));
        $dormant = $this->license('ZCWeb-0000000000000003', License::STATUS_EXPIRED, now()->subDays(200));
        $never = $this->license('ZCWeb-0000000000000004', License::STATUS_SUSPENDED, null);

        $this->livewireAs($this->staff)->test(ListLicenses::class)
            ->assertCanSeeTableRecords([$active, $calling])->assertCanNotSeeTableRecords([$dormant, $never]);
        $this->livewireAs($this->staff)->withQueryParams(['tab' => 'dormant'])->test(ListLicenses::class)
            ->assertCanSeeTableRecords([$dormant, $never])->assertCanNotSeeTableRecords([$active, $calling]);
        $this->livewireAs($this->staff)->withQueryParams(['tab' => 'all'])->test(ListLicenses::class)
            ->assertCanSeeTableRecords([$active, $calling, $dormant, $never]);
    }

    public function test_the_mcp_says_the_versions_the_tabs_and_who_runs_a_version(): void
    {
        $old = $this->license('ZCWeb-00000000000000aa', License::STATUS_ACTIVE, now()->subHour());
        $new = $this->license('ZCWeb-00000000000000bb', License::STATUS_ACTIVE, now()->subHour());
        $this->license('ZCWeb-00000000000000cc', License::STATUS_EXPIRED, now()->subDays(200));
        $old->update(['last_endpoint' => 'v0']);
        $new->update(['last_endpoint' => 'v1']);
        $this->install($old, 'controller', '2.5.1', 'install-old');
        $this->install($new, 'controller', '2.8.0', 'install-new');
        $this->install($new, 'controller', '2.10.0', 'install-newer');
        $this->install($new, 'agent', '2026.11.15.1012', 'install-agent');

        MycsServer::actingAs($this->staff, 'staff')->tool(LicensesTool::class, [])->assertOk()
            ->assertSee('"version": "2.10.0"')->assertSee('"version": "2.5.1"')
            ->assertSee('"live": 2')->assertSee('"dormant": 1')->assertSee('"all": 3')
            ->assertSee('"v0 (zlic.php)": 1')->assertSee('"v1 (/licensing/v1/check)": 1')
            ->assertSee('"version": "2026.11.15.1012"');

        // Newest first by version, not by text: 2.10.0 above 2.8.0.
        $this->assertSame(['2.10.0', '2.8.0', '2.5.1'], array_column(LicensesTool::versions(now()->subDay())['controller'], 'version'));

        MycsServer::actingAs($this->staff, 'staff')->tool(LicensesTool::class, ['product' => 'controller', 'version' => '2.5.1'])->assertOk()
            ->assertSee('ZCWeb-…00aa')->assertDontSee('ZCWeb-00000000000000aa') // keys masked, as everywhere in the MCP
            ->assertSee('controller 2.5.1')->assertDontSee('ZCWeb-…00bb');
    }

    public function test_the_mcp_needs_the_services_permission(): void
    {
        $other = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['admin.mcp.use']])->id]);

        MycsServer::actingAs($other, 'staff')->tool(LicensesTool::class, [])->assertHasErrors();
    }
}
