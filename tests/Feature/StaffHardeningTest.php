<?php

namespace Tests\Feature;

use App\Admin\Resources\ExtensionResource\Pages\EditExtension;
use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Models\Extension;
use App\Models\License;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Security audit group 2 for staff: SA-7 (actions' own permissions), SA-10, SA-11 (masked bearer values), SA-12. */
class StaffHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_uninstalling_an_extension_needs_the_delete_permission(): void
    {
        $extension = Extension::query()->create(['name' => 'DiscordNotifications', 'extension' => 'DiscordNotifications', 'type' => 'other', 'enabled' => false]);

        $this->livewireAs(Staff::factory()->withPermissions(['admin.extensions.viewAny', 'admin.extensions.update'])->create())
            ->test(EditExtension::class, ['record' => $extension->getRouteKey()])->assertActionHidden('uninstall');
        $this->livewireAs(Staff::factory()->withPermissions(['admin.extensions.viewAny', 'admin.extensions.update', 'admin.extensions.delete'])->create())
            ->test(EditExtension::class, ['record' => $extension->getRouteKey()])->assertActionVisible('uninstall');
    }

    public function test_duplicating_a_product_needs_the_create_permission(): void
    {
        $product = $this->createProduct()->product;

        $this->livewireAs(Staff::factory()->withPermissions(['admin.products.viewAny', 'admin.products.update'])->create())
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])->assertActionHidden('duplicate');
        $this->assertSame(1, Product::query()->count());
    }

    public function test_a_license_key_is_masked_for_staff_who_only_view_services(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'currency_code' => 'USD']);
        License::query()->create(['service_id' => $service->id, 'license_key' => 'ZCAgent-0123456789abcdef', 'status' => License::STATUS_ACTIVE]);

        $this->livewireAs(Staff::factory()->withPermissions(['admin.services.viewAny'])->create())->test(ListLicenses::class)
            ->assertSee('ZCAgent-…cdef')->assertDontSee('0123456789abcdef');
        $this->livewireAs(Staff::factory()->withPermissions(['admin.services.viewAny', 'admin.services.update'])->create())->test(ListLicenses::class)
            ->assertSee('ZCAgent-0123456789abcdef');
    }

    public function test_the_income_export_keeps_the_panels_session_lock(): void
    {
        config(['settings.session_validation' => 'ip_admin']);
        $this->actingAs(Staff::factory()->admin()->create(), 'staff');
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        $url = URL::temporarySignedRoute('admin.reports.income.export', now()->addMinutes(10),
            ['year' => (int) now()->format('Y'), 'currency' => 'EUR', 'format' => 'csv']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->get($url)->assertOk();
        // The same session from another address (a stolen cookie): refused, as the panel does.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get($url)->assertRedirect();
        $this->assertGuest('staff');
    }

    public function test_the_panel_refuses_what_has_no_policy(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->isAuthorizationStrict());
    }

    /** A license's key is its service's domain too: masked there, and found by the whole key only (review of #368). */
    public function test_a_key_that_is_the_service_domain_is_masked_in_the_service_lists_and_search(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $product = $this->createProduct();
        $key = 'ZCAgent-0123456789abcdef';
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'currency_code' => 'USD', 'domain' => $key]);
        License::query()->create(['service_id' => $service->id, 'license_key' => $key, 'status' => License::STATUS_ACTIVE]);
        $masked = Staff::factory()->withPermissions(['admin.services.viewAny'])->create();

        $this->livewireAs($masked)->test(ListService::class)
            ->assertCanSeeTableRecords([$service])->assertDontSee($key)
            ->searchTable('ZCAgent-01234')->assertCanNotSeeTableRecords([$service])
            ->searchTable($key)->assertCanSeeTableRecords([$service]); // the search box holds what they typed, the key whole

        $this->actingAs($masked, 'staff');
        $this->assertCount(0, ServiceResource::getGlobalSearchResults('ZCAgent-01234'));
        $titles = ServiceResource::getGlobalSearchResults($key)->map(fn ($r) => (string) $r->title)->implode(' ');
        $this->assertStringContainsString('#' . $service->id, $titles);
        $this->assertStringNotContainsString($key, $titles);

        $full = Staff::factory()->withPermissions(['admin.services.viewAny', 'admin.services.update'])->create();
        $this->livewireAs($full)->test(ListService::class)
            ->searchTable('ZCAgent-01234')->assertCanSeeTableRecords([$service])->assertSee($key);
    }
}
