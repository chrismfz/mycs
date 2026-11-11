<?php

namespace Tests\Feature;

use App\Admin\Resources\ServerResource\Pages\EditServer;
use App\Admin\Resources\ServerResource\Pages\ListServers;
use App\Exceptions\NoServerWithRoom;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\ServerSelector;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MyCS\Redfish\Tests\FakeTransport;
use Tests\TestCase;

/**
 * A physical machine read through its BMC (Redfish, owner 2026-11-11): one server per machine. Its service is the
 * customer who has it, none and it is free, «Max Accounts» 0 and it is our own. Read only when staff ask, never changed.
 */
class RedfishMachineTest extends TestCase
{
    use RefreshDatabase;

    private const BMC = 'https://10.0.0.20';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @var array<string, array{0: int, 1: mixed}>|null the BMC's answers by path; null: any request fails the test */
    private ?array $answers = null;

    /**
     * One fake for the whole test (a second Http::fake would only stand behind the first).
     *
     * @param  array<string, array{0: int, 1: mixed}>|null  $answers  null: the usual BMC
     */
    private function bmc(?array $answers = null): void
    {
        $first = $this->answers === null;
        $this->answers = $answers ?? FakeTransport::bmc();
        if (!$first) {
            return;
        }
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            [$status, $data] = str_starts_with($request->url(), self::BMC . '/') && isset($this->answers[$path]) ? $this->answers[$path] : [404, ['error' => 'none']];

            return Http::response(is_string($data) ? $data : (string) json_encode($data), $status);
        });
    }

    private function machine(string $name = 'rack4-u12', ?int $max = 1, array $settings = []): Server
    {
        $server = Server::query()->create(['name' => $name, 'extension' => 'Redfish', 'type' => 'server', 'enabled' => true, 'max_accounts' => $max]);
        ExtensionHelper::saveSettings($server, $settings + ['host' => self::BMC, 'self_signed' => true, 'username' => 'reader', 'password' => 's3cret', 'location' => 'Rack 4, U12']);

        return $server->fresh();
    }

    private function serviceOn(Server $server, string $status = 'active'): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 90, 'quantity' => 1,
            'domain' => 'box.example.test']);
    }

    private function staff(array $permissions = ['*']): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]), 'staff');
    }

    public function test_the_module_is_offered_and_its_password_kept_encrypted(): void
    {
        $this->assertContains('Redfish', array_column(ExtensionHelper::getExtensions('server'), 'name'));

        $server = $this->machine();
        $password = $server->settings->firstWhere('key', 'password');
        $this->assertTrue((bool) $password->encrypted);
        $this->assertStringNotContainsString('s3cret', (string) $password->getRawOriginal('value'));
    }

    public function test_the_connection_test_reads_the_bmc_and_only_reads(): void
    {
        $this->bmc();
        $server = $this->machine();

        $this->assertTrue(ExtensionHelper::testConfig($server, ExtensionHelper::settingsToArray($server->settings)));

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_starts_with($request->url(), self::BMC . '/redfish/v1/')
            && $request->header('Authorization') === ['Basic ' . base64_encode('reader:s3cret')]);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_the_connection_test_says_why_not(): void
    {
        $server = $this->machine();
        $test = fn (array $over = []) => (string) ExtensionHelper::testConfig($server, $over + ExtensionHelper::settingsToArray($server->settings));

        $this->bmc(['/redfish/v1/Systems' => [401, '']]);
        $this->assertStringContainsString('refused the login', $test());
        $this->assertStringNotContainsString('s3cret', $test());

        $this->bmc([]);
        $this->assertStringContainsString('firmware too old', $test());

        // Never over http, and a self-signed certificate only to a known IP (PanelConnection): nothing is sent.
        $sent = count(Http::recorded());
        $this->assertStringContainsString('https://', $test(['host' => 'http://10.0.0.20']));
        $this->assertStringContainsString('needs the server IP', $test(['host' => 'https://bmc.example.test']));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_staff_read_the_hardware_from_the_servers_page_when_they_ask(): void
    {
        $this->bmc();
        $this->staff();
        $server = $this->machine();

        $page = Livewire::test(EditServer::class, ['record' => $server->id]);
        Http::assertNothingSent(); // the page itself never waits for a BMC

        $page->assertActionVisible('hardware')->mountAction('hardware')
            ->assertMountedActionModalSee(['Contoso SYS-1000', 'S000001', 'RACK4-U12', '2 × Example CPU 4210', '128 GiB', 'Power: On', 'Health: OK', '182 W',
                '170 W / 180 W / 240 W', 'last 5 min', 'Nobody: free', 'Rack 4, U12'])
            ->assertMountedActionModalDontSee('s3cret');
        Http::assertSentCount(5); // read once, however often the modal is drawn
    }

    public function test_the_hardware_says_who_has_the_machine(): void
    {
        $this->bmc();
        $taken = $this->machine('taken');
        $service = $this->serviceOn($taken);
        $ended = $this->serviceOn($taken, Service::STATUS_CANCELLED);

        $shown = EditServer::hardware($taken);
        $this->assertCount(1, $shown['services']);
        $this->assertStringContainsString('service #' . $service->id . ' · box.example.test', $shown['services'][0]['label']);
        $this->assertStringNotContainsString('#' . $ended->id, $shown['services'][0]['label']);
        $this->assertFalse($shown['own']);

        $own = EditServer::hardware($this->machine('ours', 0));
        $this->assertSame([[], true], [$own['services'], $own['own']]);
        $this->assertStringContainsString('our own machine', view('admin.servers.hardware', $own)->render());
    }

    public function test_a_bmc_that_does_not_answer_is_said_and_who_has_it_still_shown(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->staff();
        $server = $this->machine();
        $this->serviceOn($server);

        Livewire::test(EditServer::class, ['record' => $server->id])->mountAction('hardware')
            ->assertMountedActionModalSee(['The BMC was not read', 'did not answer', 'box.example.test'])->assertMountedActionModalDontSee('Power:');
    }

    public function test_what_a_bmc_says_is_shown_as_text(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Systems/1'][1]['Model'] = '<script>alert(1)</script>';
        $answers['/redfish/v1/Systems/1'][1]['Status'] = ['Health' => 'Critical'];
        $answers['/redfish/v1/Systems/1'][1]['PowerState'] = 'Off';
        $this->bmc($answers);

        $html = view('admin.servers.hardware', EditServer::hardware($this->machine()))->render();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
        $this->assertStringContainsString('Health: Critical', $html);
        $this->assertStringContainsString('Power: Off', $html);
    }

    public function test_other_servers_have_no_hardware_button_and_the_list_filters_by_module(): void
    {
        $this->staff();
        $panel = Server::query()->create(['name' => 'Manual', 'extension' => 'OfflineDedicated', 'type' => 'server', 'enabled' => true]);
        $machine = $this->machine();

        Livewire::test(EditServer::class, ['record' => $panel->id])->assertActionHidden('hardware');
        Livewire::test(ListServers::class)->assertCanSeeTableRecords([$panel, $machine])
            ->filterTable('extension', 'Redfish')->assertCanSeeTableRecords([$machine])->assertCanNotSeeTableRecords([$panel]);
    }

    public function test_an_order_takes_a_free_machine_never_a_taken_one_or_our_own(): void
    {
        $ours = $this->machine('ours', 0);
        $taken = $this->machine('taken');
        $free = $this->machine('free');
        $this->serviceOn($taken);
        $product = $this->createProduct()->product;
        $product->update(['server_id' => $ours->id]);
        $group = ServerGroup::query()->create(['name' => 'Dedicated', 'fill_type' => ServerGroup::FILL_IN_ORDER]);
        $group->servers()->sync([$ours->id, $taken->id, $free->id]);
        $product->update(['server_group_id' => $group->id]);

        $this->assertSame($free->id, ServerSelector::forNewAccount($product->fresh())->id);

        // The last one gone: nothing is given, and the create fails for staff to see, never onto our own machine.
        $this->serviceOn($free);
        $this->assertNull(ServerSelector::forNewAccount($product->fresh()));
        $pending = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plans()->first()->id,
            'product_id' => $product->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 90, 'quantity' => 1]);
        $this->expectException(NoServerWithRoom::class);
        ServerSelector::assign($pending);
    }

    public function test_a_suspension_of_its_service_is_a_task_for_staff_and_nothing_reaches_the_bmc(): void
    {
        Http::fake(fn () => $this->fail('the BMC was called'));
        config(['settings.mail_disable' => true]);
        $service = $this->serviceOn($this->machine());
        $service->forceFill(['status' => 'suspended'])->save();

        (new SuspendJob($service))->handle();

        $task = ModuleAction::query()->open()->where('service_id', $service->id)->sole();
        $this->assertSame(['suspend', 'Redfish', ModuleAction::SUBJECT_BY_HAND], [$task->action, $task->module, $task->subject]);
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_max_accounts_zero_is_kept_and_shown_on_the_list(): void
    {
        $this->staff();
        $server = $this->machine('ours', 1);

        Livewire::test(EditServer::class, ['record' => $server->id])->fillForm(['max_accounts' => 0])->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, (int) $server->fresh()->max_accounts);
        $this->assertNotNull($server->fresh()->max_accounts);
        $this->assertFalse($server->fresh()->hasRoom());
        Livewire::test(ListServers::class)->assertTableColumnStateSet('accounts', '0 / 0', $server);
    }
}
