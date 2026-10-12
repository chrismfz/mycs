<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * PR 2a (docs/payments.md) with two real processes on MariaDB/MySQL. Under REPEATABLE READ a plain read sees the
 * snapshot of the transaction's first read, even after waiting for a lock: the money a payment computes with
 * must come from locking reads, or the second of two payments works on a balance that ignores the first.
 */
class PaymentConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

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

    /**
     * Invoice 100.00; two payments of 60.00. The second process took its snapshot before the first one
     * committed (an outer transaction, as in the cron or the credit page), then pays: 40 is applied, 20
     * becomes credit, the invoice is paid.
     */
    public function test_two_concurrent_payments_credit_exactly_the_excess(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100.00]);

        $this->race([
            // A: waits until B holds its snapshot, then pays and commits.
            function (string $dir) use ($invoice) {
                $this->waitFor("$dir/b-snapshot");
                ExtensionHelper::addPayment($invoice->id, 'Stripe', 60.00, null, 'pi_a', currency: 'usd');
                touch("$dir/a-done");
            },
            // B: an outer transaction reads first (its snapshot predates A), then pays after A committed.
            function (string $dir) use ($invoice) {
                DB::transaction(function () use ($dir, $invoice) {
                    InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count();
                    touch("$dir/b-snapshot");
                    $this->waitFor("$dir/a-done");
                    ExtensionHelper::addPayment($invoice->id, 'Stripe', 60.00, null, 'pi_b', currency: 'usd');
                });
            },
        ]);

        $invoice = $invoice->fresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0.0, $invoice->remaining);
        $this->assertSame('20.00', number_format((float) Credit::query()->where('user_id', $user->id)->value('amount'), 2, '.', ''));
        $this->assertEquals(20.00, (float) InvoiceTransaction::query()->sum('credited_amount'));
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
                        exit(0);
                    } catch (Throwable $e) {
                        file_put_contents("$dir/error-$index", $e->getMessage());
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
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
