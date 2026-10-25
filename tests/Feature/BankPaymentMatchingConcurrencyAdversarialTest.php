<?php

namespace Tests\Feature;

use App\Jobs\SendChatNotification;
use App\Models\BankMovement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\BankAlerts\PaymentMatcher;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ForksWorkers;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #458, ADV-6: lock order. RecordPaymentService locks the customer, then the invoice (its docblock:
 * «every payment path of one customer is serialised»). PaymentMatcher locks the invoice first, then calls it, which then
 * asks for the customer: the opposite order. A staff «Add payment» (or a gateway webhook) on the same customer at the
 * same moment deadlocks with the cron; MariaDB kills one of them (error 1213), so the staff payment or the webhook fails.
 */
class BankPaymentMatchingConcurrencyAdversarialTest extends TestCase
{
    use DatabaseTruncation;
    use ForksWorkers;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Row-lock test requires MariaDB/MySQL.');
        }
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Row-lock test requires pcntl.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateDatabaseTables());
    }

    public function test_the_matcher_takes_the_customer_lock_before_the_invoice_as_every_payment_does(): void
    {
        Queue::fake([SendChatNotification::class]);
        $gateway = Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $clientId = User::factory()->create()->client()->id;
        $invoice = Invoice::factory()->create(['client_id' => $clientId, 'status' => 'unpaid', 'currency_code' => 'EUR', 'number' => '32999', 'due_at' => now()->addDays(7)]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '150.00']);
        $credit = BankMovement::query()->create(['bank' => 'eurobank', 'direction' => 'credit', 'amount' => '150.00', 'balance' => '1000.00',
            'reason' => '32999', 'executed_at' => now()->subHour(), 'message_key' => uniqid('', true), 'authentic' => true, 'status' => 'pending']);

        $this->race([
            // A: the cron. Once it holds the invoice's lock, it waits until staff hold the customer's.
            function (string $dir) use ($credit) {
                $signalled = false;
                Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($dir, &$signalled) {
                    if (!$signalled && str_contains($query->sql, 'from `invoices`') && str_contains($query->sql, 'for update')) {
                        $signalled = true;
                        touch("$dir/a-has-invoice");
                        $this->waitFor("$dir/b-has-client");
                        usleep(200_000);
                    }
                });
                PaymentMatcher::matchOne($credit->id);
            },
            // B: staff «Add payment» on the same customer: customer, then invoice (RecordPaymentService::lockInvoice).
            function (string $dir) use ($clientId, $invoice, $gateway) {
                DB::transaction(function () use ($dir, $clientId, $invoice, $gateway) {
                    Client::lock($clientId);
                    touch("$dir/b-has-client");
                    $this->waitFor("$dir/a-has-invoice");
                    (new RecordPaymentService)->handle($invoice->id, $gateway, '150.00', enteredByStaff: true);
                });
            },
        ]);

        // Both finished (no deadlock victim): the invoice paid once by one of them, the other's money as credit.
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertGreaterThanOrEqual(1, InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count());
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
        $dir = sys_get_temp_dir() . '/mycs-bank-match-race-' . bin2hex(random_bytes(8));
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
