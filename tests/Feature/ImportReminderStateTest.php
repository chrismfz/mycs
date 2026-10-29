<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Mail\Mail;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $command = app(ImportFromWhmcs::class);
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
}
