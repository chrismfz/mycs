<?php

namespace Tests\Feature;

use App\Admin\AdminSidebar;
use App\Admin\Resources\ServiceCancellationResource\Pages\ListServiceCancellations;
use App\Admin\Widgets\Overview;
use App\Models\Currency;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Pending cancellations» in the sidebar's «To handle» (owner 2026-10-25): the dashboard card's count, opening the
 * requests on the same filter.
 */
class SidebarPendingCancellationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function request(string $status): ServiceCancellation
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => '2026-12-31', 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1,
        ]);

        return ServiceCancellation::withoutEvents(fn () => ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']));
    }

    /** @return array{label: string, url: string, badge: ?string, color: string}|null */
    private function queue(): ?array
    {
        return collect(AdminSidebar::queues())->firstWhere('label', 'Pending cancellations');
    }

    public function test_counts_the_pending_requests_after_pending_services(): void
    {
        $this->staff();
        $this->request(Service::STATUS_ACTIVE);
        $this->request(Service::STATUS_SUSPENDED);
        $this->request(Service::STATUS_CANCELLED);

        $this->assertSame(['badge' => '2', 'color' => 'warning'], array_intersect_key($this->queue(), ['badge' => 0, 'color' => 0]));
        $labels = array_column(AdminSidebar::queues(), 'label');
        $this->assertSame(array_search('Pending services', $labels) + 1, array_search('Pending cancellations', $labels));
    }

    public function test_shown_without_a_count_when_none_waits(): void
    {
        $this->staff();
        $this->request(Service::STATUS_TERMINATED);

        $this->assertNull($this->queue()['badge']);
    }

    public function test_opens_the_requests_on_the_pending_ones(): void
    {
        $this->staff();
        $pending = $this->request(Service::STATUS_ACTIVE);
        $ended = $this->request(Service::STATUS_CANCELLED);
        parse_str((string) parse_url($this->queue()['url'], PHP_URL_QUERY), $query);

        Livewire::withQueryParams($query)->test(ListServiceCancellations::class)
            ->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$ended]);
    }

    public function test_only_to_staff_who_may_see_the_requests(): void
    {
        $this->request(Service::STATUS_ACTIVE);

        $this->staff(['admin.tickets.viewAny']);
        $this->assertNull($this->queue());

        $this->staff(['admin.service_cancellations.viewAny']);
        $this->assertSame('1', $this->queue()['badge']);
    }

    /** The sidebar and the dashboard's card read one cached count: they never disagree. */
    public function test_one_cached_count_with_the_dashboard_card(): void
    {
        config(['app.admin_badge_cache_seconds' => 30]);
        $this->staff();
        $this->request(Service::STATUS_ACTIVE);
        $this->assertSame('1', $this->queue()['badge']);

        $this->request(Service::STATUS_ACTIVE); // within the 30 seconds: both still say 1
        $stats = collect((fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance()))
            ->keyBy(fn (Stat $stat) => (string) $stat->getLabel());

        $this->assertSame('1', (string) $stats[__('Pending Cancellations')]->getValue());
    }

    public function test_the_label_in_greek(): void
    {
        $staff = $this->staff();
        $this->request(Service::STATUS_ACTIVE);
        $this->storeSettings(['admin_language' => 'el']); // the admin's language (AdminLocale)

        $this->withSession($this->loginUser($staff))->get('/sysadmin')->assertOk()->assertSee('Ακυρώσεις σε αναμονή');
    }
}
