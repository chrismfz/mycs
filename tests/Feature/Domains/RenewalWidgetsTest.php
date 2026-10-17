<?php

namespace Tests\Feature\Domains;

use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\RecordsToCheck;
use App\Admin\Widgets\Renewals\ServiceRenewals;
use App\Admin\Widgets\Renewals\ServicesToCheck;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The staff's renewal widgets (owner 2026-10-17, as myip's dashboard hack): domains and services due soon (30 days by
 * default), and the records to check (no expiry, a transfer stuck, a lapse not synced, a service without a due date).
 */
class RenewalWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(string $sld, array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'com', 'fqdn' => "{$sld}.com",
            'status' => DomainStatus::Active, 'expires_at' => '2026-11-01', 'currency_code' => 'USD', 'years' => 1, 'auto_renew' => true]);
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-11-01']);
    }

    private function staff(): Staff
    {
        return Staff::factory()->withPermissions(['admin.widgets.renewals', 'admin.domains.viewAny', 'admin.services.viewAny'])->create();
    }

    public function test_domains_due_within_the_window_with_how_their_renewal_stands(): void
    {
        $soon = $this->domain('soon');
        $later = $this->domain('later', ['expires_at' => '2027-01-15']);
        $ended = $this->domain('ended', ['status' => DomainStatus::TransferredAway]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $soon->id, 'period_start' => '2026-11-01', 'period_end' => '2027-11-01']);

        $this->livewireAs($this->staff())->test(DomainRenewals::class)
            ->assertCanSeeTableRecords([$soon])->assertCanNotSeeTableRecords([$later, $ended])
            ->assertTableColumnStateSet('renewal', __('Unpaid'), $soon)
            ->filterTable('within', '90')->assertCanSeeTableRecords([$soon, $later]);
    }

    public function test_services_due_within_the_window(): void
    {
        $soon = $this->service();
        $later = $this->service(['expires_at' => '2027-02-01']);

        $this->livewireAs($this->staff())->test(ServiceRenewals::class)
            ->assertCanSeeTableRecords([$soon])->assertCanNotSeeTableRecords([$later]);
    }

    public function test_records_to_check_say_why(): void
    {
        $fine = $this->domain('fine');
        $noExpiry = $this->domain('noexpiry', ['expires_at' => null]);
        $stuck = $this->domain('stuck', ['status' => DomainStatus::PendingTransfer, 'expires_at' => null]);
        DB::table('domains')->where('id', $stuck->id)->update(['updated_at' => now()->subDays(10)]);
        $lag = $this->domain('lag', ['expires_at' => '2026-10-01']);
        $noDue = $this->service(['expires_at' => null]);
        $billed = $this->service();

        $this->livewireAs($this->staff())->test(RecordsToCheck::class)
            ->assertCanSeeTableRecords([$noExpiry, $stuck, $lag])->assertCanNotSeeTableRecords([$fine])
            ->assertTableColumnStateSet('why', __('No expiry date'), $noExpiry);
        $this->livewireAs($this->staff())->test(ServicesToCheck::class)
            ->assertCanSeeTableRecords([$noDue])->assertCanNotSeeTableRecords([$billed]);
    }

    public function test_only_staff_with_the_widget_permission_see_them(): void
    {
        $this->actingAs(Staff::factory()->withPermissions(['admin.domains.viewAny'])->create(), 'staff');
        $this->assertFalse(DomainRenewals::canView());

        $this->actingAs($this->staff(), 'staff');
        $this->assertTrue(DomainRenewals::canView() && RecordsToCheck::canView() && ServiceRenewals::canView() && ServicesToCheck::canView());
    }
}
