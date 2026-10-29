<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Setting;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The company's automation comes with the import (pre-launch audit, flow 1, 2026-10-29): left at MyCS's defaults, the
 * cron would end myip's services at 60 days where WHMCS kept them to 120.
 */
class ImportAutomationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private BufferedOutput $output;

    /** @param array<string, string> $config */
    private function import(array $config): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $insert = $whmcs->prepare('INSERT INTO tblconfiguration VALUES (?, ?)');
        foreach ($config as $setting => $value) {
            $insert->execute([$setting, $value]);
        }
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        (new ReflectionMethod($command, 'importAutomationSettings'))->invoke($command);
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function settings(): array
    {
        return Setting::query()->whereNull('settingable_type')->where('key', 'like', 'cronjob_%')->get()
            ->mapWithKeys(fn (Setting $s) => [$s->key => [$s->getRawOriginal('value'), $s->type]])->all();
    }

    public function test_myips_automation_comes_with_its_termination_at_120_days(): void
    {
        $this->import(['AutoSuspension' => 'on', 'AutoSuspensionDays' => '15', 'AutoTermination' => 'on', 'AutoTerminationDays' => '120',
            'CreateInvoiceDaysBefore' => '14', 'DailyCronExecutionHour' => '10', 'CloseInactiveTickets' => '48']);

        $settings = $this->settings();
        $this->assertSame(['120', 'string'], $settings['cronjob_order_terminate']);
        $this->assertSame(['15', 'string'], $settings['cronjob_order_suspend']);
        $this->assertSame(['1', 'boolean'], $settings['cronjob_suspend_enabled']);
        $this->assertSame(['1', 'boolean'], $settings['cronjob_terminate_enabled']);
        $this->assertSame(['14', 'string'], $settings['cronjob_invoice']);
        $this->assertSame(['10:00', 'string'], $settings['cronjob_time']);
        $this->assertSame(['2', 'string'], $settings['cronjob_close_ticket'], '48 hours, 2 days');
    }

    public function test_nixpals_equal_the_defaults_and_a_switch_off_is_off(): void
    {
        $this->import(['AutoSuspension' => 'on', 'AutoSuspensionDays' => '8', 'AutoTermination' => '', 'AutoTerminationDays' => '60',
            'CreateInvoiceDaysBefore' => '7', 'DailyCronExecutionHour' => '09', 'CloseInactiveTickets' => '120']);

        $settings = $this->settings();
        $this->assertSame(['0', 'boolean'], $settings['cronjob_terminate_enabled'], 'WHMCS «off» is an empty value');
        $this->assertSame(['60', 'string'], $settings['cronjob_order_terminate']);
        $this->assertSame(['09:00', 'string'], $settings['cronjob_time']);
        $this->assertSame(['5', 'string'], $settings['cronjob_close_ticket'], '120 hours, 5 days');
    }

    public function test_a_value_missing_or_out_of_range_keeps_mycs_own(): void
    {
        $before = $this->settings();
        $this->import(['AutoTerminationDays' => 'ninety', 'DailyCronExecutionHour' => '03', 'CloseInactiveTickets' => '25']);

        $settings = $this->settings();
        $this->assertSame($before['cronjob_order_terminate'] ?? null, $settings['cronjob_order_terminate'] ?? null, 'not a number: kept');
        $this->assertSame($before['cronjob_time'] ?? null, $settings['cronjob_time'] ?? null, '03:xx never (the clock changes)');
        $this->assertSame(['2', 'string'], $settings['cronjob_close_ticket'], '25 hours are two days, not one');
        $this->assertStringContainsString('kept', $this->output->fetch());
    }
}
