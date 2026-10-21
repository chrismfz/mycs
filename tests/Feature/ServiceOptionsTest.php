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
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WHMCS's product addons as the service's own options (owner 2026-10-21): extra disk space in steps of 10GB, set by the
 * cPanel module (quota = its package's + the extra); a dedicated IP or an extra IPv4 done by staff, a task for them
 * when a service takes one or gives it back.
 */
class ServiceOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Service $service;

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
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'Semi20']);
        $this->disk = $this->option('Extra disk space', 'extra_disk_gb', ['None' => '0', '+10GB' => '10', '+20GB' => '20'], staff: false);
        $this->ip = $this->option('Dedicated IP', 'dedicated_ip', ['No' => 'no', 'Yes' => 'yes'], staff: true);
        $this->disk['_']->products()->attach($product->product->id);
        $this->ip['_']->products()->attach($product->product->id);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'pending', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'example.test']);
    }

    /** @return array<string, ConfigOption> its values by name, the option itself as «_» */
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

    private function whm(?string $quota = '20480'): void
    {
        Http::fake([
            self::WHM . '/createacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
            self::WHM . '/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'exampleuser', 'domain' => 'example.test', 'plan' => 'Semi20']]]]),
            self::WHM . '/getpkginfo*' => Http::response(['metadata' => ['result' => 1], 'data' => ['pkg' => ['QUOTA' => $quota]]]),
            self::WHM . '/editquota*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
            self::WHM . '/changepackage*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
        ]);
    }

    /** @return list<int|string> the quotas set, in order */
    private function quotas(): array
    {
        return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), '/editquota'))->map(fn ($pair) => $pair[0]['quota'])->values()->all();
    }

    public function test_extra_disk_is_the_packages_quota_plus_its_gb_set_up_changed_and_taken_off(): void
    {
        $this->whm();
        $this->choose($this->disk, '+10GB');
        ExtensionHelper::createServer($this->service->fresh());
        $this->assertSame([20480 + 10240], $this->quotas());

        $this->service->update(['status' => 'active']);
        $this->choose($this->disk, '+20GB');
        ExtensionHelper::upgradeServer($this->service->fresh());
        $this->choose($this->disk, 'None');
        ExtensionHelper::upgradeServer($this->service->fresh());

        $this->assertSame([30720, 40960, 20480], $this->quotas(), 'changed, then back to its package\'s');
    }

    public function test_an_unlimited_package_or_a_product_without_the_option_is_left_as_it_is(): void
    {
        $this->whm('unlimited');
        $this->choose($this->disk, '+10GB');
        ExtensionHelper::createServer($this->service->fresh());
        $this->assertSame([], $this->quotas());

        $this->service->configs()->delete();
        $this->service->update(['status' => 'active']);
        ExtensionHelper::upgradeServer($this->service->fresh());
        $this->assertSame([], $this->quotas(), 'a quota staff set in WHM stays');
    }

    public function test_a_quota_refused_after_the_account_is_made_is_a_change_package_for_staff_not_a_second_account(): void
    {
        Http::fake([
            self::WHM . '/createacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
            self::WHM . '/getpkginfo*' => Http::response(['metadata' => ['result' => 1], 'data' => ['pkg' => ['QUOTA' => '20480']]]),
            self::WHM . '/editquota*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'Quotas are disabled']]),
        ]);
        $this->choose($this->disk, '+10GB');

        $this->assertTrue((bool) ExtensionHelper::createServer($this->service->fresh()));

        $this->assertNotNull($this->service->fresh()->username, 'the account is made');
        $action = ModuleAction::query()->open()->where('action', 'upgrade')->sole();
        $this->assertStringContainsString('Quotas are disabled', (string) $action->last_error);
    }

    public function test_a_dedicated_ip_is_a_task_for_staff_when_taken_and_when_given_back(): void
    {
        $this->choose($this->ip, 'Yes');
        $this->service->update(['status' => 'active']); // it starts with it

        $task = ModuleAction::query()->where('action', OptionTasks::ACTION)->sole();
        $this->assertStringContainsString('Give: Dedicated IP: Yes', (string) $task->last_error);
        $this->assertStringContainsString('example.test', (string) $task->last_error);
        Mail::assertQueued(SystemMail::class, fn (SystemMail $mail) => str_contains($mail->envelope()->subject, 'Give: Dedicated IP: Yes'));
        $task->markResolved(null);

        $this->service->update(['price' => 12.00]); // nothing of its options changed: no task
        $this->assertSame(0, ModuleAction::query()->open()->count());

        $this->service->update(['status' => 'terminated']);
        $this->assertStringContainsString('Take back: Dedicated IP: Yes', (string) ModuleAction::query()->open()->sole()->last_error);
    }

    public function test_an_upgrade_that_adds_or_removes_it_tells_staff_what_to_do(): void
    {
        $this->service->update(['status' => 'active']);
        $this->choose($this->ip, 'No');
        $upgrade = ServiceUpgrade::create(['service_id' => $this->service->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id, 'status' => 'pending']);
        $upgrade->configs()->create(['config_option_id' => $this->ip['_']->id, 'config_value_id' => $this->ip['Yes']->id]);

        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        $this->assertStringContainsString('Give: Dedicated IP: Yes', (string) ModuleAction::query()->where('action', OptionTasks::ACTION)->sole()->last_error);
    }

    public function test_staff_tasks_are_never_retried_as_a_module_call(): void
    {
        Http::fake();
        $this->choose($this->ip, 'Yes');
        $this->service->update(['status' => 'active']);
        $task = ModuleAction::query()->where('action', OptionTasks::ACTION)->sole();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(ListModuleActions::class)->assertTableActionHidden('retry', $task);
        $this->livewireAs($staff)->test(ListModuleActions::class)->callTableAction('resolve', $task);

        $this->assertSame(ModuleAction::STATUS_RESOLVED, $task->fresh()->status);
    }
}
