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
 * Follow-up review of PR 2a (Codex, docs/payments.md), two real processes on MariaDB/MySQL: a refund and a payment
 * of the same invoice must take their locks in the same order (customer, invoice, transaction), or one of them
 * dies in a deadlock, possibly after the gateway already returned the money.
 */
class CodexRefundPaymentRaceReviewTest extends TestCase
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
     * Invoice 100.00 with 60.00 paid. A payment of 10.00 holds the invoice while a refund of 10.00 of the first
     * payment starts: both must finish, with the refund applied after the payment.
     *
     * As first written, the refund worker locked the transaction row itself in an outer transaction before calling
     * refundLocally(), which reproduced the helper's old order (transaction first). The helper now takes the
     * customer and the invoice first, like every payment, so the worker calls it as the admin does: no outer lock.
     */
    public function test_refund_and_payment_do_not_deadlock_with_opposite_lock_order(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        $txn = ExtensionHelper::addPayment($invoice->id, 'Stripe', 60, transactionId: 'INITIAL', currency: 'USD');
        $this->race([
            function (string $dir) use ($invoice) {
                $paused = false;
                DB::listen(function ($query) use (&$paused, $dir) {
                    if (!$paused && str_starts_with($query->sql, 'select * from `invoices`') && str_contains($query->sql, 'for update')) {
                        $paused = true;
                        touch("$dir/payment-has-invoice");
                        $this->waitFor("$dir/refund-started");
                        usleep(300000); // the refund is now inside refundLocally(), waiting for its first lock
                    }
                });
                ExtensionHelper::addPayment($invoice->id, 'Stripe', 10, transactionId: 'NEXT', currency: 'USD');
            },
            function (string $dir) use ($txn) {
                $this->waitFor("$dir/payment-has-invoice");
                touch("$dir/refund-started");
                ExtensionHelper::refundLocally($txn, 10);
            },
        ]);

        $this->assertEquals(10.00, (float) $txn->fresh()->refunded_amount);
        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'NEXT')->count());
        // MT-1c: a refund on an open invoice is not forgiven (no refund note): 100 still due, 60 - 10 + 10 paid.
        $this->assertEquals(40.00, $invoice->fresh()->remaining, '100 due; 60 - 10 refunded + 10 paid');
        $this->assertEquals(0.0, (float) Credit::query()->where('user_id', $user->id)->value('amount'));
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
