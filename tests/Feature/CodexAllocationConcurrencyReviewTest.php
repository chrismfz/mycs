<?php

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;
use Throwable;

/**
 * Focused review of 92246c5..8a95af7 (Codex, docs/payments.md), two real processes on MariaDB/MySQL: the same
 * PayPal subscription webhook delivered twice at once records one sale and pays one cycle per service, once.
 */
class CodexAllocationConcurrencyReviewTest extends TestCase
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

    /** Two services, two open cycles each, sale 30.00 delivered twice at once. */
    public function test_concurrent_subscription_webhooks_allocate_one_cycle_once(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $invoices = [];
        foreach (range(1, 2) as $n) {
            $service = Service::factory()->create([
                'client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'status' => 'active', 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10,
                'subscription_id' => 'CONCURRENT-SUB',
            ]);
            foreach (['2026-10-15', '2026-11-15'] as $start) {
                $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
                $invoice->forceFill(['created_at' => $start])->save();
                $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class,
                    'description' => 'Renewal', 'quantity' => 1, 'price' => 10,
                    'period_start' => $start, 'period_end' => CarbonImmutable::parse($start)->addMonth()->toDateString()]);
                $invoices[] = $invoice;
            }
        }
        $resource = ['id' => 'CONCURRENT-SALE', 'billing_agreement_id' => 'CONCURRENT-SUB', 'amount' => ['total' => '30.00', 'currency' => 'USD']];
        $worker = function (string $dir) use ($resource) {
            touch($dir . '/ready-' . getmypid());
            $deadline = microtime(true) + 10;
            while (count(glob($dir . '/ready-*')) < 2) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Worker barrier timed out');
                }
                usleep(1000);
            }
            (new PayPal([]))->recordSubscriptionSale($resource);
        };
        $this->race([$worker, $worker]);
        $this->assertSame(1, InvoiceTransaction::where('transaction_id', 'CONCURRENT-SALE')->count());
        $this->assertSame(1, InvoiceTransaction::whereNotNull('source_transaction_id')->count());
        $this->assertSame('10.00', Credit::where('client_id', $user->client()->id)->value('amount'));
        $this->assertSame(['paid', 'unpaid', 'paid', 'unpaid'], array_map(fn ($invoice) => $invoice->fresh()->status, $invoices));
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
