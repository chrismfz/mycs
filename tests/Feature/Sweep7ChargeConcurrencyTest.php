<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\SplitInvoice;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use MyCS\Extensions\Gateways\S7RacePay\S7RacePay;
use Tests\Concerns\ForksWorkers;
use Tests\TestCase;
use Throwable;

/**
 * S7-PAYMENTS-1, with two real processes on MariaDB (REPEATABLE READ): two charges of one invoice whose transactions
 * overlap. The hold («a payment for this invoice is already on its way») is read with plain reads; when the transaction's
 * snapshot was taken before the other charge committed (a plain read before the locks), the second charge does not see
 * the first one's attempt and the gateway is charged twice. The gateway here counts its calls in a file, as two
 * processes share nothing else.
 */
class Sweep7ChargeConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use ForksWorkers;

    private Invoice $invoice;

    private BillingAgreement $agreement;

    /** @var array<string, string> */
    private array $kept = [];

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
        if (!class_exists(S7RacePay::class)) {
            // Taken at the gateway; its payment comes later, by its webhook (Stripe). Each call is a line in the file.
            eval('namespace MyCS\\Extensions\\Gateways\\S7RacePay; class S7RacePay {
                public static string $calls = "";
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    file_put_contents(self::$calls, number_format((float) $amount, 2, ".", "") . "\n", FILE_APPEND | LOCK_EX);
                    return true;
                } }');
        }
        $client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'S7RacePay'], ['name' => 'S7RacePay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
    }

    private function chargeOrRefused(): void
    {
        try {
            (new SavedMethodCharge)->charge($this->invoice->fresh(), $this->agreement->fresh(), ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
            // refused: a payment for it is already on its way (what the second one must get)
        }
    }

    /**
     * A arrives while B holds the invoice's locks (B wrote its attempt and has not committed): A waits for them. What A
     * read before waiting must not decide: after B's commit A sees B's attempt and sends nothing.
     */
    public function test_a_charge_that_waited_for_another_ones_locks_sees_its_attempt(): void
    {
        $calls = $this->race([
            // B: holds the locks with its attempt written, until A is under way (and so waiting for them).
            function (string $dir) {
                $held = false;
                DB::listen(function (QueryExecuted $query) use ($dir, &$held) {
                    if (!$held && preg_match('/^insert into [`"]charge_attempts[`"]/i', $query->sql) === 1) {
                        $held = true;
                        touch("$dir/b-locked");
                        $this->waitFor("$dir/a-started");
                        usleep(700_000); // A's first statements run, then it waits for B's locks
                    }
                });
                $this->chargeOrRefused();
            },
            function (string $dir) {
                $this->waitFor("$dir/b-locked");
                touch("$dir/a-started");
                $this->chargeOrRefused();
            },
        ]);

        $this->assertOneCharge($calls);
    }

    /**
     * A's transaction made its first plain read (its snapshot, when there is one before the locks) and then B's whole
     * charge commits before A takes the locks. With no plain read before the locks, A simply goes first and B is refused.
     */
    public function test_a_charge_whose_first_read_predates_another_ones_commit_sees_its_attempt(): void
    {
        $calls = $this->race([
            function (string $dir) {
                $seen = false;
                DB::listen(function (QueryExecuted $query) use ($dir, &$seen) {
                    if ($seen || DB::transactionLevel() === 0 || preg_match('/^select/i', $query->sql) !== 1) {
                        return;
                    }
                    $seen = true;
                    touch("$dir/a-first-read");
                    // A plain read first: the snapshot is taken, no lock is held, so B can run to its end now. A locking
                    // read first: A holds the lock, B waits for it, and nothing is paused.
                    if (preg_match('/for update|lock in share mode/i', $query->sql) !== 1) {
                        $this->waitFor("$dir/b-done");
                    }
                });
                $this->chargeOrRefused();
            },
            function (string $dir) {
                $this->waitFor("$dir/a-first-read");
                $this->chargeOrRefused();
                touch("$dir/b-done");
            },
        ]);

        $this->assertOneCharge($calls);
    }

    /**
     * REV-774: staff split the invoice (as a merge: InvoiceLineMove::lock reads the owners by id first, a plain read, then
     * waits for the locks) while its card is being charged for its whole amount. After the charge's commit the split must
     * see the attempt and be refused: moved, the line's new invoice would be charged again and the late payment would
     * land as credit.
     */
    public function test_a_split_that_waited_for_a_charges_locks_is_refused(): void
    {
        $second = $this->invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '20.00']);

        $calls = $this->race([
            function (string $dir) {
                $held = false;
                DB::listen(function (QueryExecuted $query) use ($dir, &$held) {
                    if (!$held && preg_match('/^insert into [`"]charge_attempts[`"]/i', $query->sql) === 1) {
                        $held = true;
                        touch("$dir/b-locked");
                        $this->waitFor("$dir/a-started");
                        usleep(700_000); // the split's first statements run, then it waits for these locks
                    }
                });
                $this->chargeOrRefused();
            },
            function (string $dir) use ($second) {
                $this->waitFor("$dir/b-locked");
                touch("$dir/a-started");
                try {
                    (new SplitInvoice)->handle($this->invoice->id, [$second->id]);
                    touch("$dir/split-done");
                } catch (DisplayException $e) {
                    file_put_contents("$dir/split-refused", $e->getMessage());
                }
            },
        ], keep: ['split-done', 'split-refused']);

        $this->assertSame(['120.00'], $calls, 'precondition: the card was charged for both lines');
        $this->assertArrayNotHasKey('split-done', $this->kept, 'the invoice was split while its card was charged 120.00 for it');
        $this->assertStringContainsString('card charge on its way', $this->kept['split-refused'] ?? '');
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(2, $this->invoice->items()->count());
    }

    /** @param  list<string>  $calls */
    private function assertOneCharge(array $calls): void
    {
        $this->assertSame(['100.00'], $calls, 'the gateway was charged ' . count($calls) . ' times for one invoice of 100.00');
        $this->assertSame(1, ChargeAttempt::query()->where('invoice_id', $this->invoice->id)->count(), 'one attempt, one key');
        $this->assertTrue(SavedMethodCharge::onItsWay($this->invoice->fresh()));
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

    /**
     * @param  list<callable(string): void>  $workers
     * @param  list<string>  $keep  files the workers left that the test reads (into $this->kept)
     * @return list<string> the amounts the gateway was charged
     */
    private function race(array $workers, array $keep = []): array
    {
        $this->kept = [];
        $dir = sys_get_temp_dir() . '/mycs-s7-charge-race-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        S7RacePay::$calls = "$dir/gateway-calls";
        touch(S7RacePay::$calls);
        $pids = [];
        DB::disconnect(); // Children must open independent DB connections, never share a PDO socket.
        try {
            foreach ($workers as $index => $worker) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork a charge worker.');
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
                    file_exists("$dir/error-$index") ? (string) file_get_contents("$dir/error-$index") : 'Charge worker failed.');
            }

            foreach ($keep as $file) {
                if (file_exists("$dir/$file")) {
                    $this->kept[$file] = (string) file_get_contents("$dir/$file");
                }
            }

            return array_values(array_filter(explode("\n", (string) file_get_contents(S7RacePay::$calls))));
        } finally {
            self::reapWorkers($pids);
            foreach (glob("$dir/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
