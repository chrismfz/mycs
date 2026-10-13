<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\RenewServiceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class BillingCursorConcurrencyTest extends TestCase
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

    public function test_concurrent_callbacks_apply_each_line_once(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        try {
            foreach ([true, false] as $sameLine) {
                $product = $this->createProduct();
                $user = User::factory()->create();
                $service = Service::factory()->create([
                    'user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
                    'status' => 'active', 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10,
                ]);
                $items = [];
                for ($i = 0; $i < ($sameLine ? 1 : 2); $i++) {
                    // Stored paid, its line added while it was open (MT-1c: never created paid, a paid invoice keeps its lines).
                    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
                    $items[] = $invoice->items()->create([
                        'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Concurrent callback',
                        'price' => 10, 'quantity' => 1,
                    ])->id;
                    DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
                }
                $this->race($service->id, [$items[0], $items[$sameLine ? 0 : 1]]);
                $this->assertSame($sameLine ? '2026-11-15' : '2026-12-15', $service->fresh()->expires_at->toDateString());
                foreach ($items as $id) {
                    $this->assertNotNull(InvoiceItem::findOrFail($id)->applied_at);
                }
                if (!$sameLine) {
                    $lines = $service->livePeriodLines()->values();
                    $this->assertSame($lines[0]->period_end->toDateString(), $lines[1]->period_start->toDateString());
                }
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_staff_hold_survives_a_concurrent_payment(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        try {
            $product = $this->createProduct();
            $service = Service::factory()->create([
                'user_id' => User::factory()->create()->id, 'product_id' => $product->product->id,
                'plan_id' => $product->plan->id, 'status' => 'suspended', 'dunning_suspended_at' => now(),
                'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10,
            ]);
            $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD']);
            $item = $invoice->items()->create([
                'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Concurrent staff hold',
                'price' => 10, 'quantity' => 1, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15',
            ]);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']); // stored paid (MT-1c)
            $this->race($service->id, [$item->id, $item->id], staffHold: true);
            $service->refresh();
            $this->assertSame('2026-11-15', $service->expires_at->toDateString());
            $this->assertSame('suspended', $service->status);
            $this->assertNull($service->dunning_suspended_at);
            $this->assertNotNull($item->fresh()->applied_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function race(int $serviceId, array $itemIds, bool $staffHold = false): void
    {
        $dir = sys_get_temp_dir() . '/mycs-billing-race-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $pids = [];
        DB::disconnect(); // Children must open independent DB connections, never share a PDO socket.
        try {
            foreach ($itemIds as $index => $itemId) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork a billing callback.');
                }
                if ($pid === 0) {
                    try {
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
                        touch("$dir/ready-$index");
                        $deadline = microtime(true) + 10;
                        while (!file_exists("$dir/start")) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Callback barrier timed out.');
                            }
                            usleep(1000);
                        }
                        if ($staffHold && $index === 1) {
                            Service::findOrFail($serviceId)->placeOnHold(); // staff's explicit suspend
                        } else {
                            (new RenewServiceService)->handle(Service::findOrFail($serviceId), InvoiceItem::findOrFail($itemId));
                        }
                        DB::disconnect();
                        exit(0);
                    } catch (Throwable $e) {
                        file_put_contents("$dir/error-$index", $e->getMessage());
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            $deadline = microtime(true) + 10;
            while (!file_exists("$dir/ready-0") || !file_exists("$dir/ready-1")) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Callbacks did not reach the barrier.');
                }
                usleep(1000);
            }
            touch("$dir/start");
            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                    file_exists("$dir/error-$index") ? file_get_contents("$dir/error-$index") : 'Callback failed.');
            }
        } finally {
            foreach ($pids as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob("$dir/*") as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
