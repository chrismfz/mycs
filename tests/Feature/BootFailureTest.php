<?php

namespace Tests\Feature;

use App\Support\BootFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Boot failures are no longer swallowed (audit sweep KR-8, EVT-12): a worker started during a database blip ran on
 * code defaults and without the gateways' listeners until it was restarted.
 */
class BootFailureTest extends TestCase
{
    public function test_web_requests_and_money_workers_fail_instead_of_running_on_defaults(): void
    {
        $this->assertTrue(BootFailure::mustFail(inConsole: false, downForMaintenance: false, command: null));
        foreach (['queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'app:cron-job'] as $command) {
            $this->assertTrue(BootFailure::mustFail(inConsole: true, downForMaintenance: false, command: $command), $command);
            $this->assertTrue(BootFailure::mustFail(inConsole: true, downForMaintenance: true, command: $command), "$command (down)");
        }
    }

    public function test_maintenance_mode_still_answers_503_and_other_commands_run(): void
    {
        $this->assertFalse(BootFailure::mustFail(inConsole: false, downForMaintenance: true, command: null));
        foreach (['package:discover', 'config:cache', 'migrate', 'mycs:install', 'down', null] as $command) {
            $this->assertFalse(BootFailure::mustFail(inConsole: true, downForMaintenance: false, command: $command), (string) $command);
        }
    }

    public function test_the_command_is_found_after_options(): void
    {
        $this->assertSame('queue:work', BootFailure::command(['artisan', 'queue:work', '--once']));
        $this->assertSame('queue:work', BootFailure::command(['artisan', '-v', 'queue:work', '--tries=3']));
        $this->assertSame('queue:work', BootFailure::command(['artisan', '--env=production', 'queue:work']));
        $this->assertNull(BootFailure::command(['artisan']));
    }

    public function test_a_failure_is_reported(): void
    {
        Exceptions::fake();

        BootFailure::handle(new RuntimeException('Invalid timezone'));
        BootFailure::handle(new QueryException('mariadb', 'select 1', [], new PDOException('SQLSTATE[HY000] [2002] Connection refused')));

        Exceptions::assertReported(RuntimeException::class);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_a_fresh_install_without_tables_is_not_a_failure(): void
    {
        Exceptions::fake();

        $missing = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mycs.settings' doesn't exist");
        $missing->errorInfo = ['42S02', 1146, "Table 'mycs.settings' doesn't exist"];
        BootFailure::handle(new QueryException('mariadb', 'select * from settings', [], $missing));
        BootFailure::handle(new QueryException('sqlite', 'select * from settings', [], new PDOException('SQLSTATE[HY000]: General error: 1 no such table: settings')));

        Exceptions::assertNothingReported();
    }

    public function test_a_table_missing_in_the_engine_is_reported(): void
    {
        Exceptions::fake();

        $corrupt = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1932 Table 'mycs.settings' doesn't exist in engine");
        $corrupt->errorInfo = ['42S02', 1932, "Table 'mycs.settings' doesn't exist in engine"];
        BootFailure::handle(new QueryException('mariadb', 'select * from settings', [], $corrupt));

        Exceptions::assertReported(QueryException::class);
    }
}
