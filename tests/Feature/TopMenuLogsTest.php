<?php

namespace Tests\Feature;

use App\Admin\Logs;
use App\Admin\Resources\Audits\AuditResource;
use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\PaymentGatewayEventResource;
use App\Admin\TopMenu;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
