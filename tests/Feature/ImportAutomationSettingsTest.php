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

    /** @var list<float> the source's customers' credit balances */
    private array $balances = [];

    /** @param array<string, string> $config */
    private function import(array $config): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $whmcs->exec('CREATE TABLE IF NOT EXISTS tblclients (id INTEGER PRIMARY KEY, credit REAL DEFAULT 0)');
        foreach ($this->balances as $credit) {
            $whmcs->exec('INSERT INTO tblclients (credit) VALUES (' . (float) $credit . ')');
        }
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

    /** Review of #518 (M2–M4): myip's reminders and credit come as WHMCS has them, the per-cycle days are said. */
    public function test_myips_reminders_and_credit_come_and_its_invoice_days_per_cycle_are_said(): void
    {
        $this->import(['SendReminder' => 'on', 'SendInvoiceReminderDays' => '1', 'SendFirstOverdueInvoiceReminder' => '1',
            'SendSecondOverdueInvoiceReminder' => '3', 'SendThirdOverdueInvoiceReminder' => '5', 'NoAutoApplyCredit' => 'on',
            'CreateInvoiceDaysBefore' => '14', 'CreateInvoiceDaysBeforeMonthly' => '10', 'CreateInvoiceDaysBeforeAnnually' => '20']);

        $settings = $this->settings() + Setting::query()->whereNull('settingable_type')->where('key', 'credits_auto_use')->get()
            ->mapWithKeys(fn (Setting $s) => [$s->key => [$s->getRawOriginal('value'), $s->type]])->all();
        $this->assertSame(['1', 'string'], $settings['cronjob_payment_reminder']);
        $this->assertSame(['1,3,5', 'string'], $settings['cronjob_overdue_reminders']);
        $this->assertSame(['0', 'boolean'], $settings['credits_auto_use'], 'WHMCS does not apply credit on its own: neither does MyCS');
        $output = $this->output->fetch();
        $this->assertStringContainsString('Monthly 10, Annually 20', $output);
    }

    /** Review of #518 (M3, L1–L3, L5): switches and values in their odd forms. */
    public function test_odd_values_and_switches(): void
    {
        $this->import(['SendReminder' => '', 'SendInvoiceReminderDays' => '6', 'AutoTermination' => 'On', 'AutoTerminationDays' => '99999999999999999999',
            'CloseInactiveTickets' => '', 'DailyCronExecutionHour' => '3', 'SendFirstOverdueInvoiceReminder' => '2', 'SendSecondOverdueInvoiceReminder' => '',
            'SendThirdOverdueInvoiceReminder' => '2']);

        $settings = $this->settings();
        $this->assertSame(['0', 'string'], $settings['cronjob_payment_reminder'], 'the reminder switched off');
        $this->assertSame(['1', 'boolean'], $settings['cronjob_terminate_enabled'], '«On» is on');
        $this->assertNotSame('99999999999999999999', $settings['cronjob_order_terminate'][0] ?? null, 'a «never» typed as a huge number is not a number of days');
        $this->assertSame(['0', 'string'], $settings['cronjob_close_ticket'], 'empty is never, as in WHMCS');
        $this->assertSame(['', 'string'], $settings['cronjob_overdue_reminders'], '«Payment Reminder Emails» off stops the overdue notices too');
        $this->assertStringContainsString('never 03:xx', $this->output->fetch());
    }

    /** Round 3 of #518: credit itself is on, so the auto-apply and the imported balances work; a reminder list kept to what the cron sends. */
    public function test_credit_is_on_where_whmcs_lets_customers_add_funds_or_one_has_a_balance(): void
    {
        $credits = fn () => Setting::query()->whereNull('settingable_type')->where('key', 'credits_enabled')->value('value');
        $this->import(['AddFundsEnabled' => 'on', 'NoAutoApplyCredit' => '']);
        $this->assertSame('1', $credits());

        $this->balances = [12.5];
        $this->import(['AddFundsEnabled' => '', 'SendReminder' => 'on', 'SendFirstOverdueInvoiceReminder' => '1',
            'SendSecondOverdueInvoiceReminder' => '400', 'SendThirdOverdueInvoiceReminder' => '5000']);
        $this->assertSame('1', $credits(), 'a customer\'s balance must stay spendable');
        $this->assertSame('1', Setting::query()->whereNull('settingable_type')->where('key', 'cronjob_overdue_reminders')->value('value'));
        $output = $this->output->fetch();
        $this->assertStringContainsString('400', $output);
        $this->assertStringContainsString('5000', $output);
    }
}
