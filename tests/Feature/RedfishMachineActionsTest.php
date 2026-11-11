<?php

namespace Tests\Feature;

use App\Admin\Resources\RedfishMachineResource\Pages\ViewRedfishMachine;
use App\Helpers\ExtensionHelper;
use App\Models\RedfishMachine;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Redfish\MachineActions;
use App\Services\Redfish\MachineSync;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MyCS\Redfish\Tests\FakeActionTransport;
use Tests\TestCase;

/**
 * What staff may tell a Redfish machine (owner 2026-11-11): power, locate light, boot once, BMC restart; with the
 * server's actions' account only, asked first, written to the server's audit.
 */
class RedfishMachineActionsTest extends TestCase
{
    use RefreshDatabase;

    private const BMC = 'https://10.0.0.20';

    private const RESET = '/redfish/v1/Systems/1/Actions/ComputerSystem.Reset';

    /** @var array<string, array{0: int, 1: mixed}> what the BMC answers a change, by «METHOD path»; the others get 204 */
    private array $refusals = [];

    /** @var array<string, array{0: int, 1: mixed}> the BMC's reads, by path */
    private array $reads = [];

    /** The BMC answers «done» to a PATCH and keeps the old value. */
    private bool $forgets = false;

    /** The BMC never answers a change. */
    private bool $silent = false;

    private int $unanswered = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->reads = FakeActionTransport::bmc();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (!str_starts_with($request->url(), self::BMC . '/')) {
                return Http::response('', 404);
            }
            if ($this->silent && $request->method() !== 'GET') {
                $this->unanswered++;
                throw new ConnectionException('timed out');
            }
            [$status, $data] = $request->method() === 'GET' ? ($this->reads[$path] ?? [404, '']) : ($this->refusals[$request->method() . ' ' . $path] ?? [204, '']);
            // A PATCH the BMC takes shows on its next read, unless this BMC is one that says «done» and keeps the old value.
            if ($request->method() === 'PATCH' && $status === 204 && !$this->forgets && isset($this->reads[$path])) {
                $this->reads[$path][1] = array_replace_recursive($this->reads[$path][1], $request->data());
            }

            return Http::response(is_string($data) ? $data : (string) json_encode($data), $status);
        });
    }

    private function machine(bool $actions = true, ?int $max = 1): RedfishMachine
    {
        $server = Server::query()->create(['name' => 'node3', 'extension' => 'Redfish', 'type' => 'server', 'enabled' => true, 'max_accounts' => $max]);
        ExtensionHelper::saveSettings($server, ['host' => self::BMC, 'self_signed' => true, 'username' => 'reader', 'password' => 's3cret']
            + ($actions ? ['action_username' => 'operator', 'action_password' => '0p-secret'] : []));
        MachineSync::ensureRows();

        return RedfishMachine::query()->where('server_id', $server->id)->sole();
    }

    private function staff(array $permissions = ['*']): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]), 'staff');
    }

    private function page(RedfishMachine $row)
    {
        return Livewire::test(ViewRedfishMachine::class, ['record' => $row->id]);
    }

    /** @return list<array{0: string, 1: string, 2: mixed, 3: string}> the changes sent: method, path, body, login */
    private function writes(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])->filter(fn (Request $request) => $request->method() !== 'GET')
            ->map(fn (Request $request) => [$request->method(), (string) parse_url($request->url(), PHP_URL_PATH), $request->data(),
                (string) base64_decode(substr($request->header('Authorization')[0] ?? '', 6))])->values()->all();
    }

    private function lastAudit(RedfishMachine $row): ?string
    {
        return $row->server->audits()->where('event', 'extension_action')->latest('id')->first()?->new_values['action'] ?? null;
    }

    public function test_a_server_without_an_actions_account_offers_none_and_sends_none(): void
    {
        $this->staff();
        $row = $this->machine(actions: false);

        $this->page($row)->assertActionHidden('powerOn')->assertActionHidden('powerForceOff')->assertActionHidden('locateOn')->assertActionHidden('bootPxe')
            ->assertActionHidden('bmcRestart')->assertSee('once «Actions» is turned on in the Server settings');
        $this->assertFalse(MachineActions::offered($row->server));
        try {
            MachineActions::power($row->server, 'ForceOff');
            $this->fail('acted with the read-only account');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no account for actions', $e->getMessage());
        }
        $this->assertSame([], $this->writes());
    }

    public function test_the_actions_password_is_kept_encrypted_and_never_reaches_the_page(): void
    {
        $this->staff();
        $row = $this->machine();
        $stored = $row->server->settings->firstWhere('key', 'action_password');

        $this->assertTrue((bool) $stored->encrypted);
        $this->assertStringNotContainsString('0p-secret', (string) $stored->getRawOriginal('value'));
        $page = $this->page($row)->mountAction('powerOn');
        $this->assertStringNotContainsString('0p-secret', $page->html(false) . json_encode($page->snapshot));
    }

    public function test_power_on_is_asked_first_sent_with_the_actions_account_and_audited(): void
    {
        $this->staff();
        $row = $this->machine();

        $this->page($row)->assertActionVisible('powerOn')->mountAction('powerOn')
            ->assertMountedActionModalSee(['Power on: node3', 'Turns the machine on.', 'This machine is free.']);
        $this->assertSame([], $this->writes(), 'opening the confirmation sends nothing');

        $this->page($row)->callAction('powerOn')->assertNotified('Power on: sent');

        $this->assertSame([['POST', self::RESET, ['ResetType' => 'On'], 'operator:0p-secret']], $this->writes());
        $audits = $row->server->audits()->where('event', 'extension_action')->orderBy('id')->get();
        $this->assertSame(['redfish power: On: sending', 'redfish power: On: done'], $audits->map(fn ($audit) => $audit->new_values['action'])->all());
        $this->assertSame([auth('staff')->id()], $audits->pluck('user_id')->unique()->values()->all(), 'who asked');
    }

    public function test_what_interrupts_the_machine_needs_its_name_typed_and_says_whose_it_is(): void
    {
        $this->staff();
        $row = $this->machine();
        $product = $this->createProduct();
        $product->product->update(['server_id' => $row->server_id]);
        Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $row->server_id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 90, 'quantity' => 1, 'domain' => 'box.example.test']);

        $this->page($row)->mountAction('powerForceOff')->assertMountedActionModalSee(['unsaved data is lost', 'This machine is given to:', 'box.example.test']);
        $this->page($row)->callAction('powerForceOff', ['name' => 'node4'])->assertHasActionErrors(['name']);
        $this->page($row)->callAction('powerForceOff', [])->assertHasActionErrors(['name']);
        $this->assertSame([], $this->writes());
        $this->assertNull($this->lastAudit($row));

        $this->page($row)->callAction('powerForceOff', ['name' => 'node3'])->assertHasNoActionErrors()->assertNotified('Force off: sent');
        $this->assertSame([['POST', self::RESET, ['ResetType' => 'ForceOff'], 'operator:0p-secret']], $this->writes());
        $this->assertSame('redfish power: ForceOff: done', $this->lastAudit($row));
    }

    public function test_every_interrupting_power_action_is_typed_and_power_on_is_not(): void
    {
        $this->staff();
        $row = $this->machine(max: 0);

        foreach (['GracefulShutdown', 'GracefulRestart', 'ForceOff', 'ForceRestart', 'PowerCycle'] as $type) {
            $this->page($row)->callAction('power' . $type)->assertHasActionErrors(['name']);
        }
        $this->assertSame([], $this->writes());
        $this->page($row)->mountAction('powerForceRestart')->assertMountedActionModalSee('a machine of our own');

        $this->page($row)->callAction('powerOn')->assertHasNoActionErrors()->assertNotified('Power on: sent');
        $this->assertCount(1, $this->writes());
    }

    public function test_a_change_the_bmc_never_answers_is_unknown_not_undone_and_never_sent_again(): void
    {
        $this->staff();
        $row = $this->machine();
        $this->silent = true;

        $this->page($row)->callAction('powerForceOff', ['name' => 'node3'])->assertNotified('Force off: no answer, unknown whether done');

        $this->assertSame(1, $this->unanswered, 'sent once');
        $this->assertStringStartsWith('redfish power: ForceOff (no answer, unknown whether done:', (string) $this->lastAudit($row));
    }

    public function test_a_second_power_action_right_after_one_is_not_sent(): void
    {
        $this->staff();
        $row = $this->machine();
        $this->page($row)->callAction('powerOn')->assertNotified('Power on: sent');

        $this->page($row)->callAction('powerForceOff', ['name' => 'node3'])->assertNotified('Force off: not done');
        $this->assertCount(1, $this->writes());
        // The locate light is no power action: it goes.
        $this->page($row)->callAction('locateOn')->assertNotified('Locate light on: sent');

        $this->travel(MachineActions::POWER_PAUSE + 1)->seconds();
        $this->page($row)->callAction('powerForceOff', ['name' => 'node3'])->assertNotified('Force off: sent');
        $this->assertCount(3, $this->writes());
    }

    public function test_a_bmc_with_several_systems_is_never_acted_on(): void
    {
        $this->staff();
        $row = $this->machine();
        $this->reads['/redfish/v1/Systems'][1]['Members'][] = ['@odata.id' => '/redfish/v1/Systems/2'];

        foreach ([['powerForceOff', ['name' => 'node3']], ['bootPxe', []], ['locateOn', []]] as [$action, $data]) {
            $this->page($row)->callAction($action, $data);
            $this->travel(MachineActions::POWER_PAUSE + 1)->seconds();
        }

        $this->assertSame([], $this->writes());
        $this->assertStringContainsString('Several systems are behind this BMC (2): nothing was sent', (string) $this->lastAudit($row));
    }

    public function test_a_bmc_that_says_done_and_keeps_the_old_value_is_said(): void
    {
        $this->staff();
        $row = $this->machine();
        $this->forgets = true;

        $this->page($row)->callAction('bootPxe')->assertNotified('Network (PXE): sent, not shown yet');
        $this->page($row)->callAction('locateOn')->assertNotified('Locate light on: sent, not shown yet');
    }

    public function test_the_read_after_an_action_is_the_readers(): void
    {
        $this->staff();
        $row = $this->machine();

        $this->page($row)->callAction('locateOn');

        $after = Http::recorded()->map(fn (array $pair) => $pair[0])->skipUntil(fn (Request $request) => $request->method() === 'PATCH')->skip(1);
        $this->assertGreaterThan(0, $after->count());
        foreach ($after as $request) {
            $this->assertSame(['GET', ['Basic ' . base64_encode('reader:s3cret')]], [$request->method(), $request->header('Authorization')]);
        }
    }

    public function test_a_name_kept_with_spaces_around_it_can_still_be_typed(): void
    {
        $this->staff();
        $row = $this->machine();
        $row->server->forceFill(['name' => ' node3 '])->saveQuietly();

        $this->page($row->fresh())->callAction('powerForceOff', ['name' => 'node3'])->assertHasNoActionErrors();
        $this->assertCount(1, $this->writes());
    }

    public function test_staff_who_may_update_servers_cannot_act_where_there_is_no_actions_account(): void
    {
        $this->staff();
        $row = $this->machine(actions: false);

        $this->page($row)->mountAction('powerOn')->callMountedAction();
        $this->page($row)->mountAction('bmcRestart')->callMountedAction();

        $this->assertSame([], $this->writes());
    }

    public function test_a_power_action_the_bmc_does_not_offer_is_said_and_audited_as_not_done(): void
    {
        $this->staff();
        $row = $this->machine();

        $this->page($row)->callAction('powerPowerCycle', ['name' => 'node3'])->assertNotified('Power cycle: not done');

        $this->assertSame([], $this->writes());
        $this->assertStringStartsWith('redfish power: PowerCycle (not done: This BMC does not offer «PowerCycle»', (string) $this->lastAudit($row));
    }

    public function test_what_the_bmc_refuses_is_shown_as_text_and_audited(): void
    {
        $this->staff();
        $row = $this->machine();
        $this->refusals['POST ' . self::RESET] = [400, ['error' => ['@Message.ExtendedInfo' => [['Message' => '<b>Already</b> on']]]]];

        $this->page($row)->callAction('powerOn');

        Notification::assertNotified(Notification::make()->danger()->title('Power on: not done')
            ->body('The BMC refused (HTTP 400): &lt;b&gt;Already&lt;/b&gt; on')->persistent());
        $this->assertCount(1, $this->writes());
        $this->assertStringContainsString('(not done: The BMC refused (HTTP 400)', (string) $this->lastAudit($row));
    }

    public function test_the_locate_light_and_boot_once_are_sent_and_the_summary_read_again(): void
    {
        $this->staff();
        $row = $this->machine();

        $this->page($row)->callAction('locateOn')->assertNotified('Locate light on: sent');
        $this->page($row)->callAction('bootPxe')->assertNotified('Network (PXE): sent');
        $this->page($row)->callAction('bootCancel')->assertNotified('Cancel boot once: sent');
        $this->page($row)->callAction('locateOff');

        $this->assertSame([
            ['PATCH', '/redfish/v1/Systems/1', ['IndicatorLED' => 'Blinking'], 'operator:0p-secret'],
            ['PATCH', '/redfish/v1/Systems/1', ['Boot' => ['BootSourceOverrideEnabled' => 'Once', 'BootSourceOverrideTarget' => 'Pxe']], 'operator:0p-secret'],
            ['PATCH', '/redfish/v1/Systems/1', ['Boot' => ['BootSourceOverrideEnabled' => 'Disabled']], 'operator:0p-secret'],
            ['PATCH', '/redfish/v1/Systems/1', ['IndicatorLED' => 'Off'], 'operator:0p-secret'],
        ], $this->writes());
        $this->assertSame('redfish locate light: off: done', $this->lastAudit($row));
        $this->assertNotNull($row->fresh()->read_at, 'read again after');
        $this->assertSame('On', $row->fresh()->power_state);

        // A boot source this BMC does not have: refused before anything is sent.
        $this->page($row)->callAction('bootCd')->assertNotified('CD / virtual media: not done');
        $this->assertCount(4, $this->writes());
    }

    public function test_the_bmcs_restart(): void
    {
        $this->staff();
        $row = $this->machine();

        $this->page($row)->mountAction('bmcRestart')->assertMountedActionModalSee('The machine keeps running');
        $this->page($row)->callAction('bmcRestart')->assertNotified('Restart the BMC: sent');

        $this->assertSame([['POST', '/redfish/v1/Managers/1/Actions/Manager.Reset', ['ResetType' => 'GracefulRestart'], 'operator:0p-secret']], $this->writes());
        $this->assertSame('redfish BMC restart: done', $this->lastAudit($row));
    }

    public function test_the_page_shows_the_locate_light_and_a_boot_once_as_last_read(): void
    {
        $this->staff();
        $row = $this->machine();
        $row->update(['read_at' => now(), 'data' => ['model' => 'SYS-1000', 'powerState' => 'On', 'locating' => true, 'bootOnce' => 'Pxe']]);

        $this->page($row)->assertSee('Locate light: on')->assertSee('Next boot once: Pxe');
    }

    public function test_staff_who_may_only_see_servers_get_no_action(): void
    {
        $row = $this->machine();
        $this->staff(['admin.servers.viewAny']);

        $page = $this->page($row)->assertOk()->assertActionHidden('powerOn')->assertActionHidden('powerForceOff')->assertActionHidden('locateOn')->assertActionHidden('bmcRestart');
        // Called straight, not through the hidden button.
        $page->mountAction('powerOn')->callMountedAction();
        $this->page($row)->mountAction('powerForceOff', ['name' => 'node3'])->callMountedAction(['name' => 'node3']);
        $this->page($row)->mountAction('bmcRestart')->callMountedAction();

        $this->assertSame([], $this->writes());
        $this->assertNull($this->lastAudit($row));
    }

    public function test_the_hourly_read_and_the_hardware_never_use_the_actions_account_or_change_anything(): void
    {
        $row = $this->machine();

        $this->artisan('redfish:sync')->assertExitCode(0);
        ExtensionHelper::testConfig($row->server, ExtensionHelper::settingsToArray($row->server->settings));

        $this->assertSame([], $this->writes());
        Http::assertNotSent(fn (Request $request) => str_contains((string) base64_decode(substr($request->header('Authorization')[0] ?? '', 6)), 'operator'));
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Basic ' . base64_encode('reader:s3cret')]);
    }

    public function test_a_machines_actions_are_one_at_a_time(): void
    {
        $row = $this->machine();
        $lock = cache()->lock("redfish.action.{$row->server_id}", 60);
        $this->assertTrue($lock->get());

        try {
            MachineActions::power($row->server, 'On');
            $this->fail('two actions at once');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Another action is being sent', $e->getMessage());
        } finally {
            $lock->release();
        }
        $this->assertSame([], $this->writes());
    }

    public function test_the_reads_account_does_the_actions_when_the_checkbox_says_so(): void
    {
        $this->staff();
        $row = $this->machine(actions: false);
        $this->assertFalse(MachineActions::offered($row->server));

        ExtensionHelper::saveSettings($row->server, ['actions_same_account' => true], skipNull: true);
        $row = $row->fresh();

        $this->assertTrue(MachineActions::offered($row->server));
        $this->page($row)->assertActionVisible('powerOn')->callAction('powerOn')->assertNotified('Power on: sent');
        $this->assertSame([['POST', self::RESET, ['ResetType' => 'On'], 'reader:s3cret']], $this->writes());
    }

    public function test_another_account_set_for_the_actions_is_the_one_used_whatever_the_checkbox(): void
    {
        $this->staff();
        $row = $this->machine();
        ExtensionHelper::saveSettings($row->server, ['actions_same_account' => true], skipNull: true);

        $this->page($row->fresh())->callAction('powerOn');

        $this->assertSame('operator:0p-secret', $this->writes()[0][3]);
    }
}
