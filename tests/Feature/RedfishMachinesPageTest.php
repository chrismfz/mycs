<?php

namespace Tests\Feature;

use App\Admin\Resources\RedfishMachineResource;
use App\Admin\Resources\RedfishMachineResource\Pages\ListRedfishMachines;
use App\Admin\Resources\RedfishMachineResource\Pages\ViewRedfishMachine;
use App\Admin\Resources\ServerResource\Pages\EditServer;
use App\Helpers\ExtensionHelper;
use App\Models\ModuleAction;
use App\Models\RedfishMachine;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Redfish\MachineSync;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MyCS\Redfish\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Utilities → Machines (owner 2026-11-11): every Redfish machine a line as redfish:sync last read it, and a page each
 * with its parts. The last read only is kept; a machine that needs a look is shown, nobody is told.
 */
class RedfishMachinesPageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, array{0: int, 1: mixed}>|null> each BMC's answers by its address; null: it does not answer */
    private array $bmcs = [];

    /** @var list<string> paths that time out on every BMC */
    private array $slow = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::fake(function (Request $request) {
            $base = 'https://' . parse_url($request->url(), PHP_URL_HOST);
            $answers = $this->bmcs[$base] ?? null;
            if ($answers === null || in_array((string) parse_url($request->url(), PHP_URL_PATH), $this->slow, true)) {
                throw new ConnectionException('no route');
            }
            [$status, $data] = $answers[(string) parse_url($request->url(), PHP_URL_PATH)] ?? [404, ['error' => 'none']];
            $data = $data instanceof \Closure ? $data() : $data;

            return Http::response(is_string($data) ? $data : (string) json_encode($data), $status);
        });
    }

    /** @param  array<string, array{0: int, 1: mixed}>|null|false  $answers  false: the usual BMC with its parts */
    private function machine(string $name, string $ip, array|null|false $answers = false, ?int $max = 1): Server
    {
        $this->bmcs["https://{$ip}"] = $answers === false ? FakeTransport::bmcWithParts() : $answers;
        $server = Server::query()->create(['name' => $name, 'extension' => 'Redfish', 'type' => 'server', 'enabled' => true, 'max_accounts' => $max]);
        ExtensionHelper::saveSettings($server, ['host' => "https://{$ip}", 'self_signed' => true, 'username' => 'reader', 'password' => 's3cret', 'location' => 'Rack 1 U22']);

        return $server->fresh();
    }

    private function staff(array $permissions = ['*']): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]), 'staff');
    }

    private function serviceOn(Server $server): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 90, 'quantity' => 1,
            'domain' => 'box.example.test']);
    }

    public function test_the_sync_keeps_each_machines_last_read_with_its_parts(): void
    {
        $server = $this->machine('node3', '10.0.0.21');

        $this->artisan('redfish:sync')->expectsOutputToContain('node3: On, health OK, 182 W')->assertExitCode(0);

        $row = RedfishMachine::query()->sole();
        $this->assertSame([$server->id, 'On', 'OK', 182, 24, null, null], [$row->server_id, $row->power_state, $row->health, $row->watts, $row->inlet_celsius, $row->attention, $row->error]);
        $this->assertNotNull($row->read_at);
        $machine = $row->machine();
        $this->assertSame(['Example CPU 4585PX 16-Core', 2, 2, 1, 2, true], [$machine->cpuModel, count($machine->fans), count($machine->powerSupplies),
            count($machine->memory), count($machine->drives), $machine->partsComplete]);
        $this->assertFalse($row->needsALook());
        $this->assertStringNotContainsString('s3cret', (string) $row->getRawOriginal('data'));
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');

        // Again: the same one row.
        $this->artisan('redfish:sync')->assertExitCode(0);
        $this->assertSame(1, RedfishMachine::query()->count());
    }

    public function test_a_machine_that_does_not_answer_keeps_its_last_read_with_why_and_the_others_are_read(): void
    {
        $down = $this->machine('down', '10.0.0.22');
        $this->machine('up', '10.0.0.23');
        $this->artisan('redfish:sync')->assertExitCode(0);
        $this->bmcs['https://10.0.0.22'] = null;
        $this->travel(2)->hours();

        $this->artisan('redfish:sync')->expectsOutputToContain('down: The BMC did not answer')->expectsOutputToContain('up: On')->assertExitCode(1);

        $row = RedfishMachine::query()->where('server_id', $down->id)->sole();
        $this->assertStringContainsString('did not answer', (string) $row->error);
        $this->assertNotNull($row->failed_at);
        $this->assertSame(['On', 182], [$row->power_state, $row->watts], 'what it had');
        $this->assertTrue($row->read_at->lt($row->failed_at));
        $this->assertTrue($row->needsALook());
        $this->assertSame(0, ModuleAction::query()->count(), 'an indication only: nobody is told');

        // It answers again: the failure is gone.
        $this->bmcs['https://10.0.0.22'] = FakeTransport::bmcWithParts();
        $this->artisan('redfish:sync', ['--server' => $down->id])->assertExitCode(0);
        $this->assertSame([null, null], [$row->fresh()->error, $row->fresh()->failed_at]);
    }

    public function test_a_disabled_server_and_other_modules_are_not_read(): void
    {
        $off = $this->machine('off', '10.0.0.24');
        $off->update(['enabled' => false]);
        $kept = RedfishMachine::query()->create(['server_id' => $off->id]); // from when it was enabled
        Server::query()->create(['name' => 'Manual', 'extension' => 'OfflineDedicated', 'type' => 'server', 'enabled' => true]);

        $this->artisan('redfish:sync')->assertExitCode(0);

        Http::assertNothingSent();
        MachineSync::ensureRows();
        $this->assertSame([$kept->id], RedfishMachine::query()->pluck('id')->all(), 'no row is made for either');
        $this->staff();
        Livewire::test(ListRedfishMachines::class)->assertCanNotSeeTableRecords([$kept]);
    }

    public function test_staff_who_may_only_see_servers_cannot_make_a_bmc_be_read(): void
    {
        $server = $this->machine('node3', '10.0.0.26');
        MachineSync::ensureRows();
        $row = RedfishMachine::query()->where('server_id', $server->id)->sole();
        $this->staff(['admin.servers.viewAny']);

        // Called straight, not through a button that is hidden.
        Livewire::test(ListRedfishMachines::class)->mountTableAction('read', $row)->callMountedTableAction();
        Livewire::test(ListRedfishMachines::class)->mountAction('refresh')->callMountedAction();
        Livewire::test(ViewRedfishMachine::class, ['record' => $row->id])->mountAction('read')->callMountedAction();

        Http::assertNothingSent();
        $this->assertNull($row->fresh()->read_at);
    }

    public function test_read_all_now_stops_at_its_deadline_and_says_which_were_not_reached(): void
    {
        $this->staff();
        $this->machine('a', '10.0.0.27');
        $late = $this->machine('b', '10.0.0.28');
        // The first machine's read takes longer than the whole deadline.
        $this->bmcs['https://10.0.0.27']['/redfish/v1/Systems'] = [200, fn () => usleep(60_000) ?: json_encode(['Members' => [['@odata.id' => '/redfish/v1/Systems/1']]])];
        $page = Livewire::test(ListRedfishMachines::class);
        $deadline = new \ReflectionClassConstant(ListRedfishMachines::class, 'READ_ALL_SECONDS');
        $this->assertSame(30.0, $deadline->getValue());

        ListRedfishMachines::$readAllSeconds = 0.05;
        try {
            $page->callAction('refresh')->assertNotified('1 read, 1 not');
        } finally {
            ListRedfishMachines::$readAllSeconds = null;
        }

        $this->assertNull(RedfishMachine::query()->where('server_id', $late->id)->sole()->read_at);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://10.0.0.28'));
    }

    public function test_the_needs_a_look_filter_agrees_with_the_row(): void
    {
        $this->staff();
        $make = function (string $name, string $ip, array $attributes) {
            $server = $this->machine($name, $ip);

            return RedfishMachine::query()->create($attributes + ['server_id' => $server->id, 'read_at' => now(), 'health' => 'OK', 'power_state' => 'On']);
        };
        $fine = $make('fine', '10.0.1.1', []);
        $silent = $make('silent', '10.0.1.2', ['health' => null]); // a BMC that says no health is not unhealthy
        $look = [
            $make('failed', '10.0.1.3', ['error' => 'The BMC did not answer', 'failed_at' => now()]),
            $make('stale', '10.0.1.4', ['read_at' => now()->subHours(RedfishMachine::STALE_HOURS)->subMinute()]),
            $make('warning', '10.0.1.5', ['health' => 'Warning']),
            $make('part', '10.0.1.6', ['attention' => 'FAN1: Critical']),
            $make('never', '10.0.1.7', ['read_at' => null, 'health' => null, 'power_state' => null]),
        ];

        $this->assertSame([false, false], [$fine->needsALook(), $silent->needsALook()]);
        foreach ($look as $row) {
            $this->assertTrue($row->needsALook(), $row->server->name);
        }
        Livewire::test(ListRedfishMachines::class)->filterTable('look', true)->assertCanSeeTableRecords($look)->assertCanNotSeeTableRecords([$fine, $silent]);
        Livewire::test(ListRedfishMachines::class)->filterTable('look', false)->assertCanSeeTableRecords([$fine, $silent])->assertCanNotSeeTableRecords($look);
    }

    public function test_what_a_bmc_says_is_text_on_the_list_and_in_the_notification(): void
    {
        $this->staff();
        $answers = FakeTransport::bmcWithParts();
        $answers['/redfish/v1/Systems/1'][1]['PowerState'] = '<a href=//x>On</a>';
        $answers['/redfish/v1/Systems/1'][1]['Manufacturer'] = '<img src=x>';
        $answers['/redfish/v1/Chassis/1/Thermal'][1]['Fans'][0] = ['Name' => '<i>FAN</i>', 'Reading' => 1, 'Status' => ['Health' => 'Critical']];
        $server = $this->machine('node3', '10.0.0.29', $answers);
        MachineSync::ensureRows();
        $row = RedfishMachine::query()->where('server_id', $server->id)->sole();

        $page = Livewire::test(ListRedfishMachines::class)->callTableAction('read', $row);

        $html = $page->html(false);
        foreach (['<a href=//x>', '<img src=x>', '<i>FAN</i>'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }
        $this->assertStringContainsString('&lt;i&gt;FAN&lt;/i&gt;: Critical', $html);
        // As sent: the BMC's «power state» escaped in the notification's body.
        Notification::assertNotified(Notification::make()->success()->title('node3')->body('&lt;a href=//x&gt;On&lt;/a&gt;, health OK, 182 W'));
    }

    public function test_one_slow_part_does_not_fail_the_machines_read(): void
    {
        $answers = FakeTransport::bmcWithParts();
        unset($answers['/redfish/v1/Systems/1/Storage']);
        $server = $this->machine('node3', '10.0.0.30', $answers);
        $this->slow = ['/redfish/v1/Systems/1/Storage'];

        $row = MachineSync::sync($server);

        $this->assertNull($row->error);
        $this->assertSame('On', $row->power_state);
        $this->assertFalse($row->machine()->partsComplete);
        $this->assertCount(2, $row->machine()->fans);
    }

    public function test_the_parts_to_look_at_are_kept_on_the_line(): void
    {
        $answers = FakeTransport::bmcWithParts();
        $answers['/redfish/v1/Chassis/1/Thermal'][1]['Fans'][0]['Status'] = ['Health' => 'Critical'];
        $answers['/redfish/v1/Chassis/1'][1]['Status'] = ['Health' => 'Critical'];
        $server = $this->machine('hot', '10.0.0.25', $answers);

        $row = MachineSync::sync($server);

        $this->assertSame(['Critical', ['FAN1: Critical']], [$row->health, $row->attentionLines()]);
        $this->assertTrue($row->needsALook());
    }

    public function test_the_list_shows_a_line_a_machine_and_who_has_it(): void
    {
        $this->staff();
        $taken = $this->machine('taken', '10.0.0.31');
        $service = $this->serviceOn($taken);
        $free = $this->machine('free', '10.0.0.32');
        $ours = $this->machine('ours', '10.0.0.33', max: 0);
        $panel = Server::query()->create(['name' => 'Manual', 'extension' => 'OfflineDedicated', 'type' => 'server', 'enabled' => true]);
        MachineSync::sync($taken);

        // The other two are on the list before their first read.
        $sent = count(Http::recorded());
        $page = Livewire::test(ListRedfishMachines::class);
        $this->assertCount($sent, Http::recorded(), 'opening the list reads no BMC');
        $rows = RedfishMachine::query()->get()->keyBy('server_id');
        $this->assertSame([$taken->id, $free->id, $ours->id], $rows->keys()->sort()->values()->all());
        $this->assertArrayNotHasKey($panel->id, $rows->all());

        $page->assertCanSeeTableRecords($rows->values())
            ->assertTableColumnStateSet('given', 'Free', $rows[$free->id])
            ->assertTableColumnStateSet('given', 'Our own', $rows[$ours->id])
            ->assertTableColumnStateSet('watts', 182, $rows[$taken->id])
            ->assertTableColumnStateSet('inlet_celsius', 24, $rows[$taken->id])
            ->assertSee('Contoso SYS-1000')->assertSee('Rack 1 U22')->assertSee('never');
        $this->assertStringContainsString('service #' . $service->id . ' · box.example.test', RedfishMachineResource::given($rows[$taken->id])['label']);
        $this->assertSame($service->id, RedfishMachineResource::given($rows[$taken->id])['service']);

        // «Needs a look»: the two never read, not the one read just now.
        $page->filterTable('look', true)->assertCanSeeTableRecords([$rows[$free->id], $rows[$ours->id]])->assertCanNotSeeTableRecords([$rows[$taken->id]]);
        $page->filterTable('look', false)->assertCanSeeTableRecords([$rows[$taken->id]])->assertCanNotSeeTableRecords([$rows[$free->id]]);
    }

    public function test_opening_the_list_never_reads_a_bmc_and_read_now_does(): void
    {
        $this->staff();
        $server = $this->machine('node3', '10.0.0.41');

        $page = Livewire::test(ListRedfishMachines::class);
        Http::assertNothingSent();
        $row = RedfishMachine::query()->where('server_id', $server->id)->sole();
        $this->assertNull($row->read_at);

        $page->callTableAction('read', $row)->assertNotified('node3');
        $this->assertSame('On', $row->fresh()->power_state);

        $this->machine('gone', '10.0.0.42', null);
        Livewire::test(ListRedfishMachines::class)->callAction('refresh')->assertNotified('1 read, 1 not');
        $this->assertStringContainsString('did not answer', (string) RedfishMachine::query()->whereHas('server', fn ($q) => $q->where('name', 'gone'))->sole()->error);
    }

    public function test_an_old_read_and_a_failed_one_need_a_look(): void
    {
        $row = MachineSync::sync($this->machine('node3', '10.0.0.43'));
        $this->assertFalse($row->needsALook());

        $this->travel(RedfishMachine::STALE_HOURS)->hours();
        $this->travel(1)->minutes();
        $this->assertTrue($row->fresh()->needsALook());
    }

    public function test_a_machines_page_shows_its_parts_as_last_read_without_reading_again(): void
    {
        $this->staff();
        $answers = FakeTransport::bmcWithParts();
        $answers['/redfish/v1/Systems/1'][1]['Model'] = '<b>SYS</b>';
        $answers['/redfish/v1/Systems/1/Storage/RAID.1/Drives/Disk.Bay.1:Enclosure.Internal.0-1:RAID.1'][1]['FailurePredicted'] = true;
        $server = $this->machine('node3', '10.0.0.51', $answers);
        $this->serviceOn($server);
        $row = MachineSync::sync($server);
        $sent = count(Http::recorded());

        Livewire::test(ViewRedfishMachine::class, ['record' => $row->id])->assertOk()
            ->assertSee(['Power: On', 'Health: OK', '182 W', '23.5 °C', 'box.example.test', 'Rack 1 U22'])
            ->assertSee(['FAN1', '5400 RPM', 'FAN2', '40 %', 'CPU Temp', '90 / 100 °C', 'PSU 1', '1000 W', 'Example CPU 4585PX 16-Core', '16 / 32',
                'DIMMA1', '64 GiB', 'DDR5 4800 MHz', 'Disk 0', 'D0001', '960.2 GB', 'SSD SATA', '97 %'])
            ->assertSee('Disk 1: failure predicted')
            ->assertSee('&lt;b&gt;SYS&lt;/b&gt;', false)->assertDontSee('<b>SYS</b>', false)
            ->assertDontSee('s3cret');
        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_machine_never_read_and_one_whose_bmc_lists_no_parts_have_a_page_too(): void
    {
        $this->staff();
        $never = $this->machine('never', '10.0.0.52', null);
        MachineSync::ensureRows();
        Livewire::test(ViewRedfishMachine::class, ['record' => RedfishMachine::query()->where('server_id', $never->id)->value('id')])
            ->assertOk()->assertSee('Never read yet.')->assertSee('Nobody: free');

        $bare = MachineSync::sync($this->machine('bare', '10.0.0.53', FakeTransport::bmc()));
        Livewire::test(ViewRedfishMachine::class, ['record' => $bare->id])->assertOk()->assertSee('The BMC lists none')->assertSee('onboard SATA');
    }

    public function test_only_staff_with_the_servers_permissions_see_the_machines_and_read_them(): void
    {
        $row = MachineSync::sync($this->machine('node3', '10.0.0.61'));
        $sent = count(Http::recorded());

        $this->staff(['admin.clients.viewAny']);
        Livewire::test(ListRedfishMachines::class)->assertForbidden();
        Livewire::test(ViewRedfishMachine::class, ['record' => $row->id])->assertForbidden();

        $this->staff(['admin.servers.viewAny']);
        Livewire::test(ListRedfishMachines::class)->assertOk()->assertTableActionHidden('read', $row)->assertActionHidden('refresh');
        Livewire::test(ViewRedfishMachine::class, ['record' => $row->id])->assertOk()->assertActionHidden('read')->assertActionHidden('server');
        $this->assertCount($sent, Http::recorded());
    }

    public function test_the_servers_hardware_links_to_the_machines_page(): void
    {
        $this->staff();
        $server = $this->machine('node3', '10.0.0.71');

        $this->assertSame(RedfishMachineResource::getUrl('index'), EditServer::hardware($server)['page']);

        $row = MachineSync::sync($server);
        $this->assertSame(RedfishMachineResource::getUrl('view', ['record' => $row->id]), EditServer::hardware($server)['page']);
        Livewire::test(EditServer::class, ['record' => $server->id])->mountAction('hardware')->assertMountedActionModalSee('Utilities → Machines');
    }

    public function test_a_deleted_server_takes_its_machine_off_the_list(): void
    {
        $this->staff();
        $server = $this->machine('node3', '10.0.0.81');
        $row = MachineSync::sync($server);

        $server->delete();

        Livewire::test(ListRedfishMachines::class)->assertCanNotSeeTableRecords([$row]);
    }

    public function test_the_sync_is_scheduled_every_hour(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('redfish:sync')->assertExitCode(0);
    }
}
