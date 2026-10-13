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
        $this->assertTrue(BootFailure::mustFail(inConsole: false, command: null));
        foreach (['queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'app:cron-job'] as $command) {
            $this->assertTrue(BootFailure::mustFail(inConsole: true, command: $command), $command);
        }
    }

    public function test_console_commands_that_do_not_need_the_database_still_run(): void
    {
        foreach (['package:discover', 'config:cache', 'migrate', 'mycs:install', null] as $command) {
            $this->assertFalse(BootFailure::mustFail(inConsole: true, command: $command), (string) $command);
        }
    }

    public function test_a_failure_is_reported(): void
    {
        Exceptions::fake();

        BootFailure::handle(new RuntimeException('Connection refused'));

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_fresh_install_without_tables_is_not_a_failure(): void
    {
        Exceptions::fake();

        BootFailure::handle(new QueryException('mariadb', 'select * from settings', [], new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mycs.settings' doesn't exist")));

        Exceptions::assertNothingReported();
    }
}
