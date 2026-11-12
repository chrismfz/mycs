<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Console\Commands\ImportFromWhmcs;
use App\Models\CronStat;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nixpal\Licensing\Tests\WhmcsSchema;
use PDO;
use Tests\TestCase;
use Throwable;

/**
 * Sweep 11, S11-IMPORT-2: the import refuses to empty an instance that has run its own daily cron since its last
 * import, unless --replace-live-data is typed as well. The normal paths around it: a rehearsal instance that never ran
 * its cron, an import that failed half way, a dry run. Made-up rows only; the tiny source stops the import somewhere
 * past its reset, which is all these look at.
 */
class ImportLivedInstanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        WhmcsSchema::create($whmcs);
        foreach (['tblconfiguration', 'tblcurrencies'] as $table) {
            $whmcs->exec("CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY)");
        }
        ImportFromWhmcs::$connect = fn () => $whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
    }

    protected function tearDown(): void
    {
        ImportFromWhmcs::$connect = null;
        putenv('WHMCS_SOURCE_PASSWORD');
        parent::tearDown();
    }

    /** The documented command; its exit code (null when the tiny source stopped it with an exception) and what it said. */
    private function import(array $options = []): array
    {
        $code = null;
        $said = '';
        try {
            $code = $this->withoutMockingConsoleOutput()->artisan('app:import-from-whmcs', $options + ['dbname' => 'whmcs_copy', '--source-user' => 'reader',
                '--source-host' => 'db.example.test', '--confirm-database' => (string) DB::connection()->getDatabaseName()]);
            $said = (string) preg_replace('/\s+/', ' ', Artisan::output());
        } catch (Throwable) {
            // past the reset: the tiny source has not every table
        }

        return [$code, $said];
    }

    /** A row of this instance's own (a login and its client): gone once the import emptied the database. */
    private function mark(): int
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->client());

        return $user->id;
    }

    private function emptied(int $mark): bool
    {
        return !DB::table('users')->where('id', $mark)->exists();
    }

    /** A daily run of its own, as CronJob leaves it. */
    private function live(string $key = 'last_cron_run'): void
    {
        CronStat::create(['key' => 'invoices_created', 'value' => 1, 'date' => today()->toDateString()]);
        Setting::updateOrCreate(['key' => $key, 'settingable_type' => CronStat::class], ['value' => now()->toDateTimeString(), 'type' => 'string']);
    }

    public function test_a_rehearsal_instance_that_never_ran_its_cron_is_imported_and_imported_again_without_the_option(): void
    {
        $mark = $this->mark();
        $this->import();
        $this->assertTrue($this->emptied($mark), 'the first import');

        // That import ended (its mark removed); staff looked around, no cron ran. The same command again.
        DB::table('settings')->where('key', ImportFromWhmcs::RUNNING)->delete();
        $mark = $this->mark();
        $this->import();
        $this->assertTrue($this->emptied($mark), 'imported again freely');
    }

    public function test_an_import_that_failed_half_way_is_run_again_without_the_option(): void
    {
        $this->live();
        $this->import(['--replace-live-data' => true]);
        $this->assertNotNull(ImportFromWhmcs::running(), 'the tiny source stopped it half way');

        $mark = $this->mark();
        $this->import();

        $this->assertTrue($this->emptied($mark));
    }

    public function test_a_lived_instance_is_refused_with_what_was_found_and_the_way_through(): void
    {
        foreach ([CronJob::STARTED, CronJob::DUNNING_READY, 'last_cron_run'] as $trace) {
            DB::table('settings')->where('settingable_type', CronStat::class)->delete();
            DB::table('cron_stats')->delete();
            $this->live($trace);
            $mark = $this->mark();

            [$code, $said] = $this->import();

            $this->assertSame(1, $code, $trace);
            $this->assertFalse($this->emptied($mark), $trace);
            $this->assertStringContainsString('has run its daily cron since its last import (on 1 day(s) with counts, last at ' . now()->toDateTimeString() . ')', $said);
            $this->assertStringContainsString('--replace-live-data', $said);
            $this->assertStringContainsString('Nothing done', $said);
        }
    }

    /** Review of #811, REV-811-2: installed days before its cut-over, its scheduler ran the daily cron over nothing. */
    public function test_an_instance_with_no_client_is_not_lived_whatever_ran_over_it(): void
    {
        $this->live();
        $this->assertSame(0, DB::table('clients')->count());

        [$code, $said] = $this->import(['--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('--replace-live-data', $said);

        $this->import();
        $this->assertNotNull(ImportFromWhmcs::running(), 'its first import started');
    }

    public function test_with_the_option_a_lived_instance_is_replaced(): void
    {
        $this->freezeTime();
        $this->live();
        $mark = $this->mark();

        $this->import(['--replace-live-data' => true]);

        $this->assertTrue($this->emptied($mark));
        $this->assertSame(0, DB::table('cron_stats')->count());
    }

    /** The option alone is no confirmation: the database's name is still typed. */
    public function test_the_option_does_not_stand_for_the_databases_name(): void
    {
        $this->live();
        $mark = $this->mark();

        [$code] = $this->import(['--replace-live-data' => true, '--confirm-database' => 'another']);

        $this->assertSame(1, $code);
        $this->assertFalse($this->emptied($mark));
    }

    public function test_a_dry_run_on_a_lived_instance_empties_nothing_and_warns(): void
    {
        $this->live();
        $mark = $this->mark();

        [$code, $said] = $this->import(['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertFalse($this->emptied($mark));
        $this->assertSame(1, DB::table('cron_stats')->count());
        $this->assertStringContainsString('has run its daily cron since its last import', $said);
        $this->assertStringContainsString('would refuse to empty it without --replace-live-data', $said);
    }

    public function test_a_dry_run_on_an_instance_that_never_ran_its_cron_says_nothing_of_it(): void
    {
        [$code, $said] = $this->import(['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('--replace-live-data', $said);
    }
}
