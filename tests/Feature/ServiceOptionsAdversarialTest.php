<?php

namespace Tests\Feature;

use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Helpers\ExtensionHelper;
use App\Mail\SystemMail;
use App\Models\ConfigOption;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\OptionTasks;
use App\Services\Service\RetryModuleAction;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Adversarial review of #296 (service options: extra disk by the cPanel module, IPs as staff tasks). Written without
 * the author's context. Each test says FAILING (a finding) or GUARD (passes, kept as a fence).
 */
class ServiceOptionsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Server $server;

    private Service $service;

    private object $product;

    /** @var array<string, ConfigOption> */
    private array $disk = [];

    /** @var array<string, ConfigOption> */
    private array $ip = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->product = $this->hostingProduct();
        $this->disk = $this->option('Extra disk space', 'extra_disk_gb', ['None' => '0', '+10GB' => '10', '+20GB' => '20', 'Half' => '2.5'], staff: false);
        $this->ip = $this->option('Dedicated IPs', 'dedicated_ips', ['None' => '0', '1 IP' => '1', '2 IPs' => '2'], staff: true);
        $this->disk['_']->products()->attach($this->product->product->id);
        $this->ip['_']->products()->attach($this->product->product->id);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $this->product->plan->id,
            'product_id' => $this->product->product->id, 'server_id' => $this->server->id, 'status' => 'pending', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'example.test']);
    }

    private function hostingProduct(): object
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'Semi20']);

        return $product;
    }

    /** @return array<string, ConfigOption> */
    private function option(string $name, string $env, array $values, bool $staff): array
    {
        $option = ConfigOption::query()->create(['name' => $name, 'env_variable' => $env, 'type' => 'select', 'upgradable' => true, 'staff_task' => $staff]);
        $out = ['_' => $option];
        foreach ($values as $label => $value) {
            $out[$label] = ConfigOption::query()->create(['name' => $label, 'env_variable' => $value, 'type' => 'select', 'parent_id' => $option->id]);
        }

        return $out;
    }

    private function choose(array $option, string $value): void
    {
        $this->service->configs()->updateOrCreate(['config_option_id' => $option['_']->id], ['config_value_id' => $option[$value]->id]);
    }

    private function whm(?string $quota = '20480', string $editquota = 'ok'): void
    {
        Http::fake([
            self::WHM . '/createacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
            self::WHM . '/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'exampleuser', 'domain' => 'example.test', 'plan' => 'Semi20']]]]),
            self::WHM . '/getpkginfo*' => Http::response(['metadata' => ['result' => 1], 'data' => ['pkg' => ['QUOTA' => $quota]]]),
            self::WHM . '/editquota*' => $editquota === 'ok'
                ? Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])
                : Http::response(['metadata' => ['result' => 0, 'reason' => $editquota]]),
            self::WHM . '/changepackage*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
        ]);
    }

    /** @return list<int|string> */
    private function quotas(): array
    {
        return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), '/editquota'))->map(fn ($pair) => (int) $pair[0]['quota'])->values()->all();
    }

    private function createCalls(): int
    {
        return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), '/createacct'))->count();
    }

    /** @return list<string> */
    private function tasks(): array
    {
        return ModuleAction::query()->where('action', OptionTasks::ACTION)->orderBy('id')->pluck('last_error')->map(fn ($e) => (string) $e)->all();
    }

    private function upgrade(int $productId, int $planId, array $configs = []): ServiceUpgrade
    {
        $upgrade = ServiceUpgrade::create(['service_id' => $this->service->id, 'plan_id' => $planId, 'product_id' => $productId, 'status' => 'pending']);
        foreach ($configs as [$option, $value]) {
            $upgrade->configs()->create(['config_option_id' => $option['_']->id, 'config_value_id' => $option[$value]->id]);
        }

        return $upgrade;
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    // ---- Staff tasks -------------------------------------------------------------------------------------------------

    /**
     * A1 FAILING (high). An upgrade to another product that does not have the IP option deletes the service's IP config
     * BEFORE OptionTasks reads «before» (ServiceUpgradeService: the product-change delete runs above the snapshot), so
     * the IP goes from the recurring amount with no «Take back» task: the customer stops paying and keeps the IP.
     */
    public function test_a1_a_product_change_that_drops_the_ip_option_tells_staff_to_take_it_back(): void
    {
        $this->choose($this->ip, '1 IP');
        $this->service->update(['status' => 'active']);
        ModuleAction::query()->update(['status' => ModuleAction::STATUS_RESOLVED]); // the Give was done
        $other = $this->hostingProduct(); // no IP option

        $this->assertTrue((new ServiceUpgradeService)->handle($this->upgrade($other->product->id, $other->plan->id)));

        $this->assertSame(0, $this->service->fresh()->configs()->count(), 'the IP option went with the product');
        $this->assertTrue(collect($this->tasks())->contains(fn ($t) => str_contains($t, 'Take back: Dedicated IPs: 1 IP')),
            'staff must be told to take back the IP the service no longer pays for; tasks: ' . json_encode($this->tasks()));
    }

    /**
     * A2 FAILING (medium). A change of value (2 IPs → 1 IP) reads «Give: Dedicated IPs: 1 IP»: changed() sets «Give»
     * for any label that differs in its first loop, so the «Change: … → …» branch is unreachable. Staff told to «Give»
     * on a downgrade hand out a third IP instead of taking one back.
     */
    public function test_a2_a_downgrade_of_an_ip_count_is_a_change_not_a_give(): void
    {
        $this->choose($this->ip, '2 IPs');
        $this->service->update(['status' => 'active']);
        ModuleAction::query()->update(['status' => ModuleAction::STATUS_RESOLVED]);

        $this->assertTrue((new ServiceUpgradeService)->handle($this->upgrade($this->product->product->id, $this->product->plan->id, [[$this->ip, '1 IP']])));

        $last = (string) collect($this->tasks())->last();
        $this->assertStringNotContainsString('Give:', $last, 'a downgrade must never read as «Give»');
        $this->assertStringContainsString('Change: Dedicated IPs: 2 IPs → Dedicated IPs: 1 IP', $last);
    }

    /**
     * A7 FAILING (low). A service staff bring back from terminated (or cancelled) to active had its IP taken back (a
     * «Take back» task on its end); coming back it gets no «Give»: only pending → active gives.
     */
    public function test_a7_a_service_brought_back_from_terminated_gets_its_ip_again(): void
    {
        $this->choose($this->ip, '1 IP');
        $this->service->update(['status' => 'active']);
        $this->service->update(['status' => 'terminated']);
        ModuleAction::query()->update(['status' => ModuleAction::STATUS_RESOLVED]); // given, then taken back

        $this->service->update(['status' => 'active']); // staff reactivate it

        $this->assertStringContainsString('Give: Dedicated IPs: 1 IP', (string) collect($this->tasks())->last(),
            'tasks: ' . json_encode($this->tasks()));
    }

    /**
     * A8 FAILING (low). Status flapping (staff set active → pending → active) opens a second «Give» while the first is
     * still open: no check for an open task of the same option and text.
     */
    public function test_a8_status_flapping_does_not_open_a_second_identical_task(): void
    {
        $this->choose($this->ip, '1 IP');
        $this->service->update(['status' => 'active']);
        $this->service->update(['status' => 'pending']);
        $this->service->update(['status' => 'active']);

        $this->assertSame(1, ModuleAction::query()->open()->where('action', OptionTasks::ACTION)->count(), 'tasks: ' . json_encode($this->tasks()));
    }

    /** GUARD (passes). Staff's Retry path itself, called directly (as the MCP proposal would), never runs a module call. */
    public function test_guard_retry_of_a_staff_task_never_calls_the_server(): void
    {
        Http::fake();
        $this->choose($this->ip, '1 IP');
        $this->service->update(['status' => 'active']);
        $task = ModuleAction::query()->where('action', OptionTasks::ACTION)->sole();

        try {
            RetryModuleAction::run($task);
            $this->fail('a staff task has nothing to run');
        } catch (InvalidArgumentException) {
        }
        Http::assertNothingSent();
        $this->assertSame(ModuleAction::STATUS_OPEN, $task->fresh()->status);
    }

    /** GUARD (passes). A successful terminate supersedes module failures, never the staff's «Take back». */
    public function test_guard_a_terminate_does_not_close_the_take_back(): void
    {
        $this->whm();
        Http::fake([self::WHM . '/removeacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
        $this->choose($this->ip, '1 IP');
        $this->service->update(['status' => 'active', 'username' => 'exampleuser']);
        $this->service->update(['status' => 'terminated']);
        try {
            ExtensionHelper::terminateServer($this->service->fresh());
        } catch (\Throwable) {
            // the module's own outcome is not what is tested here
        }

        $this->assertSame(1, ModuleAction::query()->open()->where('action', OptionTasks::ACTION)
            ->where('last_error', 'like', '%Take back%')->count());
    }

    /** GUARD (passes). The same upgrade handled twice (payment and cron) opens its task once. */
    public function test_guard_an_upgrade_handled_twice_opens_one_task(): void
    {
        $this->choose($this->ip, 'None');
        $this->service->update(['status' => 'active']);
        $upgrade = $this->upgrade($this->product->product->id, $this->product->plan->id, [[$this->ip, '1 IP']]);

        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));
        $this->assertFalse((new ServiceUpgradeService)->handle($upgrade->fresh()));

        $this->assertCount(1, $this->tasks());
    }

    // ---- Extra disk --------------------------------------------------------------------------------------------------

    /**
     * A3 FAILING (medium). A quota refused right after createacct is recorded with ModuleAction::recordFailure('upgrade')
     * outside any job: given_up_at stays null, so the row's Retry is hidden for ever (it shows only once given up) and
     * staff get no mail (only giveUp() mails). The commit says «its Retry sets it»; the customer paid for disk that is
     * never set unless staff happen to open the list and find a row they cannot retry.
     */
    public function test_a3_a_quota_refused_after_create_can_be_retried_by_staff(): void
    {
        $this->whm(editquota: 'Quotas are disabled');
        $this->choose($this->disk, '+10GB');
        $this->assertTrue((bool) ExtensionHelper::createServer($this->service->fresh()));
        $row = ModuleAction::query()->open()->where('action', 'upgrade')->sole();

        $this->livewireAs($this->staff())->test(ListModuleActions::class)->assertTableActionVisible('retry', $row);
    }

    /** A3b FAILING (medium). Same cause as A3: the row opens without giveUp(), so no staff mail is ever sent for it. */
    public function test_a3b_staff_are_told_of_a_quota_refused_after_create(): void
    {
        $this->whm(editquota: 'Quotas are disabled');
        $this->choose($this->disk, '+10GB');
        ExtensionHelper::createServer($this->service->fresh());

        Mail::assertQueued(SystemMail::class, fn (SystemMail $mail) => str_contains((string) $mail->envelope()->subject, 'upgrade')
            || str_contains((string) $mail->envelope()->subject, 'disk'));
    }

    /** GUARD (passes). The quota's failure never makes a second account, and the service starts. */
    public function test_guard_a_quota_refused_after_create_makes_one_account_and_starts_the_service(): void
    {
        $this->whm(editquota: 'Quotas are disabled');
        $this->choose($this->disk, '+10GB');

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame(1, $this->createCalls());
        $this->assertSame('active', $this->service->fresh()->status);
        $this->assertSame(0, ModuleAction::query()->open()->where('action', 'create')->count());
    }

    /**
     * A4 FAILING (medium). A create whose first answer was lost (createacct timed out after WHM made the account) is
     * retried, finds the account under its pending name and adopts it (return $this->created(...)): that early return
     * skips applyExtraDisk, so the account has its package's quota only. The customer paid for +10GB and never gets it,
     * with no row for staff.
     */
    public function test_a4_an_account_adopted_after_a_lost_answer_gets_its_extra_disk(): void
    {
        $this->whm();
        $this->choose($this->disk, '+10GB');
        $this->service->properties()->create(['key' => 'cpanel_pending_username', 'name' => 'pending', 'value' => 'exampleuser']);

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame(0, $this->createCalls(), 'adopted, not made again');
        $this->assertSame([20480 + 10240], $this->quotas(), 'the extra disk it paid for');
    }

    /**
     * A5 FAILING (medium). An upgrade to another product on the same cPanel package that has no extra-disk option: the
     * option's config goes (and its price from the recurring amount), but applyExtraDisk returns early when the property
     * is absent and changepackage is skipped (same package), so the account keeps +10GB it no longer pays for.
     */
    public function test_a5_a_product_change_without_the_disk_option_takes_the_extra_disk_off(): void
    {
        $this->whm();
        $this->choose($this->disk, '+10GB');
        $this->service->update(['status' => 'active', 'username' => 'exampleuser']);
        $other = $this->hostingProduct(); // the same package Semi20, no extra-disk option

        $this->assertTrue((new ServiceUpgradeService)->handle($this->upgrade($other->product->id, $other->plan->id)));
        ExtensionHelper::upgradeServer($this->service->fresh()); // what the queued UpgradeJob runs

        $this->assertSame(0, $this->service->fresh()->configs()->count(), 'the disk option went with the product');
        $this->assertSame([20480], $this->quotas(), 'back to its package\'s quota');
    }

    /**
     * A6 FAILING (low). A value that is not a whole number of GB is read by stripping non-digits: «2.5» becomes 25 GB
     * (10× what it says); «-10» would become +10. Expected: refused (or read as the number it is), never inflated.
     */
    public function test_a6_a_decimal_value_is_not_inflated_tenfold(): void
    {
        $this->whm();
        $this->choose($this->disk, 'Half');

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertNotContains(20480 + 25 * 1024, $this->quotas(), '«2.5» GB must not become 25 GB');
    }

    /** GUARD (passes). A suspended service's upgrade still sets its quota (the upgrade service allows suspended ones). */
    public function test_guard_a_suspended_service_upgrade_sets_the_quota(): void
    {
        $this->whm();
        $this->choose($this->disk, 'None');
        $this->service->update(['status' => 'suspended', 'username' => 'exampleuser']);

        $this->assertTrue((new ServiceUpgradeService)->handle($this->upgrade($this->product->product->id, $this->product->plan->id, [[$this->disk, '+20GB']])));
        ExtensionHelper::upgradeServer($this->service->fresh());

        $this->assertSame([20480 + 20480], $this->quotas());
    }

    /** GUARD (passes). A quota refused on an upgrade fails the upgrade (retried by its job), never reported as done. */
    public function test_guard_a_quota_refused_on_upgrade_fails_the_upgrade(): void
    {
        $this->whm(editquota: 'Quotas are disabled');
        $this->choose($this->disk, '+10GB');
        $this->service->update(['status' => 'active', 'username' => 'exampleuser']);

        try {
            ExtensionHelper::upgradeServer($this->service->fresh());
            $this->fail('a refused quota is a failed upgrade');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Quotas are disabled', $e->getMessage());
        }
        $this->assertSame(1, ModuleAction::query()->open()->where('action', 'upgrade')->count());
    }

    // ---- Money -------------------------------------------------------------------------------------------------------

    /** GUARD (passes). An options-only upgrade's recurring amount is unchanged by the task hooks (priced options). */
    public function test_guard_an_options_only_upgrade_keeps_its_recurring_price(): void
    {
        $this->choose($this->ip, 'None');
        $this->service->update(['status' => 'active']);

        $this->assertTrue((new ServiceUpgradeService)->handle($this->upgrade($this->product->product->id, $this->product->plan->id, [[$this->ip, '1 IP']])));

        $this->assertEqualsWithDelta(10.00, (float) $this->service->fresh()->price, 0.001, 'unpriced option: the price it had');
    }
}
