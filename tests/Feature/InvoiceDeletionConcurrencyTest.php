<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ForksWorkers;
use Tests\TestCase;
use Throwable;

/** Real independent connections exercise both lock orders and a pre-existing REPEATABLE READ snapshot. */
class InvoiceDeletionConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use ForksWorkers;

    protected function beforeTruncatingDatabase(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Row-lock test requires MariaDB/MySQL and pcntl.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateDatabaseTables());
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Test item', 'price' => 10, 'quantity' => 1]);

        return $invoice;
    }

    public function test_payment_waits_until_invoice_deletion_finishes_without_orphan_credit(): void
    {
        $invoice = $this->invoice();
        $this->race([
            function (string $dir) use ($invoice) {
                Invoice::deleting(function ($deleting) use ($invoice, $dir) {
                    if ($deleting->id === $invoice->id) {
                        touch("$dir/delete-guard-passed");
                        $this->waitFor("$dir/payment-started");
                        usleep(300000);
                    }
                });
                Invoice::findOrFail($invoice->id)->delete();
            },
            function (string $dir) use ($invoice) {
                $this->waitFor("$dir/delete-guard-passed");
                touch("$dir/payment-started");
                try {
                    ExtensionHelper::addPayment($invoice->id, 'Stripe', 30, transactionId: 'DELETE-RACE', currency: 'USD');
                    throw new \RuntimeException('Payment must not commit while deletion holds the locks.');
                } catch (ModelNotFoundException) {
                    // Deletion won: there is no longer an invoice to receive a payment.
                }
            },
        ]);

        $this->assertNull($invoice->fresh());
        $this->assertSame(0, InvoiceTransaction::where('transaction_id', 'DELETE-RACE')->count());
        $this->assertSame(0, Credit::where('client_id', $invoice->client_id)->count());
    }

    public function test_deletion_waits_for_payment_then_refuses_to_cascade_its_transaction(): void
    {
        $invoice = $this->invoice();
        $this->race([
            function (string $dir) use ($invoice) {
                $paused = false;
                DB::listen(function ($query) use (&$paused, $dir) {
                    if (!$paused && str_starts_with($query->sql, 'insert into `invoice_transactions`')) {
                        $paused = true;
                        touch("$dir/payment-written");
                        $this->waitFor("$dir/delete-started");
                        usleep(300000);
                    }
                });
                ExtensionHelper::addPayment($invoice->id, 'Stripe', 30, transactionId: 'DELETE-RACE', currency: 'USD');
            },
            function (string $dir) use ($invoice) {
                $this->waitFor("$dir/payment-written");
                $deleting = Invoice::findOrFail($invoice->id);
                touch("$dir/delete-started");
                $this->assertDeletionRefused($deleting);
            },
        ]);

        $this->assertNotNull($invoice->fresh());
        $this->assertSame(1, InvoiceTransaction::where('transaction_id', 'DELETE-RACE')->count());
        $this->assertSame('20.00', Credit::where('client_id', $invoice->client_id)->sole()->amount);
    }

    public function test_deletion_guard_reads_payments_committed_after_its_transaction_snapshot(): void
    {
        $invoice = $this->invoice();
        $this->race([
            function (string $dir) use ($invoice) {
                DB::transaction(function () use ($dir, $invoice) {
                    $deleting = Invoice::findOrFail($invoice->id); // Establish the old snapshot.
                    touch("$dir/snapshot-open");
                    $this->waitFor("$dir/payment-committed");
                    $this->assertDeletionRefused($deleting);
                });
            },
            function (string $dir) use ($invoice) {
                $this->waitFor("$dir/snapshot-open");
                ExtensionHelper::addPayment($invoice->id, 'Stripe', 30, transactionId: 'DELETE-RACE', currency: 'USD');
                touch("$dir/payment-committed");
            },
        ]);

        $this->assertNotNull($invoice->fresh());
        $this->assertSame(1, InvoiceTransaction::where('transaction_id', 'DELETE-RACE')->count());
        $this->assertSame('20.00', Credit::where('client_id', $invoice->client_id)->sole()->amount);
    }

    private function assertDeletionRefused(Invoice $invoice): void
    {
        try {
            $invoice->delete();
            throw new \RuntimeException('An invoice with a committed payment must remain.');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('payments cannot be deleted', $e->getMessage());
        }
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
        $dir = sys_get_temp_dir() . '/mycs-payment-race-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $pids = [];
        DB::disconnect(); // Children must open independent DB connections, never share a PDO socket.
        try {
            foreach ($workers as $index => $worker) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork a payment worker.');
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
                    file_exists("$dir/error-$index") ? file_get_contents("$dir/error-$index") : 'Payment worker failed.');
            }
        } finally {
            foreach ($pids as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob("$dir/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
