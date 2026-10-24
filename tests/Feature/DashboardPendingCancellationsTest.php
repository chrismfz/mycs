<?php

namespace Tests\Feature;

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
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Pending Cancellations» on the dashboard (owner 2026-10-24): the requests whose service still runs, how many end
 * within a week, opening the list of requests on those.
 */
class DashboardPendingCancellationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 10:00:00');
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $permissions): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]), 'staff');
    }

    /** @return array<string, Stat> */
    private function stats(): array
    {
        $stats = (fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance());

        return collect($stats)->keyBy(fn (Stat $stat) => (string) $stat->getLabel())->all();
    }

    /** An end-of-period request for a service in $status that expires on $expires. */
    private function request(string $status, ?string $expires): ServiceCancellation
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1,
        ]);

        return ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
    }

    public function test_counts_the_requests_whose_service_still_runs_and_those_ending_this_week(): void
    {
        $this->staff(['*']);
        $soon = $this->request(Service::STATUS_ACTIVE, '2026-10-30');
        $today = $this->request(Service::STATUS_SUSPENDED, '2026-10-24');
        $later = $this->request(Service::STATUS_ACTIVE, '2026-12-31');
        $noDate = $this->request(Service::STATUS_PENDING, null);
        $ended = $this->request(Service::STATUS_CANCELLED, '2026-10-01');
        $terminated = $this->request(Service::STATUS_TERMINATED, '2026-10-01');
        $outsideWeek = $this->request(Service::STATUS_ACTIVE, '2026-11-01'); // the 8th day

        $stat = $this->stats()[__('Pending Cancellations')];

        $this->assertSame('5', (string) $stat->getValue());
        $this->assertSame('2 end within 7 days', (string) $stat->getDescription());
        $this->assertSame('warning', $stat->getColor());
        $this->assertStringContainsString('filters', (string) $stat->getUrl());

        Livewire::test(ListServiceCancellations::class)->filterTable('pending')
            ->assertCanSeeTableRecords([$soon, $today, $later, $noDate, $outsideWeek])
            ->assertCanNotSeeTableRecords([$ended, $terminated]);
    }

    public function test_the_card_opens_the_list_on_the_pending_ones(): void
    {
        $this->staff(['*']);
        $pending = $this->request(Service::STATUS_ACTIVE, '2026-12-31');
        $ended = $this->request(Service::STATUS_CANCELLED, '2026-10-01');

        $url = (string) $this->stats()[__('Pending Cancellations')]->getUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(['pending' => ['isActive' => '1']], $query['filters']);
        Livewire::withQueryParams($query)->test(ListServiceCancellations::class)
            ->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$ended]);
    }

    public function test_one_ending_this_week_singular_and_none_this_week(): void
    {
        $this->staff(['*']);
        $request = $this->request(Service::STATUS_ACTIVE, '2026-12-31');

        $stat = $this->stats()[__('Pending Cancellations')];
        $this->assertSame(['1', 'None ends within 7 days', 'info'], [(string) $stat->getValue(), (string) $stat->getDescription(), $stat->getColor()]);

        $request->service->update(['expires_at' => '2026-10-27']);
        $this->assertSame('1 ends within 7 days', (string) $this->stats()[__('Pending Cancellations')]->getDescription());
    }

    public function test_gray_at_zero(): void
    {
        $this->staff(['*']);
        $this->request(Service::STATUS_CANCELLED, '2026-10-01');

        $stat = $this->stats()[__('Pending Cancellations')];

        $this->assertSame(['0', 'Nothing waiting', 'gray'], [(string) $stat->getValue(), (string) $stat->getDescription(), $stat->getColor()]);
    }

    public function test_in_greek(): void
    {
        $this->staff(['*']);
        $this->request(Service::STATUS_ACTIVE, '2026-10-30');
        $this->request(Service::STATUS_ACTIVE, '2026-10-31');
        app()->setLocale('el');

        $stat = $this->stats()['Εκκρεμείς ακυρώσεις'];

        $this->assertSame('2 λήγουν μέσα σε 7 ημέρες', (string) $stat->getDescription());
    }

    public function test_only_to_staff_who_may_see_the_cancellation_requests(): void
    {
        $this->request(Service::STATUS_ACTIVE, '2026-10-30');

        $this->staff(['admin.widgets.overview']);
        $this->assertArrayNotHasKey(__('Pending Cancellations'), $this->stats());

        $this->staff(['admin.widgets.overview', 'admin.service_cancellations.viewAny']);
        $this->assertSame('1', (string) $this->stats()[__('Pending Cancellations')]->getValue());
    }

    /** Seven cards, one row on a desktop: as many columns as cards at the widest, never more. */
    public function test_seven_cards_in_one_row(): void
    {
        $this->staff(['*']);
        $widget = Livewire::test(Overview::class)->instance();
        $stats = (fn () => $this->getStats())->call($widget);
        $columns = (fn () => $this->getColumns())->call($widget);

        $this->assertCount(7, $stats);
        $this->assertSame(7, max($columns));
    }
}
