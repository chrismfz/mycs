<?php

namespace Tests\Feature;

use App\Admin\Logs;
use App\Admin\Resources\Audits\AuditResource;
use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\PaymentGatewayEventResource;
use App\Admin\TopMenu;
use App\Models\EmailLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * «Utilities → Logs» (owner 2026-10-28): one line for all the logs instead of one each; it opens the first log the staff
 * member may see, and every log lists them all on its left (App\Admin\Logs). No log is left without a way to it.
 */
class TopMenuLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /** @return array<string, array{label: string, url: string, active: bool}> */
    private function utilities(): array
    {
        return collect(collect(TopMenu::groups())->firstWhere('label', 'Utilities')['items'] ?? [])->keyBy('label')->all();
    }

    public function test_utilities_has_one_line_for_the_logs_that_opens_the_activity_log(): void
    {
        $this->actingAs($this->staff(['*']), 'staff');

        $utilities = $this->utilities();

        $this->assertSame(AuditResource::getUrl('index'), $utilities['Logs']['url']);
        foreach (['Email Message Log', 'Activity Log', 'Login History', 'Registrar Log', 'Automation Status', 'Failed Jobs', 'MCP Calls'] as $log) {
            $this->assertArrayNotHasKey($log, $utilities, "{$log} is behind «Logs» now");
        }
        $this->assertArrayHasKey('Pending Module Actions', $utilities);
    }

    public function test_logs_opens_the_first_log_the_staff_member_may_see(): void
    {
        $this->actingAs($this->staff(['admin.email_logs.viewAny']), 'staff');

        $this->assertSame(EmailLogResource::getUrl('index'), $this->utilities()['Logs']['url']);
    }

    public function test_no_log_no_line(): void
    {
        $this->actingAs($this->staff(['admin.clients.viewAny']), 'staff');

        $this->assertArrayNotHasKey('Logs', $this->utilities());
    }

    public function test_every_log_is_reached_from_the_list_and_none_falls_to_addons(): void
    {
        $this->actingAs($this->staff(['*']), 'staff');

        foreach (Logs::classes() as $class) {
            $this->assertNotContains($class, TopMenu::unlisted());
        }
        $this->assertNotContains('Addons', array_column(TopMenu::groups(), 'label'));
    }

    public function test_logs_is_lit_on_a_log_and_only_one_group_on_a_log_with_its_own_line(): void
    {
        $staff = $this->staff(['*']);

        $this->actingAs($staff, 'staff')->get(EmailLogResource::getUrl('index'))->assertOk();
        $this->assertTrue($this->utilities()['Logs']['active']);
        $this->assertSame(['Utilities'], array_column(array_filter(TopMenu::groups(), fn ($g) => $g['active']), 'label'));

        $this->get(PaymentGatewayEventResource::getUrl('index'))->assertOk();
        $this->assertSame(['Billing'], array_column(array_filter(TopMenu::groups(), fn ($g) => $g['active']), 'label'));
    }

    /** Stored as a Setting: each request below loads the settings again from the database (TopMenuCoverageTest). */
    private function debug(bool $on): void
    {
        Setting::query()->updateOrCreate(['key' => 'debug', 'settingable_type' => null], ['value' => $on, 'type' => 'boolean']);
        config(['settings.debug' => $on]);
        Cache::forget('settings.rows');
    }

    public function test_with_debugging_off_logs_never_leads_to_the_debug_logs(): void
    {
        $this->debug(false);
        $this->actingAs($this->staff(['admin.debug_logs.view']), 'staff');

        $this->assertArrayNotHasKey('Logs', $this->utilities());

        $this->actingAs($this->staff(['*']), 'staff');
        $labels = array_map(fn ($item) => $item->getLabel(), Logs::navigation());
        $this->assertNotContains(__('Error Logs'), $labels);
        $this->assertNotContains(__('HTTP Logs'), $labels);
        $this->assertContains(__('Failed Jobs'), $labels);
    }

    public function test_with_debugging_on_logs_leads_to_them(): void
    {
        $this->debug(true);
        $this->actingAs($this->staff(['admin.debug_logs.view']), 'staff');

        $this->assertStringContainsString('error-logs', $this->utilities()['Logs']['url']);
    }

    /** The menu opens only the first log now: each of the others opens too, from the list on its left. */
    public function test_every_log_in_the_list_opens(): void
    {
        $this->debug(true);
        foreach (['tax_enabled' => [true, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        $this->actingAs($this->staff(['*']), 'staff');

        $urls = array_map(fn ($item) => (string) $item->getUrl(), Logs::navigation());
        $this->assertCount(count(Logs::classes()), $urls);
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_logs_is_lit_on_a_logs_record_too(): void
    {
        $log = EmailLog::query()->create(['subject' => 'x', 'to' => 'e@example.test', 'body' => 'y']);
        $this->actingAs($this->staff(['*']), 'staff')->get(EmailLogResource::getUrl('view', ['record' => $log]))->assertOk();

        $this->assertTrue($this->utilities()['Logs']['active']);
    }
}
