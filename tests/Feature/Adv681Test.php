<?php

namespace Tests\Feature;

use App\Admin\Widgets\CronStat\CronCalendar;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ops\CronDay;
use App\Services\Service\DunningForecast;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #681 (the cron's dunning rules moved to DunningRules, a 14-day forecast of what it suspends and
 * terminates, the cron calendar and its dashboard card). Each test fails on the branch as pushed; the real cron
 * (`app:cron-job`, run day by day) is the oracle for the forecast, as in DunningForecastTest. Fake data only.
 */
class Adv681Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_terminate_min_suspended_days' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00')); // a Wednesday
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(int $expiredDaysAgo, array $extra = [], bool $addon = false): Service
    {
        $product = $this->createProduct();
        if ($addon) {
            $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        }

        $service = Service::factory()->create([
            'client_id' => isset($extra['parent_service_id']) ? Service::find($extra['parent_service_id'])->client_id : User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->startOfDay()->subDays($expiredDaysAgo)->addHours(12), 'currency_code' => 'USD', 'price' => 10.00,
            'quantity' => 1,
        ] + $extra);
        // Past due and running: its renewal was billed before its due date, as the cron does (nothing is suspended for an
        // invoice issued fewer than Suspend Days ago; one the cron bills for the first time tonight waits those days).
        if ($expiredDaysAgo > 0 && $service->status === 'active') {
            $this->billedAhead($service);
        }

        return $service;
    }

    /** @return array<string, string> «suspend 12» => the day foretold ('now' as today) */
    private function predicted(array $forecast): array
    {
        $predicted = [];
        foreach (['suspend', 'terminate'] as $what) {
            foreach ($forecast['now'][$what] as $id) {
                $predicted["{$what} {$id}"] = Carbon::today()->toDateString();
            }
        }
        foreach ($forecast['days'] as $day => $lists) {
            foreach (['suspend', 'terminate'] as $what) {
                foreach ($lists[$what] as $id) {
                    $predicted["{$what} {$id}"] = $day;
                }
            }
        }

        return $predicted;
    }

    /**
     * The real cron, once a day for the forecast's days: the first day each service is seen suspended and terminated.
     *
     * @param  list<Service>  $services
     * @return array<string, string>
     */
    private function observe(array $services, int $days = DunningForecast::DAYS): array
    {
        $seen = [];
        for ($d = 0; $d <= $days; $d++) {
            $this->artisan('app:cron-job')->assertExitCode(0);
            foreach ($services as $service) {
                $status = $service->fresh()->status;
                if ($status === Service::STATUS_SUSPENDED || $status === Service::STATUS_TERMINATED) {
                    $seen["suspend {$service->id}"] ??= Carbon::today()->toDateString();
                }
                if ($status === Service::STATUS_TERMINATED) {
                    $seen["terminate {$service->id}"] ??= Carbon::today()->toDateString();
                }
            }
            Carbon::setTestNow(now()->addDay());
        }

        return $seen;
    }

    private function staff(array $permissions): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /**
     * ADV-681-1: an addon is suspended and terminated with its parent (AddonCascade), on its parent's days. The forecast
     * asks DunningRules of each service on its own, so an addon not due itself is never foretold: staff calling the
     * customer about the parent are not told the addon goes with it.
     */
    public function test_adv_681_1_an_addon_suspended_and_terminated_with_its_parent_is_foretold(): void
    {
        $parent = $this->service(1); // suspended 2026-11-13, terminated 2026-11-25
        $addon = $this->service(-20, ['parent_service_id' => $parent->id], addon: true); // not due itself

        $predicted = $this->predicted(DunningForecast::compute(Carbon::today()));
        $seen = $this->observe([$parent, $addon]);

        $this->assertSame('2026-11-13', $seen["suspend {$addon->id}"] ?? null, 'the cron: suspended with its parent');
        $this->assertSame($seen["suspend {$addon->id}"], $predicted["suspend {$addon->id}"] ?? 'not foretold', 'suspended with its parent');
        $this->assertSame($seen["terminate {$addon->id}"] ?? 'never', $predicted["terminate {$addon->id}"] ?? 'not foretold', 'terminated with its parent');
    }

    /**
     * ADV-681-2: an addon overdue on its own whose parent is suspended first is suspended with it (no dunning_suspended_at),
     * so the cron never suspends nor terminates it on its own days: it ends with its parent. The forecast lists it for a
     * suspension of its own on a later day and a termination on its own day, both wrong.
     */
    public function test_adv_681_2_an_addon_already_under_its_parents_suspension_is_foretold_on_its_parents_days(): void
    {
        $parent = $this->service(5); // suspended now, terminated 2026-11-21
        $addon = $this->service(1, ['parent_service_id' => $parent->id], addon: true); // its own days: 11-13 and 11-25

        $predicted = $this->predicted(DunningForecast::compute(Carbon::today()));
        $seen = $this->observe([$parent, $addon]);

        $this->assertSame('2026-11-21', $seen["terminate {$addon->id}"] ?? null, 'the cron: terminated with its parent');
        $this->assertSame($seen["terminate {$addon->id}"], $predicted["terminate {$addon->id}"] ?? 'not foretold', 'terminated with its parent, not on its own day');
        $this->assertSame($seen["suspend {$addon->id}"], $predicted["suspend {$addon->id}"] ?? 'not foretold', 'suspended now with its parent');
    }

    /**
     * ADV-681-3: «suspend only with an overdue invoice». A service whose renewal the cron has not billed yet (Invoice
     * Generation days ahead) has no invoice today, so the forecast never lists it; the cron bills it inside the 14 days and
     * suspends it on a day of the window all the same.
     */
    public function test_adv_681_3_requires_invoice_a_renewal_billed_inside_the_window_is_foretold(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true, 'settings.cronjob_invoice' => 7]);
        $service = $this->service(-10); // due 11-21, billed 11-14, suspended 11-24

        $predicted = $this->predicted(DunningForecast::compute(Carbon::today()));
        $seen = $this->observe([$service]);

        $this->assertArrayHasKey("suspend {$service->id}", $seen, 'the cron suspends it inside the 14 days');
        $this->assertSame($seen["suspend {$service->id}"], $predicted["suspend {$service->id}"] ?? 'not foretold');
    }

    /**
     * ADV-681-4: today outside the dunning window (a Saturday, Monday to Friday). What qualifies today is put under «Due
     * now: the cron suspends these at its next run», and the dashboard says «Due now · 1 to suspend»; the cron does it on
     * Monday, whose cell does not list it.
     */
    public function test_adv_681_4_what_qualifies_on_a_closed_day_is_foretold_on_the_next_open_day(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5]]);
        Carbon::setTestNow(Carbon::parse('2026-11-14 09:00')); // a Saturday
        $service = $this->service(3); // qualifies today, Saturday

        $forecast = DunningForecast::compute(Carbon::today());
        $seen = $this->observe([$service], 2);

        $this->assertSame('2026-11-16', $seen["suspend {$service->id}"] ?? null, 'the cron: Monday');
        $this->assertSame([$service->id], $forecast['days']['2026-11-16']['suspend'] ?? [], 'Monday lists it');
        $this->assertNotContains($service->id, $forecast['now']['suspend'], 'not «due now» on a day the cron does not suspend');
    }

    /**
     * ADV-681-5: the forecast is kept 600 s under a key of the day only. The cron's hourly run suspends a service; the
     * calendar and the dashboard still say it is «Due now» for up to ten minutes (and the same after a payment, a hold, a
     * settings change).
     */
    public function test_adv_681_5_the_cached_forecast_does_not_list_what_the_cron_just_suspended(): void
    {
        $service = $this->service(5);
        $this->assertSame([$service->id], DunningForecast::upcoming()['now']['suspend']);

        // A minute later (its renewal was billed long ago, so the run writes no invoice: the suspension is the change).
        Carbon::setTestNow(now()->addMinute());
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);

        $this->assertNotContains($service->id, DunningForecast::upcoming()['now']['suspend'], 'suspended already, still «due now»');
    }

    /**
     * ADV-681-6: the calendar is behind admin.cron_stats.view only (a WHMCS «Automation Status» role imports as just that,
     * RolePermissions 138), yet it lists clients' names with links to their accounts, services and invoices, and invoice
     * totals and payments. NeedsAttention shows each part only to staff who may view that resource.
     */
    public function test_adv_681_6_staff_with_only_the_cron_permission_do_not_see_clients_and_their_links(): void
    {
        $service = $this->service(5);
        $name = $service->client->name;
        $this->assertNotSame('', (string) $name);
        $staff = $this->staff(['admin.cron_stats.view']);

        $this->livewireAs($staff)->test(CronCalendar::class)
            ->assertSee('#' . $service->id)
            ->assertDontSee((string) $name)
            ->assertDontSee('/clients/' . $service->client_id)
            ->assertDontSee('/services/' . $service->id);
    }

    /**
     * ADV-681-7: a day's «Suspended» list stops at 50 audits (LIMIT before unique), and its header counts the list, with no
     * «and N more» as the other parts have: the cut-over's first day, 55 suspended, reads «Suspended · 50».
     */
    public function test_adv_681_7_more_than_fifty_suspended_in_a_day_are_counted(): void
    {
        config(['audit.console' => true]);
        $services = collect(range(1, CronDay::LIMIT + 5))->map(fn () => $this->service(5));
        foreach ($services as $service) {
            $service->forceFill(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();
        }

        $this->livewireAs($this->staff(['*']))->test(CronCalendar::class)->call('pick', Carbon::today()->toDateString())
            ->assertSee('Suspended · ' . (CronDay::LIMIT + 5));
    }

    /**
     * ADV-681-8: a day past the forecast's 14 reads «If nothing is paid … 0 to suspend · None» although the cron will
     * suspend a service that day: the page cannot tell «nothing» from «not looked at».
     */
    public function test_adv_681_8_a_day_past_the_forecast_does_not_read_as_nothing_to_suspend(): void
    {
        $service = $this->service(-18); // qualifies 2026-12-02, 21 days ahead
        $this->assertNotContains($service->id, DunningForecast::compute(Carbon::today())['now']['suspend']);

        $this->livewireAs($this->staff(['*']))->test(CronCalendar::class)->call('pick', '2026-12-02')
            ->assertDontSee('0 to suspend');
    }
}
