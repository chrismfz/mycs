<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Focused review of 92246c5..8a95af7 (Codex, docs/payments.md), two real processes on MariaDB/MySQL: a payment
 * arriving right after the reopening UPDATE of a cancelled invoice, before its cancellation credit note is voided.
 * The reviewer's single-process version ran the payment inside the reopening connection's query listener; this one
 * runs it in a second process, so the payment can wait for the reopening's locks.
 */
class CodexReopenPaymentRaceReviewTest extends TestCase
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

    /** Invoice 10.00 cancelled, then reopened while a payment of 10.00 arrives: paid and renewed once, no credit. */
    public function test_reopening_and_payment_are_atomic(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $user = User::factory()->create();
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);
        $service = Service::factory()->create([
            'client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15',
        ]);
        $invoice->fresh()->update(['status' => 'cancelled']); // admin «Cancel»: writes the cancellation credit note

        $this->race([
            // A: staff reopen; pauses right after the status UPDATE, before the observer voids the note.
            function (string $dir) use ($invoice) {
                $paused = false;
                DB::listen(function ($query) use (&$paused, $dir) {
                    if (!$paused && str_starts_with($query->sql, 'update `invoices`') && in_array('unpaid', $query->bindings, true)) {
                        $paused = true;
                        touch("$dir/reopen-updated");
                        $this->waitFor("$dir/payment-started");
                        usleep(300000); // the payment is now running (or waiting for the reopening's locks)
                    }
                });
                Invoice::query()->findOrFail($invoice->id)->update(['status' => 'unpaid']);
            },
            // B: the customer's payment, right after the UPDATE.
            function (string $dir) use ($invoice) {
                $this->waitFor("$dir/reopen-updated");
                touch("$dir/payment-started");
                ExtensionHelper::addPayment($invoice->id, 'Stripe', 10, transactionId: 'DURING-REOPEN', currency: 'USD');
            },
        ]);

        $invoice = $invoice->fresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0.0, $invoice->remaining);
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
        $this->assertEquals(0.0, (float) Credit::query()->where('client_id', $user->client()->id)->value('amount'), 'paid service plus spendable credit');
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
