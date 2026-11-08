<?php

namespace Tests\Feature;

use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Admin\Widgets\NeedsAttention;
use App\Models\CronStat;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ops\CronDay;
use App\Services\Ops\CronSummary;
use App\Services\Ops\Health;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner 2026-11-08, after the review of the stray accounts rule: the «check this» tasks (ModuleAction::CHECKS) are not
 * failures. No Health warning, no failed (red) day in the Cron calendar or the summary email, not in «Module actions
 * left open» nor «Needs staff»; shown on their own: «N accounts to check» in the summary, a grey line in «Needs
 * attention». No mail per task.
 */
class StrayChecksNotFailuresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00')); // a Wednesday
        $this->storeSettings(['mail_disable' => false, 'system_email_address' => 'ops@example.test']);
    }

    private function service(): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => Service::STATUS_TERMINATED, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 1]);
    }

    /** Two accounts and a domain to check, opened yesterday as a read would (given up at once). */
    private function openChecks(): void
    {
        $server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'check', 'fqdn' => 'check.test']);
        $at = Carbon::parse('2026-11-10 09:10');
        foreach ([
            ['service_id' => $this->service()->id, 'action' => ModuleAction::STRAY_ENDED, 'subject' => "{$server->id}:old"],
            ['action' => ModuleAction::STRAY_UNMAPPED, 'subject' => "{$server->id}:stray"],
            ['domain_id' => $domain->id, 'action' => ModuleAction::DOMAIN_CHECK],
        ] as $row) {
            ModuleAction::query()->create($row + ['status' => ModuleAction::STATUS_OPEN, 'attempts' => 0, 'last_attempt_at' => $at,
                'given_up_at' => $at, 'last_error' => 'check-text-' . $row['action']])->forceFill(['created_at' => $at])->save();
        }
    }

    private function ran(string $day): void
    {
        CronStat::create(['key' => 'invoices_created', 'value' => 0, 'date' => $day]);
    }

    /** @return Collection<int, EmailLog> */
    private function sent()
    {
        return EmailLog::query()->where('template_key', 'system')->orderBy('id')->get();
    }

    public function test_health_does_not_warn_for_checks_and_counts_them_apart(): void
    {
        $this->openChecks();

        $check = Health::report()['checks']['module_actions'];

        $this->assertSame(Health::OK, $check['status'], 'checks are not failures');
        $this->assertSame(0, $check['data']['open']);
        $this->assertSame(0, $check['data']['given_up']);
        $this->assertSame(2, $check['data']['accounts_to_check']);
        $this->assertSame(1, $check['data']['domains_to_check']);
        $this->assertStringContainsString('2 accounts and 1 domains to check (not failures)', $check['detail']);

        // A real failure beside them still warns.
        ModuleAction::query()->create(['service_id' => $this->service()->id, 'action' => 'suspend', 'status' => ModuleAction::STATUS_OPEN, 'given_up_at' => now()]);
        $this->assertSame(Health::WARN, Health::report()['checks']['module_actions']['status']);
    }

    public function test_a_day_with_only_checks_is_not_red_nor_failed_and_failures_only_sends_nothing(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'failures']);
        $this->ran('2026-11-10');
        $this->openChecks();

        $this->assertFalse(CronDay::range(Carbon::parse('2026-11-10'), Carbon::parse('2026-11-10'))['2026-11-10']['attention'], 'no red day for checks');
        $data = CronSummary::build(Carbon::today(), Carbon::parse('2026-11-10'), Carbon::parse('2026-11-10'));
        $this->assertFalse($data['failed']);
        $this->assertCount(0, $data['days'][0]['actions'], 'not «Module actions left open»');
        $this->assertSame(['accounts' => 2, 'domains' => 1], $data['checks']);

        $this->artisan('app:cron-summary')->expectsOutputToContain('Nothing failed')->assertExitCode(0);
        $this->assertCount(0, $this->sent(), 'no summary for checks alone, and no mail per task');
    }

    public function test_the_daily_summary_shows_the_count_apart_not_as_a_failure(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily']);
        $this->ran('2026-11-10');
        $this->openChecks();

        $this->artisan('app:cron-summary')->assertExitCode(0);

        $this->assertCount(1, $this->sent());
        $mail = $this->sent()[0];
        $this->assertStringNotContainsString('something to look at', (string) $mail->subject);
        $this->assertStringContainsString(trans_choice(':count account to check|:count accounts to check', 2), (string) $mail->body);
        $this->assertStringContainsString(trans_choice(':count domain to check|:count domains to check', 1), (string) $mail->body);
        $this->assertStringNotContainsString(__('Module actions left open'), (string) $mail->body);
        $this->assertStringNotContainsString('check-text-', (string) $mail->body, 'a count, not the tasks');
    }

    public function test_needs_attention_lists_checks_on_a_grey_line_of_their_own_and_its_filter_lists_the_same(): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]), 'staff');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->openChecks();
        $failure = ModuleAction::query()->create(['service_id' => $this->service()->id, 'action' => 'suspend', 'status' => ModuleAction::STATUS_OPEN,
            'given_up_at' => now(), 'last_error' => 'refused']);

        $rows = NeedsAttention::report()['rows'];
        $titles = array_column($rows, 'title');

        $this->assertContains(trans_choice(':count server action needs staff|:count server actions need staff', 1), $titles, 'the checks are not counted as failures');
        $check = collect($rows)->firstWhere('color', 'gray');
        $this->assertNotNull($check);
        $this->assertSame(trans_choice(':count account to check|:count accounts to check', 2) . ' · ' . trans_choice(':count domain to check|:count domains to check', 1), $check['title']);
        parse_str((string) parse_url($check['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['filters' => ['checks' => ['isActive' => '1']]], $query);
        $this->assertSame('danger', $rows[0]['color'], 'the failure first, the checks after');

        $checks = ModuleAction::query()->whereIn('action', ModuleAction::CHECKS)->get();
        Livewire::test(ListModuleActions::class)->filterTable('checks')->assertCanSeeTableRecords($checks)->assertCountTableRecords(3);
        Livewire::test(ListModuleActions::class)->filterTable('needs_staff')->assertCanSeeTableRecords([$failure])->assertCountTableRecords(1);
    }

    public function test_only_checks_open_is_a_grey_line_alone_with_no_automation_warning(): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]), 'staff');
        $this->openChecks();
        Health::fresh();

        $rows = NeedsAttention::report()['rows'];
        $colors = array_column($rows, 'color');

        $this->assertContains('gray', $colors);
        $this->assertNotContains(trans_choice(':count server action needs staff|:count server actions need staff', 3), array_column($rows, 'title'));
        foreach ($rows as $row) {
            $this->assertStringNotContainsString(__('health.module_actions'), $row['detail'], 'no automation warning for checks');
        }
    }
}
