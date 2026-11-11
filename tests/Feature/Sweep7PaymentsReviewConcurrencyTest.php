<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\S7RevRacePay\S7RevRacePay;
use Tests\Concerns\ForksWorkers;
use Tests\TestCase;
use Throwable;

/**
 * Review of PR #774 (S7-PAYMENTS-1), two real processes on MariaDB (REPEATABLE READ), as Sweep7ChargeConcurrencyTest.
 *
 * The PR made SavedMethodCharge::charge read nothing before the locks. The other side of the same hold still does: what
 * decides «a charge of this invoice is on its way» with plain reads (SavedMethodCharge::onItsWay) under a lockInvoice()
 * that was given the invoice's ID reads the owner first (a plain read: the snapshot), waits for the charge's locks, and
 * then does not see the charge's attempt.
 *
 * REV-774-1: credit auto-apply (CreditAutoApply::forCustomer → toInvoice($id) → payWithCredit($id): the cron's sweep, and
 *            after every overpayment, refund to credit or credit adjustment) pays the invoice from the balance while its
 *            card charge is on its way: 100.00 of credit spent and 100.00 taken from the card for one invoice of 100.00.
 * REV-774-2: staff's change of the invoice's customer (Invoice::save → lockInvoice($id) → InvoiceObserver's hold) moves
 *            the invoice to another account while the first account's card is being charged for it.
 */
class Sweep7PaymentsReviewConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use ForksWorkers;

    private Client $client;

    private Invoice $invoice;

    private BillingAgreement $agreement;

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
        Mail::fake();
        Queue::fake();
        if (!class_exists(S7RevRacePay::class)) {
            // Taken at the gateway; its payment comes later, by its webhook (Stripe). Each call is a line in the file.
            eval('namespace MyCS\\Extensions\\Gateways\\S7RevRacePay; class S7RevRacePay {
                public static string $calls = "";
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    file_put_contents(self::$calls, number_format((float) $amount, 2, ".", "") . "\n", FILE_APPEND | LOCK_EX);
                    return true;
                } }');
        }
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'S7RevRacePay'], ['name' => 'S7RevRacePay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
    }

    private function chargeOrRefused(): void
    {
        try {
            (new SavedMethodCharge)->charge($this->invoice->fresh(), $this->agreement->fresh(), ChargeAttempt::SOURCE_CRON);
        } catch (DisplayException) {
            // refused: the invoice is paid, or a payment for it is already on its way
        }
    }

    /** The charge's transaction, held open with its attempt written until the other worker is under way and waiting. */
    private function chargeHoldingItsLocks(string $dir): void
    {
        $held = false;
        DB::listen(function (QueryExecuted $query) use ($dir, &$held) {
            if (!$held && preg_match('/^insert into [`"]charge_attempts[`"]/i', $query->sql) === 1) {
                $held = true;
                touch("$dir/b-locked");
                $this->waitFor("$dir/a-started");
                usleep(700_000); // the other's first statements run, then it waits for these locks
            }
        });
        $this->chargeOrRefused();
    }

    /** The worker's first plain read inside a transaction (its snapshot), then the other worker runs to its end. */
    private function pauseAfterFirstPlainRead(string $dir): void
    {
        $seen = false;
        DB::listen(function (QueryExecuted $query) use ($dir, &$seen) {
            if ($seen || DB::transactionLevel() === 0 || preg_match('/^select/i', $query->sql) !== 1) {
                return;
            }
            $seen = true;
            touch("$dir/a-first-read");
            // A locking read first: this worker holds the lock, the other waits for it, and nothing is paused.
            if (preg_match('/for update|lock in share mode/i', $query->sql) !== 1) {
                $this->waitFor("$dir/b-done");
            }
        });
    }

    private function giveCredit(): void
    {
        (new CreditLedger)->add($this->client->id, 'USD', 10000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Review');
    }

    /**
     * REV-774-1. The cron charges the card of an invoice of 100.00 (attempt written, locks held); the credit sweep of the
     * other cron process (or the credit an overpayment left, applied after its commit) reaches the same invoice and waits
     * for the locks. After the charge's commit it must see the attempt («on its way») and leave the invoice alone.
     */
    public function test_rev_774_1_credit_that_waited_for_a_charges_locks_does_not_pay_its_invoice_too(): void
    {
        $this->giveCredit();

        $calls = $this->race([
            fn (string $dir) => $this->chargeHoldingItsLocks($dir),
            function (string $dir) {
                $this->waitFor("$dir/b-locked");
                touch("$dir/a-started");
                (new CreditAutoApply)->forCustomer($this->client->id, 'USD');
            },
        ]);

        $this->assertPaidOnce($calls);
    }

    /**
     * REV-774-1, the other interleaving: the credit's transaction made its first read (the invoice's owner, by its id)
     * and the whole charge committed before it took the locks.
     */
    public function test_rev_774_1_credit_whose_first_read_predates_a_charges_commit_does_not_pay_its_invoice_too(): void
    {
        $this->giveCredit();

        $calls = $this->race([
            function (string $dir) {
                $this->pauseAfterFirstPlainRead($dir);
                (new CreditAutoApply)->forCustomer($this->client->id, 'USD');
            },
            function (string $dir) {
                $this->waitFor("$dir/a-first-read");
                $this->chargeOrRefused();
                touch("$dir/b-done");
            },
        ]);

        $this->assertPaidOnce($calls);
    }

    /**
     * REV-774-2. Staff set another customer on the invoice (its edit page, the API) while the first customer's card is
     * being charged for it: the save waits for the charge's locks and must then be refused («a card payment of this
     * invoice is on its way», ADV-554-2), as it is when the charge committed a moment earlier.
     */
    public function test_rev_774_2_an_invoice_is_not_moved_to_another_customer_while_its_card_charge_holds_the_locks(): void
    {
        $other = User::factory()->create()->client();

        $calls = $this->race([
            fn (string $dir) => $this->chargeHoldingItsLocks($dir),
            function (string $dir) use ($other) {
                $this->waitFor("$dir/b-locked");
                $invoice = Invoice::query()->findOrFail($this->invoice->id);
                touch("$dir/a-started");
                try {
                    $invoice->update(['client_id' => $other->id]);
                } catch (DisplayException) {
                    // refused: a card payment of it is on its way
                }
            },
        ]);

        $this->assertSame(['100.00'], $calls, 'precondition: the first customer\'s card was charged 100.00');
        $this->assertSame($this->client->id, (int) Invoice::query()->whereKey($this->invoice->id)->value('client_id'),
            'the invoice went to another customer while the first customer\'s card was charged 100.00 for it');
    }

    /** @param  list<string>  $calls  what the gateway was charged */
    private function assertPaidOnce(array $calls): void
    {
        $credit = Invoice::query()->findOrFail($this->invoice->id)->transactions()->where('is_credit_transaction', true)->sum('amount');
        $balance = number_format((float) Credit::query()->where('client_id', $this->client->id)->value('amount'), 2, '.', '');
        $card = array_sum(array_map('floatval', $calls));

        $this->assertSame(100.0, round($card + (float) $credit, 2), sprintf(
            'one invoice of 100.00: the card was charged %s and the account credit paid %s (balance left %s)',
            number_format($card, 2, '.', ''), number_format((float) $credit, 2, '.', ''), $balance));
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
     * @return list<string> the amounts the gateway was charged
     */
    private function race(array $workers): array
    {
        $dir = sys_get_temp_dir() . '/mycs-s7rev-charge-race-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        S7RevRacePay::$calls = "$dir/gateway-calls";
        touch(S7RevRacePay::$calls);
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

            return array_values(array_filter(explode("\n", (string) file_get_contents(S7RevRacePay::$calls))));
        } finally {
            self::reapWorkers($pids);
            foreach (glob("$dir/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
