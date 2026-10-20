<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Automation tab (WHMCS's Automation Settings): each company sets its own days and switches; the defaults are
 * the pilot's. Enable Suspension / Enable Termination off stop those cron steps; Close Inactive Tickets 0 disables.
 */
class AutomationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    public function test_the_defaults_are_the_pilots(): void
    {
        $defaults = collect(\App\Classes\Settings::settings()['cronjob'])->pluck('default', 'name');

        $this->assertSame(8, $defaults['cronjob_order_suspend']);
        $this->assertSame(60, $defaults['cronjob_order_terminate']);
        $this->assertSame(7, $defaults['cronjob_invoice']);
        $this->assertTrue($defaults['cronjob_suspend_enabled']);
        $this->assertTrue($defaults['cronjob_terminate_enabled']);
    }

    public function test_by_default_suspend_days_are_eight(): void
    {
        $due = $this->service(['expires_at' => '2026-10-11']);
        $notYet = $this->service(['expires_at' => '2026-10-12']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $due->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $notYet->fresh()->status);
    }

    public function test_enable_suspension_off_suspends_nothing(): void
    {
        config(['settings.cronjob_suspend_enabled' => false]);
        $service = $this->service(['expires_at' => '2026-09-01']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    public function test_enable_termination_off_terminates_nothing(): void
    {
        config(['settings.cronjob_terminate_enabled' => false]);
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-01-01', 'dunning_suspended_at' => '2026-01-10']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
    }

    public function test_close_inactive_tickets_zero_disables(): void
    {
        config(['settings.cronjob_close_ticket' => 0]);
        $ticket = Ticket::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => 'answered']);
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'user_id' => null, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Hi', 'created_at' => now()->subDays(30)]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame('answered', $ticket->fresh()->status);
    }

    public function test_the_automation_tab_shows_whmcs_sections_and_saves(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        config(['settings.system_email_address' => 'billing@example.test']); // required, empty in the test seed

        $this->livewireAs($owner)->test(Settings::class)
            ->assertSee('Automation')
            ->assertSee('Automatic Module Functions')
            ->assertSee('Billing Settings')
            ->assertSee('Suspend Days')
            ->set('data.cronjob_order_suspend', 15)
            ->set('data.cronjob_terminate_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('15', Setting::query()->where('key', 'cronjob_order_suspend')->whereNull('settingable_type')->value('value'));
        $this->assertSame('0', (string) Setting::query()->where('key', 'cronjob_terminate_enabled')->whereNull('settingable_type')->value('value'));
    }
}
