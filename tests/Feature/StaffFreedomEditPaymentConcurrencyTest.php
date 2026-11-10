<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ForksWorkers;
use Tests\TestCase;
use Throwable;

/**
 * «Edit» of a payment's amount (delete + record again, EditPayment) racing the gateway's success event for the same
 * transaction id, with two real processes on MariaDB (REPEATABLE READ). Staff typed the gateway's id with a wrong amount
 * (60.00 of a 100.00 charge) and correct it while the webhook of that charge arrives. Whichever goes first, the id is
 * one succeeded payment of 100.00: the webhook is a replay of the row as it is after the edit's commit, never a second
 * payment recorded in the gap between the deletion and the new row.
 */
class StaffFreedomEditPaymentConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use ForksWorkers;

    private Invoice $invoice;

    private InvoiceTransaction $payment;

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
        $this->storeSettings(['credits_enabled' => true]);
        $client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        $this->payment = (new RecordPaymentService)->recordByStaff($this->invoice, $gateway, '60.00', 'ch_race');
    }

    private function edit(string $dir): void
    {
        try {
            (new EditPayment)->handle($this->payment, ['amount' => '100.00']);
        } catch (DisplayException $e) {
            file_put_contents("$dir/edit-refused", $e->getMessage());
        }
    }

    private function webhook(): void
    {
        ExtensionHelper::addPayment($this->invoice->id, 'Stripe', 100.00, null, 'ch_race', currency: 'USD');
    }

    /** The edit deleted the row and has not recorded it again yet, under its locks, when the webhook arrives and waits. */
    public function test_a_webhook_that_arrives_between_the_edits_deletion_and_its_new_row_is_a_replay(): void
    {
        $this->race([
            function (string $dir) {
                $held = false;
                DB::listen(function (QueryExecuted $query) use ($dir, &$held) {
                    if (!$held && preg_match('/^delete from [`"]invoice_transactions[`"]/i', $query->sql) === 1) {
                        $held = true;
                        touch("$dir/edit-deleted");
                        $this->waitFor("$dir/webhook-started");
                        usleep(700_000); // the webhook's first statements run, then it waits for these locks
                    }
                });
                $this->edit($dir);
            },
            function (string $dir) {
                $this->waitFor("$dir/edit-deleted");
                touch("$dir/webhook-started");
                $this->webhook();
            },
        ]);

        $this->assertOnePaymentOfTheCharge();
    }

    /**
     * The edit's transaction made its first read, and the webhook's whole request commits before the edit takes its
     * locks (when that first read is a plain one; a locking one makes the webhook wait instead). Either way one payment.
     */
    public function test_an_edit_whose_first_read_predates_the_webhooks_commit_still_leaves_one_payment(): void
    {
        $this->race([
            function (string $dir) {
                $seen = false;
                DB::listen(function (QueryExecuted $query) use ($dir, &$seen) {
                    if ($seen || DB::transactionLevel() === 0 || preg_match('/^select/i', $query->sql) !== 1) {
                        return;
                    }
                    $seen = true;
                    touch("$dir/edit-first-read");
                    if (preg_match('/for update|lock in share mode/i', $query->sql) !== 1) {
                        $this->waitFor("$dir/webhook-done");
                    }
                });
                $this->edit($dir);
            },
            function (string $dir) {
                $this->waitFor("$dir/edit-first-read");
                $this->webhook();
                touch("$dir/webhook-done");
            },
        ]);

        $this->assertOnePaymentOfTheCharge();
    }

    private function assertOnePaymentOfTheCharge(): void
    {
        $rows = InvoiceTransaction::query()->where('transaction_id', 'ch_race')->get();
        $this->assertCount(1, $rows, 'the gateway\'s id is recorded ' . $rows->count() . ' times');
        $this->assertSame([InvoiceTransactionStatus::Succeeded, '100.00', '0.00'], [$rows[0]->status, (string) $rows[0]->amount, (string) $rows[0]->credited_amount]);
        $invoice = $this->invoice->fresh();
        $this->assertSame([Invoice::STATUS_PAID, 0, 10000], [$invoice->status, RecordPaymentService::toCents($invoice->remaining), $invoice->moneyKeptCents()]);
        $this->assertSame(0, RecordPaymentService::toCents(Credit::query()->where('client_id', $invoice->client_id)->value('amount') ?? 0), 'nothing became credit');
        $rules = array_values(array_diff(MoneyCheck::RULES, ['paid_not_applied', 'gateway_received']));
        $this->assertSame([], (new MoneyCheck)->run($rules));
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
        $dir = sys_get_temp_dir() . '/mycs-edit-payment-race-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $pids = [];
        DB::disconnect(); // Children must open independent DB connections, never share a PDO socket.
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
                        file_put_contents("$dir/error-$index", get_class($e) . ': ' . $e->getMessage());
                        self::endWorker($dir, $index, 1);
                    }
                }
                $pids[] = $pid;
            }
            foreach ($pids as $index => $pid) {
                $this->assertTrue($this->awaitWorker($pid, $dir, $index),
                    file_exists("$dir/error-$index") ? (string) file_get_contents("$dir/error-$index") : 'Worker failed.');
            }
            $this->assertFileDoesNotExist("$dir/edit-refused", file_exists("$dir/edit-refused") ? (string) file_get_contents("$dir/edit-refused") : '');
        } finally {
            self::reapWorkers($pids);
            foreach (glob("$dir/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
