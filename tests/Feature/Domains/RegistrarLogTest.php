<?php

namespace Tests\Feature\Domains;

use App\Admin\Logs;
use App\Admin\Resources\DomainRegistrarLogResource;
use App\Admin\Resources\DomainRegistrarLogResource\Pages\ListDomainRegistrarLogs;
use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainSyncService;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The Registrar Log (owner 2026-10-23): every sync failure is kept, not only the domain's last one, with each change a
 * sync brought; all the domains' registrar calls in one list, among the logs.
 */
class RegistrarLogTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $connection;

    /** @var list<DomainSyncResult|Throwable> what the registrar answers, in turn */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $registrar = Mockery::mock(DomainRegistrar::class);
        $registrar->shouldReceive('syncDomain')->andReturnUsing(function () {
            $answer = array_shift($this->answers);

            return $answer instanceof Throwable ? throw $answer : $answer;
        });
        $factory = Mockery::mock(DomainRegistrarFactory::class);
        $factory->shouldReceive('for')->andReturn($registrar);
        $factory->shouldReceive('credentialsFor')->andReturn(new DomainRegistrarCredentials([], sandbox: false));
        $this->app->instance(DomainRegistrarFactory::class, $factory);
    }

    private function domain(): Domain
    {
        return Domain::factory()->create(['sld' => 'cdnet', 'fqdn' => 'cdnet.test', 'registrar_connection_id' => $this->connection->id,
            'expires_at' => '2026-10-31', 'registrar_domain_id' => '42']);
    }

    public function test_every_sync_failure_is_kept_not_only_the_last(): void
    {
        $domain = $this->domain();
        $this->answers = [new RuntimeException('Timeout talking to the registrar'), new RuntimeException('Authentication failed')];
        foreach ($this->answers as $ignored) {
            try {
                app(DomainSyncService::class)->sync($domain->fresh());
                $this->fail('The sync should fail.');
            } catch (RuntimeException) {
            }
        }

        $this->assertSame('Authentication failed', $domain->fresh()->sync_error); // the domain keeps the last
        $logs = DomainRegistrarLog::query()->where('domain_id', $domain->id)->orderBy('id')->get();
        $this->assertSame(['sync', 'sync'], $logs->pluck('action')->all());
        $this->assertSame([DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::STATUS_FAILED], $logs->pluck('status')->all());
        $this->assertSame(['Timeout talking to the registrar', 'Authentication failed'], $logs->pluck('error')->all());
        $this->assertSame($this->connection->id, $logs[0]->registrar_connection_id);
        $this->assertNull($logs[0]->staff_id); // the cron: the system
    }

    public function test_a_sync_that_changed_something_says_what_and_one_that_did_not_writes_nothing(): void
    {
        $domain = $this->domain();
        $this->answers = [
            new DomainSyncResult(expiresAt: '2027-10-31', registrarDomainId: '42', status: DomainStatus::Active),
            new DomainSyncResult(expiresAt: '2027-10-31', registrarDomainId: '42', status: DomainStatus::Active),
        ];
        app(DomainSyncService::class)->sync($domain->fresh());
        app(DomainSyncService::class)->sync($domain->fresh());

        $log = DomainRegistrarLog::query()->where('domain_id', $domain->id)->sole();
        $this->assertSame(['sync', DomainRegistrarLog::STATUS_OK], [$log->action, $log->status]);
        $this->assertSame('2027-10-31', substr((string) $log->response['expires_at']['to'], 0, 10));
        $this->assertSame('2026-10-31', substr((string) $log->response['expires_at']['from'], 0, 10));
    }

    public function test_the_registrar_log_lists_every_domains_calls_among_the_logs(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $domain = $this->domain();
        $failed = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $this->connection->id,
            'action' => 'sync', 'status' => DomainRegistrarLog::STATUS_FAILED, 'error' => 'Timeout']);
        $renewed = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $this->connection->id,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK]);
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');

        $this->get(DomainRegistrarLogResource::getUrl('index'))->assertOk()->assertSee('cdnet.test');
        Livewire::test(ListDomainRegistrarLogs::class)
            ->assertCanSeeTableRecords([$failed, $renewed])
            ->assertTableColumnStateSet('registrarConnection.label', 'OP', $failed)
            ->filterTable('status', DomainRegistrarLog::STATUS_FAILED)
            ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$renewed]);
        $this->assertContains(__('Registrar Log'), array_map(fn ($item) => $item->getLabel(), Logs::navigation()));
    }

    public function test_only_staff_who_see_domains_see_the_registrar_log(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Audit', 'permissions' => ['admin.audit.viewAny']])->id]), 'staff');

        $this->get(DomainRegistrarLogResource::getUrl('index'))->assertForbidden();
        $this->assertNotContains(__('Registrar Log'), array_map(fn ($item) => $item->getLabel(), Logs::navigation()));
    }

    /** The same failure in every batch (a registrar down) is logged once, when it began; its end is logged too. */
    public function test_a_repeated_failure_is_logged_once_and_its_end_once(): void
    {
        $domain = $this->domain();
        $this->answers = [new RuntimeException('Registrar down'), new RuntimeException('Registrar down'), new RuntimeException('Registrar down'),
            new DomainSyncResult(expiresAt: '2026-10-31', registrarDomainId: '42', status: DomainStatus::Active)];
        foreach (range(1, 3) as $ignored) {
            try {
                app(DomainSyncService::class)->sync($domain->fresh());
            } catch (RuntimeException) {
            }
        }
        app(DomainSyncService::class)->sync($domain->fresh());

        $logs = DomainRegistrarLog::query()->where('domain_id', $domain->id)->orderBy('id')->get();
        $this->assertSame([DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::STATUS_OK], $logs->pluck('status')->all());
        $this->assertNull($domain->fresh()->sync_error);
    }

    /** R1 of #404: a sync that only warns changes nothing, so it writes nothing, batch after batch. */
    public function test_a_sync_that_only_warns_writes_no_row_each_time(): void
    {
        $domain = $this->domain();
        $answer = new DomainSyncResult(expiresAt: '1970-01-01', registrarDomainId: '42', status: DomainStatus::Active);
        $this->answers = [$answer, $answer, $answer];
        foreach (range(1, 3) as $ignored) {
            app(DomainSyncService::class)->sync($domain->fresh());
        }

        $this->assertSame('2026-10-31', $domain->fresh()->expires_at->toDateString()); // nothing changed
        $this->assertSame(0, DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'sync')->count());
    }

    /** R2 of #404: every other writer of this log masks its error (Redact::text); the sync's failure too. */
    public function test_a_sync_failure_is_masked_as_every_other_registrar_log_error(): void
    {
        $domain = $this->domain();
        $this->answers = [new RuntimeException('HTTP 500 for s_pw=Hunter2Secret&command=StatusDomain')];
        try {
            app(DomainSyncService::class)->sync($domain->fresh());
        } catch (RuntimeException) {
        }

        $error = (string) DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'sync')->value('error');
        $this->assertStringNotContainsString('Hunter2Secret', $error);
    }
}
