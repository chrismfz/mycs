<?php

namespace Tests\Feature;

use App\Admin\Pages\PriceMismatches;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Price;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reports\PriceMismatch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The services and domains that renew at a price other than the catalog's (sweep 9γ, owner 2026-10-30), for staff.
 */
class PriceMismatchesTest extends TestCase
{
    use RefreshDatabase;

    private function service(float $price, string $status = Service::STATUS_ACTIVE): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 20.00]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1]);
    }

    private function domain(?string $own): Domain
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR'], ['price' => '12.00']);

        $sld = 'n' . uniqid();

        return Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => $sld, 'tld' => 'com',
            'fqdn' => $sld . '.com', 'domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active,
            'expires_at' => now()->addMonth()->toDateString(), 'currency_code' => 'EUR', 'years' => 1, 'recurring_amount' => $own]);
    }

    public function test_a_price_other_than_the_catalogs_is_listed_the_catalogs_is_not(): void
    {
        $cheap = $this->service(15.00);
        $this->service(20.00); // at the catalog's
        $this->service(5.00, Service::STATUS_TERMINATED); // ended: renews nothing
        $own = $this->domain('9.50');
        $this->domain(null); // follows its extension
        $this->domain('12.00'); // the same as its extension's

        $rows = collect(PriceMismatch::rows())->keyBy(fn (array $row) => $row['type'] . $row['id']);

        $this->assertSame(['service' . $cheap->id, 'domain' . $own->id], $rows->keys()->all());
        $this->assertSame(['15.00', '20.00', '-5.00'], [$rows['service' . $cheap->id]['price'], $rows['service' . $cheap->id]['catalog'],
            $rows['service' . $cheap->id]['difference']]);
        $this->assertSame(['9.50', '12.00', '-2.50'], [$rows['domain' . $own->id]['price'], $rows['domain' . $own->id]['catalog'],
            $rows['domain' . $own->id]['difference']]);
    }

    public function test_staff_see_the_largest_difference_first_and_filter_it(): void
    {
        $small = $this->service(19.00);
        $large = $this->service(30.00);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = $this->livewireAs(Staff::factory()->admin()->create())->test(PriceMismatches::class)->assertOk()
            ->assertSeeInOrder(["Service #{$large->id}", "Service #{$small->id}"]);
        $page->set('direction', 'below')->assertSee("Service #{$small->id}")->assertDontSee("Service #{$large->id}");
    }

    public function test_staff_without_the_reports_permission_cannot_open_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->withPermissions(['admin.services.viewAny', 'admin.domains.viewAny'])->create(), 'staff');

        $this->assertFalse(PriceMismatches::canAccess());
    }
}
