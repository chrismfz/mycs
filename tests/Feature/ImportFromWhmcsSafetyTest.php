<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nixpal\Licensing\Tests\WhmcsSchema;
use PDO;
use Tests\TestCase;

/**
 * The importer's guards (2026-10-29): it empties this instance's database first (migrate:fresh), so it writes only when
 * that database is named, by its name; the source only from what is given, never from this instance's DB_*; a dry run
 * reads and counts, and writes nothing.
 */
class ImportFromWhmcsSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $opened = [];

    protected function setUp(): void
    {
        parent::setUp();
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        WhmcsSchema::create($whmcs);
        ImportFromWhmcs::$connect = function (string $dsn) use ($whmcs) {
            $this->opened[] = $dsn;

            return $whmcs;
        };
        putenv('WHMCS_SOURCE_PASSWORD=secret');
        Client::factory()->create(); // something of this instance's own, that an import would empty
    }

    protected function tearDown(): void
    {
        ImportFromWhmcs::$connect = null;
        putenv('WHMCS_SOURCE_PASSWORD');
        parent::tearDown();
    }

    private function target(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    public function test_without_the_sources_user_and_host_nothing_is_opened(): void
    {
        $this->artisan('app:import-from-whmcs', ['dbname' => 'whmcs_copy'])->assertFailed()
            ->expectsOutputToContain('--source-user and --source-host');
        $this->assertSame([], $this->opened, 'never this instance\'s DB_* in their place');
    }

    public function test_without_the_targets_name_it_writes_nothing(): void
    {
        foreach ([[], ['--confirm-database' => 'another']] as $confirm) {
            $this->artisan('app:import-from-whmcs', ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test'] + $confirm)
                ->assertFailed()->expectsOutputToContain("--confirm-database={$this->target()}");
        }

        $this->assertSame(1, Client::query()->count(), 'nothing emptied');
        $this->assertSame(['mysql:host=db.example.test;port=3306;dbname=whmcs_copy;charset=utf8mb4'], array_values(array_unique($this->opened)));
    }

    public function test_a_dry_run_counts_the_source_and_writes_nothing(): void
    {
        $this->artisan('app:import-from-whmcs', ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-socket' => '/run/mysqld/mysqld.sock', '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('tblclients')
            ->expectsOutputToContain('Dry run: nothing written');

        $this->assertSame(1, Client::query()->count());
        $this->assertSame(['mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=whmcs_copy;charset=utf8mb4'], $this->opened);
    }

    public function test_this_instances_own_database_is_never_the_source(): void
    {
        $own = DB::connection()->getConfig();
        $this->artisan('app:import-from-whmcs', ['dbname' => $own['database'], '--source-user' => 'reader', '--source-host' => 'localhost',
            '--confirm-database' => $this->target()])
            ->assertFailed()->expectsOutputToContain('this instance\'s own database');

        $this->assertSame(1, Client::query()->count());
    }
}
