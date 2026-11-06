<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Mail\Mail;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Review of #531: with the due dates imported (AUD9-2), the first cron run must not send again what WHMCS sent already
 * (its «due soon» reminder, the overdue notices up to today), and still sends the ones ahead.
 */
class ImportReminderStateTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    private ImportFromWhmcs $command;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-29 09:00:00');
        Queue::fake();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec("INSERT INTO tblconfiguration VALUES ('SendInvoiceReminderDays', '6')");
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(int $id, string $date, string $due): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '{$date}', '{$due}', '0000-00-00 00:00:00', 'Unpaid', 10, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES ({$id}, {$this->clientId}, 'Item', 0, 'Line', 10, 0)");
    }

    private function import(): void
    {
        $this->command = $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        foreach (['importInvoices', 'importReminderState', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3,5']);
        MailFacade::fake();
    }

    private function sent(): int
    {
        return MailFacade::queued(Mail::class)->count();
    }

    public function test_the_first_run_sends_nothing_whmcs_sent_and_the_next_days_go_as_usual(): void
    {
        $this->invoice(601, '2026-10-17', '2026-11-01'); // due in 3 days: WHMCS's «due soon» went on 10-26
        $this->invoice(602, '2026-10-13', '2026-10-27'); // 2 days late: WHMCS's day-1 notice went yesterday
        $this->invoice(603, '2026-10-25', '2026-11-08'); // due in 10 days: nothing sent yet
        $this->import();

        $this->assertSame(0, (new PaymentReminders)->send(), 'nothing again on the first run');
        $this->assertSame(0, $this->sent());

        Carbon::setTestNow('2026-10-30 09:00:00'); // 602 is 3 days late: the day-3 notice, the cron's own
        $this->assertSame(1, (new PaymentReminders)->send());

        Carbon::setTestNow('2026-11-02 09:00:00'); // 603 due in 6 days; 601 a day late; 602 six days late
        $this->assertSame(3, (new PaymentReminders)->send(), '603 «due soon», 601 day 1 and 602 day 5');
    }

    private function whmcsRanDaily(string $ended): void
    {
        $this->whmcs->exec("INSERT INTO tblconfiguration VALUES ('lastDailyCronInvocationTime', '" . substr($ended, 0, 10) . " 09:00:02')");
        $this->whmcs->exec("INSERT INTO tblconfiguration VALUES ('lastDailyCronEndTime', '{$ended}')");
    }

    /**
     * Replay of nixpal, 2026-11-03: the dump came before WHMCS's 09:00 run, so that day's notices had not gone, and on the
     * cut-over day WHMCS's cron is off. Only what its last run sent counts as sent; the day's notices are the cron's.
     */
    public function test_a_dump_before_whmcss_daily_run_leaves_the_days_notices_to_the_cron(): void
    {
        Carbon::setTestNow('2026-10-29 02:21:00');
        $this->whmcsRanDaily('2026-10-28 09:00:26');
        $this->invoice(602, '2026-10-13', '2026-10-27'); // day 1 went on 10-28, day 2 is today's
        $this->invoice(604, '2026-10-20', '2026-10-28'); // due on WHMCS's last run: day 1 is today's
        $this->invoice(605, '2026-10-20', '2026-11-03'); // «due soon» went on 10-28 (6 days before)
        $this->invoice(606, '2026-10-20', '2026-11-04'); // «due soon» is today's
        $this->import();
        config(['settings.cronjob_overdue_reminders' => '1,2']);

        Carbon::setTestNow('2026-10-29 09:00:00');
        $this->assertSame(3, (new PaymentReminders)->send(), '602 day 2, 604 day 1, 606 «due soon»; not 602 day 1 nor 605');
        $this->assertSame(3, $this->sent());
        $this->assertSame(0, (new PaymentReminders)->send(), 'once');
        $this->assertSame(1, DB::table('invoice_reminders')->where(['invoice_id' => 602, 'kind' => 'overdue', 'days' => 2])->count());
    }

    /** After the day's run (a dump of the evening), the day's notices went: none again. */
    public function test_a_dump_after_whmcss_daily_run_counts_the_days_notices_as_sent(): void
    {
        Carbon::setTestNow('2026-10-29 18:00:00');
        $this->whmcsRanDaily('2026-10-29 09:00:35');
        $this->invoice(602, '2026-10-13', '2026-10-27');
        $this->invoice(604, '2026-10-20', '2026-10-28');
        $this->invoice(606, '2026-10-20', '2026-11-04');
        $this->import();
        config(['settings.cronjob_overdue_reminders' => '1,2']);

        $this->assertSame(0, (new PaymentReminders)->send());
    }

    /** A run time ahead of the importer's clock (another time zone, a wrong clock) is today, never later. */
    public function test_a_daily_run_after_today_counts_as_today(): void
    {
        $this->whmcsRanDaily('2026-11-05 09:00:26');
        $this->invoice(602, '2026-10-13', '2026-10-27');
        $this->invoice(607, '2026-10-20', '2026-11-08'); // 10 days ahead: its «due soon» is the cron's
        $this->import();
        config(['settings.cronjob_overdue_reminders' => '1,2']);

        $this->assertSame(0, (new PaymentReminders)->send(), '602 day 2 counted as sent, as without the setting');
        $this->assertSame(0, DB::table('invoice_reminders')->where('invoice_id', 607)->count());
    }

    /** @return list<int> the overdue days kept as sent for an invoice */
    private function overdueKept(int $invoice): array
    {
        return DB::table('invoice_reminders')->where(['invoice_id' => $invoice, 'kind' => 'overdue'])->orderBy('days')->pluck('days')->map(fn ($day) => (int) $day)->all();
    }

    /** Review of #610: no end time (a run that never ended, an older WHMCS), its start; neither usable, today. */
    public function test_without_an_end_time_the_start_counts_and_without_either_today(): void
    {
        $this->whmcs->exec("INSERT INTO tblconfiguration VALUES ('lastDailyCronEndTime', '')");
        $this->whmcs->exec("INSERT INTO tblconfiguration VALUES ('lastDailyCronInvocationTime', '2026-10-28 09:00:02')");
        $this->invoice(602, '2026-10-13', '2026-10-27');
        $this->import();
        $this->assertSame([1], $this->overdueKept(602), 'up to the start of 10-28');

        DB::table('invoice_reminders')->delete();
        $this->whmcs->exec("UPDATE tblconfiguration SET value = '0000-00-00 00:00:00' WHERE setting = 'lastDailyCronEndTime'");
        $this->whmcs->exec("UPDATE tblconfiguration SET value = 'never' WHERE setting = 'lastDailyCronInvocationTime'");
        (new ReflectionMethod(ImportFromWhmcs::class, 'importReminderState'))->invoke($this->command);
        $this->assertSame([1, 2], $this->overdueKept(602), 'up to today, as before');
    }

    /**
     * Review of #610: WHMCS last ran days before the import (its cron stopped early). The cron's first run sends the latest
     * day each invoice reached, once, never a day WHMCS sent.
     */
    public function test_a_last_run_days_old_sends_the_latest_day_reached_once(): void
    {
        $this->whmcsRanDaily('2026-10-26 09:00:26');
        $this->invoice(602, '2026-10-13', '2026-10-25'); // WHMCS sent day 1 on 10-26; day 2 (10-27) never went
        $this->invoice(608, '2026-10-20', '2026-10-27'); // due after WHMCS's last run: nothing went
        $this->import();
        config(['settings.cronjob_overdue_reminders' => '1,2']);
        $this->assertSame([1], $this->overdueKept(602));
        $this->assertSame([], $this->overdueKept(608));

        $this->assertSame(2, (new PaymentReminders)->send(), 'day 2 of each');
        $this->assertSame(0, (new PaymentReminders)->send());
        $this->assertSame([1, 2], $this->overdueKept(602));
        $this->assertSame([1, 2], $this->overdueKept(608), 'day 1 passed by, marked not sent');
    }
}
