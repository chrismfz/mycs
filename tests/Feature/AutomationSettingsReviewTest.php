<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\EmailLog;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Providers\SettingsProvider;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of the Automation tab (feat/automation-settings).
 */
class AutomationSettingsReviewTest extends TestCase
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

    private function owner(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['settings.system_email_address' => 'billing@example.test']);

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    /** @return array<int, Tab> */
    private function tabs($livewire): array
    {
        $found = [];
        $walk = function ($components) use (&$walk, &$found) {
            foreach ($components as $component) {
                if ($component instanceof Tab) {
                    $found[] = $component;
                }
                foreach (method_exists($component, 'getChildSchemas') ? $component->getChildSchemas() : [] as $schema) {
                    $walk($schema->getComponents(withHidden: true));
                }
            }
        };
        $walk($livewire->instance()->form->getComponents(withHidden: true));

        return $found;
    }

    /**
     * R1: TAB_LABELS says "the key stays: it is in the query string", but Filament derives a Tab's key from its
     * label (Tab::setUp: Str::slug(label)), so ?tab=cronjob::data::tab links/bookmarks no longer open the tab.
     */
    public function test_r1_the_automation_tab_keeps_its_cronjob_query_string_key(): void
    {
        $livewire = $this->livewireAs($this->owner())->test(Settings::class);

        $keys = array_map(fn (Tab $tab) => $tab->getKey(isAbsolute: false), $this->tabs($livewire));

        $this->assertNotEmpty(array_filter($keys, fn ($key) => str_starts_with((string) $key, 'cronjob::')), 'tab keys: ' . implode(', ', $keys));
    }

    /** Control: a checkbox saved off is stored off and read back off (config and form), then on again. */
    public function test_control_enable_suspension_round_trips_through_save_and_config(): void
    {
        $owner = $this->owner();

        $this->livewireAs($owner)->test(Settings::class)
            ->set('data.cronjob_suspend_enabled', false)
            ->call('save')->assertHasNoErrors();
        SettingsProvider::flushCache();
        $this->assertFalse(config('settings.cronjob_suspend_enabled', true));

        config()->set('settings', config('settings')->put('system_email_address', 'billing@example.test'));
        $this->livewireAs($owner)->test(Settings::class)
            ->assertSet('data.cronjob_suspend_enabled', false)
            ->set('data.cronjob_suspend_enabled', true)
            ->call('save')->assertHasNoErrors();
        SettingsProvider::flushCache();
        $this->assertTrue((bool) config('settings.cronjob_suspend_enabled'));
    }

    /** Control: every other tab still renders its fields without sections; sections only on Automation. */
    public function test_control_other_tabs_have_no_sections(): void
    {
        $livewire = $this->livewireAs($this->owner())->test(Settings::class);
        foreach ($this->tabs($livewire) as $tab) {
            $sections = array_filter($tab->getChildSchema()->getComponents(withHidden: true), fn ($c) => $c instanceof Section);
            $key = (string) $tab->getKey(isAbsolute: false);
            if (str_starts_with($key, 'automation') || str_starts_with($key, 'cronjob') || str_starts_with($key, 'theme')) {
                $this->assertCount(5, $sections, $key); // Appearance: layout, colors light/dark, custom code, files
            } elseif (str_starts_with($key, 'domains')) {
                $this->assertCount(4, $sections, $key); // Domains: ordering, renewals, sync, housekeeping
            } else {
                $this->assertCount(0, $sections, (string) $tab->getKey(isAbsolute: false));
            }
        }
    }

    /**
     * R2: the field now advertises min 0 next to «Close Inactive Tickets ... 0 to disable» in the same tab (WHMCS:
     * 0 disables data retention), but 0 here deletes every email log on the next run.
     */
    public function test_r2_email_log_retention_zero_does_not_wipe_the_log(): void
    {
        config(['settings.cronjob_delete_email_logs' => 0]);
        $log = EmailLog::query()->forceCreate(['subject' => 'Invoice', 'to' => 'a@example.test', 'body' => 'x', 'status' => 'sent',
            'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

        $this->artisan('app:cron-job');

        $this->assertNotNull(EmailLog::query()->find($log->id), 'a 0-day retention deleted an hour-old email log');
    }

    /**
     * R3: «Cancel unpaid orders after» now advertises min 0; at 0 an order placed a minute before the run is cancelled
     * with its invoice (the customer may be paying it), where the sibling settings read 0 as "off".
     */
    public function test_r3_order_cancel_zero_does_not_cancel_an_order_placed_a_minute_ago(): void
    {
        config(['settings.cronjob_order_cancel' => 0]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'pending', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'created_at' => now()->subMinute(),
        ]);

        $this->artisan('app:cron-job');

        $this->assertSame('pending', $service->fresh()->status);
    }
}
