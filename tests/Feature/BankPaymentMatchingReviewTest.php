<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Jobs\SendChatNotification;
use App\Models\BankMovement;
use App\Models\ChatNotificationRule;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\BankAlerts\PaymentMatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ForksWorkers;
use Tests\TestCase;
use Throwable;

/**
 * Independent review of #458 (automatic matching of bank credits to invoices). Each test fails on the PR as it stands
 * and names a defect. The lock-order one needs MariaDB and pcntl (skipped on SQLite): committed rows, hence truncation.
 */
class BankPaymentMatchingReviewTest extends TestCase
{
    use DatabaseTruncation;
    use ForksWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateDatabaseTables());
        Queue::fake([SendChatNotification::class]);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        // On since yesterday: the credits these tests make are read after it. Left to run() it is set to now(), at second
        // precision, and a credit made in the second before is not matched (flaky in CI, run 40318787917).
        Setting::query()->create(['key' => PaymentMatcher::SINCE_KEY, 'settingable_type' => BankMovement::class,
            'value' => now()->subDay()->toDateTimeString(), 'type' => 'string']);
    }

    private function invoice(?string $number, string $price, ?int $id = null): Invoice
    {
        $invoice = Invoice::factory()->create(array_filter(['id' => $id, 'client_id' => User::factory()->create()->client()->id, 'status' => 'unpaid',
            'currency_code' => 'EUR', 'due_at' => now()->addDays(7)], fn ($v) => $v !== null));
        DB::table('invoices')->where('id', $invoice->id)->update(['number' => $number]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function credit(string $reason, string $amount): BankMovement
    {
        return BankMovement::query()->create(['bank' => 'eurobank', 'direction' => 'credit', 'amount' => $amount, 'balance' => '1000.00',
            'reason' => $reason, 'executed_at' => now()->subHour(), 'message_key' => uniqid('', true), 'authentic' => true, 'status' => 'pending']);
    }

    /**
     * A payment already on its way (a card or SEPA charge still processing) is not counted in `remaining`, so a bank
     * credit for the full amount is matched and recorded too: the invoice is paid twice once the charge succeeds, and the
     * second payment silently becomes account credit that CreditAutoApply spends on other invoices. Payment reminders
     * already treat «a payment on its way» as not owed (PaymentReminders::open()); the matcher should leave it to staff.
     */
    public function test_an_invoice_with_a_payment_processing_is_left_for_staff(): void
    {
        $invoice = $this->invoice('32999', '150.00');
        $invoice->transactions()->create(['amount' => '150.00', 'status' => InvoiceTransactionStatus::Processing, 'transaction_id' => 'pi_on_its_way']);
        $credit = $this->credit('ΤΙΜΟΛΟΓΙΟ 32999', '150.00');

        PaymentMatcher::run();

        $this->assertSame(0, InvoiceTransaction::query()->where('transaction_id', 'bank-alert:' . $credit->id)->count(),
            'a second payment of an invoice whose payment is on its way: staff decide');
        $this->assertSame(BankMovement::PENDING, $credit->fresh()->status);
    }

    /**
     * A run of digits with leading zeros («0110», a group of an IBAN or a padded code) is compared with the integer id
     * column, so it names invoice 110. An id is matched only for an invoice without a number, which is exactly a nixpal
     * proforma: a reference quoting the payer's IBAN pays proforma #110 when the amounts agree.
     */
    public function test_a_digit_group_with_leading_zeros_never_names_an_invoice_by_its_id(): void
    {
        $proforma = $this->invoice(null, '45.00', id: 110);
        $credit = $this->credit('ΑΠΟ GR16 0110 1250 0000 0001 2300 695 ΣΥΝΔΡΟΜΗ', '45.00');

        PaymentMatcher::run();

        $this->assertSame(Invoice::STATUS_UNPAID, $proforma->fresh()->status, '«0110» is not invoice 110');
        $this->assertSame(BankMovement::PENDING, $credit->fresh()->status);
    }

    /**
     * Nixpal (proforma numbering): a proforma takes its number when it is paid. The chat message is built from the invoice
     * loaded before the payment, so staff are told «#<id>» while the invoice they open is «2026-…».
     */
    public function test_the_chat_names_the_number_the_proforma_took_when_paid(): void
    {
        config(['settings.invoice_proforma' => true, 'settings.invoice_number_is_id' => false]);
        ChatNotificationRule::query()->create(['name' => 'Billing', 'webhook_url' => 'https://chat.example.test/hooks/abcdefghijk', 'events' => ['payment_matched']]);
        $proforma = $this->invoice(null, '20.00', id: 7238);
        $this->credit('ΠΡΟΦΟΡΜΑ 7238', '20.00');

        PaymentMatcher::run();

        $number = (string) $proforma->fresh()->number;
        $this->assertNotSame('', $number, 'the paid proforma took a number');
        $told = Queue::pushed(SendChatNotification::class)->map(fn ($job) => $job->text)->implode("\n");
        $this->assertStringContainsString('#' . $number, $told);
    }

    /**
     * RecordPaymentService answers a transaction id it has seen on any invoice of the same customer with that earlier
     * transaction (a replay). The matcher only checks that it succeeded, so a credit put back to pending and naming
     * another invoice of the customer is marked «matched» to that invoice although nothing was paid on it.
     */
    public function test_a_replayed_transaction_on_another_invoice_is_not_reported_as_a_match(): void
    {
        $first = $this->invoice('32999', '30.00');
        $second = Invoice::factory()->create(['client_id' => $first->client_id, 'status' => 'unpaid', 'currency_code' => 'EUR', 'due_at' => now()->addDays(7)]);
        DB::table('invoices')->where('id', $second->id)->update(['number' => '33000']);
        $second->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '30.00']);
        $credit = $this->credit('32999', '30.00');
        PaymentMatcher::run();
        // Staff decide it paid 33000 instead: the reference fixed and the credit back to pending (invoice 32999 dealt with apart).
        DB::table('bank_movements')->where('id', $credit->id)->update(['status' => 'pending', 'invoice_id' => null, 'reason' => '33000']);

        PaymentMatcher::run();

        $credit->refresh();
        $this->assertFalse($credit->status === BankMovement::MATCHED && $credit->invoice_id === $second->id && $second->fresh()->status === Invoice::STATUS_UNPAID,
            'marked as having paid invoice 33000, which is still unpaid');
    }

    /**
     * Lock order. Every payment path locks the customer, then the invoice (RecordPaymentService::lockInvoice, PayInvoices,
     * MassPay, InvoiceLineMove). matchOne() locks the invoice first and only then calls addPayment, which locks the customer:
     * against any of those paths for the same customer it is an ABBA deadlock, and MariaDB kills one of the two (the
     * gateway webhook, a staff payment, or the match). Worker A is the matcher, held for a moment right after it locked
     * the invoice; worker B is a payment path that has the customer and wants the invoice.
     */
    public function test_the_matcher_takes_the_customer_before_the_invoice_as_every_payment_does(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Row-lock test requires MariaDB/MySQL and pcntl.');
        }
        $invoice = $this->invoice('32999', '150.00');
        $credit = $this->credit('32999', '150.00');
        $clientId = (int) $invoice->client_id;

        $this->race([
            function (string $dir) use ($credit) {
                $this->waitFor("$dir/b-has-client");
                $held = false;
                // Once the matcher's own `select … from invoices … for update` has run: it holds the invoice.
                DB::listen(function ($query) use (&$held, $dir) {
                    if (!$held && str_contains($query->sql, 'from `invoices`') && str_contains($query->sql, 'for update')) {
                        $held = true;
                        touch("$dir/a-has-invoice");
                        usleep(400_000);
                    }
                });
                PaymentMatcher::matchOne($credit->id);
            },
            function (string $dir) use ($invoice, $clientId) {
                DB::transaction(function () use ($dir, $invoice, $clientId) {
                    Client::lock($clientId);
                    touch("$dir/b-has-client");
                    // Until the matcher holds the invoice; a matcher that takes the customer first never gets there.
                    $deadline = microtime(true) + 2;
                    while (!file_exists("$dir/a-has-invoice") && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
                });
            },
        ]);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(BankMovement::MATCHED, $credit->fresh()->status);
    }

    private function waitFor(string $file): void
    {
        $deadline = microtime(true) + 10;
        while (!file_exists($file)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException("Timed out waiting for $file.");
            }
            usleep(1000);
        }
    }

    /** @param  list<callable(string): void>  $workers */
    private function race(array $workers): void
    {
        $dir = sys_get_temp_dir() . '/mycs-match-race-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $pids = [];
        DB::disconnect();
        try {
            foreach ($workers as $index => $worker) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork a worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
                        $worker($dir);
                        DB::disconnect();
                        self::endWorker($dir, $index, 0);
                    } catch (Throwable $e) {
                        file_put_contents("$dir/error-$index", $e->getMessage());
                        self::endWorker($dir, $index, 1);
                    }
                }
                $pids[] = $pid;
            }
            foreach ($pids as $index => $pid) {
                $this->assertTrue($this->awaitWorker($pid, $dir, $index),
                    file_exists("$dir/error-$index") ? file_get_contents("$dir/error-$index") : 'Worker failed.');
            }
        } finally {
            self::reapWorkers($pids);
            foreach (glob("$dir/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
