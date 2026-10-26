<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Ekdosi\EkdosiPayments;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ForksWorkers;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #464 (E3, op=add_payment of the Ekdosi bridge). Written without the author's context.
 *
 * test_adv_* fail on the PR's head: each is a break. test_held_* pass: they pin what held.
 * The race tests (two real processes) need MariaDB/MySQL and pcntl, and skip on SQLite; so do the collation ones.
 */
class EkdosiAddPaymentAdversarialTest extends TestCase
{
    use DatabaseTruncation;
    use ForksWorkers;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateDatabaseTables());
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test']);
        Gateway::query()->firstOrCreate(['extension' => 'BankTransfer'], ['name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
        $this->client = User::factory()->create()->client();
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json',
        ]), $raw);
    }

    private function onCredit(string $price = '124.00', array $attributes = [], ?Client $client = null): Invoice
    {
        $invoice = Invoice::factory()->create($attributes + ['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
        EkdosiDocument::query()->create(['invoice_id' => $invoice->id, 'mark' => (string) (400001234560000 + $invoice->id), 'code' => 'ΤΙΜ' . $invoice->id]);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, string $amount, string $transid): TestResponse
    {
        return $this->bridge(['op' => 'add_payment', 'invoice_id' => $invoice->id, 'amount' => $amount, 'transid' => $transid]);
    }

    private function paid(Invoice $invoice): string
    {
        return number_format((float) InvoiceTransaction::query()->where('invoice_id', $invoice->id)
            ->where('status', InvoiceTransactionStatus::Succeeded)->sum('amount'), 2, '.', '');
    }

    private function requireRowLocks(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MariaDB/MySQL (row locks, collation).');
        }
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Breaks
    // ----------------------------------------------------------------------------------------------------------------

    /**
     * ADV-1. Ekdosi says it received 500.00 for an invoice that owed 100.00 (or one already paid here). MyCS records
     * 100.00 (or nothing), answers 200 «ok», and the other 400.00 (or all 500.00) of the customer's money is in no
     * record of MyCS: no transaction, no credit, no log line, no staff mail. Every other path that cannot place money
     * (RecordPaymentService::notify) tells staff «Payment needs attention». The cap is the plugin's rule, but the
     * silence makes a double payment (Ekdosi and a gateway) invisible here until the customer complains.
     */
    public function test_adv_1_money_cut_off_by_the_cap_or_already_settled_is_never_told_to_staff(): void
    {
        Mail::fake();
        config(['settings.system_email_address' => 'billing@example.test', 'settings.mail_disable' => false]);
        $capped = $this->onCredit();
        ExtensionHelper::addPayment($capped, 'BankTransfer', '24.00', transactionId: 'bank-part');
        $settled = $this->onCredit();
        ExtensionHelper::addPayment($settled, 'BankTransfer', '124.00', transactionId: 'bank-full');
        // The way every other path tells staff about money it could not place: it reaches the email log here.
        ExtensionHelper::addPayment($capped, 'BankTransfer', '0', transactionId: 'probe-0');
        $this->assertSame(1, EmailLog::query()->where('subject', 'like', '%attention%')->count(), 'the probe reached the email log');
        EmailLog::query()->delete();

        $this->pay($capped, '500.00', 'over-1')->assertOk()->assertJsonPath('capped', true)->assertJsonPath('amount', '100.00');
        $this->pay($settled, '124.00', 'twice-1')->assertOk()->assertJsonPath('note', 'already_settled');

        $this->assertSame('0.00', number_format((float) Credit::query()->sum('amount'), 2, '.', ''), 'never credit: that part held');
        $told = EmailLog::query()->where('subject', 'like', '%attention%')->pluck('body');
        $this->assertCount(2, $told, 'staff are told of the 400.00 cut off (invoice #' . $capped->id . ') and of the 124.00 for the paid invoice #' . $settled->id);
    }

    /**
     * ADV-2. The replay check matches any transaction with the id `ekdosi:<transid>`, whatever its status or gateway.
     * A failed (or processing) one with that id answers «already_recorded» with 200: Ekdosi marks its payment as synced,
     * while nothing was paid here. Only a succeeded transaction is the same payment (RecordPaymentService lets a
     * failed/processing one be completed).
     */
    public function test_adv_2_a_failed_transaction_with_the_same_id_makes_the_payment_vanish(): void
    {
        $invoice = $this->onCredit();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '124.00', transactionId: 'ekdosi:pay-9', status: InvoiceTransactionStatus::Failed);

        // Fix of #464 round 2 (author): this fixture's transaction is a BankTransfer's — never taken over (R2-1). What
        // ADV-2 asked holds: no false «already_recorded», a 409 the operator sees.
        $this->pay($invoice, '124.00', 'pay-9')->assertStatus(409)->assertJsonPath('error', 'transid_conflict');
        // Ekdosi's own failed one (no gateway) is completed.
        $own = $this->onCredit();
        ExtensionHelper::addPayment($own, null, '124.00', transactionId: 'ekdosi:pay-10', status: InvoiceTransactionStatus::Failed);
        $response = $this->pay($own, '124.00', 'pay-10');
        $this->assertSame(Invoice::STATUS_PAID, $own->fresh()->status, 'answered ' . $response->getContent() . ' but the invoice is still unpaid');
    }

    /**
     * ADV-3. On MariaDB the column's collation (utf8mb4_uca1400_ai_ci) is case- and accent-insensitive: two different
     * Ekdosi payments `ABC-1` and `abc-1` (or `café` and `cafe`) are one. The second answers 200 «already_recorded» and
     * is lost. Compare transids in binary (`whereRaw('BINARY transaction_id = ?')` / a `_bin` column), or refuse such
     * a near-duplicate with a 409 instead of claiming it was recorded.
     */
    public function test_adv_3_transids_differing_only_in_case_or_accents_are_one_payment_on_mariadb(): void
    {
        $this->requireRowLocks();
        $invoice = $this->onCredit();

        // Fix of #464 (author), the reviewer's own alternative: a near-identical transid is refused (409), never taken
        // for the same payment and lost behind «already_recorded». Ekdosi's transids are «ekdosi-paid:<n>».
        $this->pay($invoice, '24.00', 'ABC-1')->assertOk()->assertJsonPath('amount', '24.00');
        $this->pay($invoice, '30.00', 'abc-1')->assertStatus(409)->assertJsonPath('error', 'transid_conflict');
        $this->pay($invoice, '10.00', 'café')->assertOk()->assertJsonPath('amount', '10.00');
        $this->pay($invoice, '10.00', 'cafe')->assertStatus(409)->assertJsonPath('error', 'transid_conflict');

        $this->assertSame('34.00', $this->paid($invoice), 'never two payments taken for one, nor one lost silently');
    }

    /**
     * ADV-4. `invoice_id` goes through filter_var(FILTER_VALIDATE_INT), which takes JSON `true` as 1 and `1.0` as 1:
     * a type confusion that pays invoice #1. The contract says <int>: anything but an integer is a 400.
     */
    public function test_adv_4_invoice_id_true_or_a_float_pays_invoice_number_one(): void
    {
        $one = $this->onCredit('124.00', ['id' => 1]);

        $this->bridge(['op' => 'add_payment', 'invoice_id' => true, 'amount' => '10.00', 'transid' => 'bool'])->assertStatus(400);
        // Fix of #464 (author): PHP's json_encode writes 1.0 as `1` — on the wire a plain integer, rightly invoice 1. A real
        // float is what reaches the bridge as one.
        $this->bridge(['op' => 'add_payment', 'invoice_id' => 1.5, 'amount' => '10.00', 'transid' => 'float'])->assertStatus(400);
        $this->assertSame('0.00', $this->paid($one));
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Races (MariaDB, two processes)
    // ----------------------------------------------------------------------------------------------------------------

    /**
     * The same transid sent for invoices of two different customers at once: the client locks do not serialise them,
     * and there is no unique key on transaction_id. Held only if InnoDB's gap locks on the (transaction_id, gateway_id)
     * index make one side deadlock: one record, the other side a 500 that a retry turns into a 409.
     */
    public function test_held_the_same_transid_on_two_customers_at_once_is_recorded_once(): void
    {
        $this->requireRowLocks();
        $other = User::factory()->create()->client();
        $a = $this->onCredit();
        $b = $this->onCredit('124.00', [], $other);

        $outcomes = $this->race([
            fn (string $dir) => $this->pausedAfterReplayCheck($dir, 0, 1, $a, 'shared'),
            fn (string $dir) => $this->pausedAfterReplayCheck($dir, 1, 0, $b, 'shared'),
        ]);

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'ekdosi:shared')->count(), 'outcomes: ' . json_encode($outcomes));
    }

    /** Two Ekdosi payments of the whole amount with different transids at once: one pays, the other finds it paid. */
    public function test_held_two_full_payments_at_once_pay_once_and_never_credit(): void
    {
        $this->requireRowLocks();
        $invoice = $this->onCredit();

        $outcomes = $this->race([
            fn (string $dir) => $this->holdingTheInvoice($dir, fn () => $this->record($invoice, '124.00', 'one')),
            fn (string $dir) => $this->afterTheHolder($dir, fn () => $this->record($invoice, '124.00', 'two')),
        ]);

        $this->assertSame(1, InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count(), json_encode($outcomes));
        $this->assertSame('124.00', $this->paid($invoice));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0.0, (float) Credit::query()->sum('amount'));
    }

    /**
     * An Ekdosi payment holding the invoice while a bank transfer of the whole amount arrives: Ekdosi's never becomes
     * credit; the transfer's excess is the customer's money and becomes credit, as for any payment.
     */
    public function test_held_ekdosi_racing_a_gateway_payment_never_credits_ekdosis_part(): void
    {
        $this->requireRowLocks();
        $invoice = $this->onCredit();

        $this->race([
            fn (string $dir) => $this->holdingTheInvoice($dir, fn () => $this->record($invoice, '124.00', 'race-g')),
            fn (string $dir) => $this->afterTheHolder($dir, fn () => ExtensionHelper::addPayment($invoice->id, 'BankTransfer', '124.00', transactionId: 'bank-race')),
        ]);

        $ekdosi = InvoiceTransaction::query()->where('transaction_id', 'ekdosi:race-g')->sole();
        $this->assertSame([124.0, 0.0], [(float) $ekdosi->amount, (float) $ekdosi->credited_amount]);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** And the other way round: the transfer first, then Ekdosi finds the invoice paid and records nothing. */
    public function test_held_a_gateway_payment_holding_the_invoice_leaves_ekdosi_nothing_to_record(): void
    {
        $this->requireRowLocks();
        $invoice = $this->onCredit();

        $this->race([
            fn (string $dir) => $this->holdingTheInvoice($dir, fn () => ExtensionHelper::addPayment($invoice->id, 'BankTransfer', '124.00', transactionId: 'bank-first')),
            fn (string $dir) => $this->afterTheHolder($dir, fn () => $this->record($invoice, '124.00', 'late')),
        ]);

        $this->assertSame(0, InvoiceTransaction::query()->where('transaction_id', 'ekdosi:late')->count());
        $this->assertSame('124.00', $this->paid($invoice));
        $this->assertSame(0.0, (float) Credit::query()->sum('amount'));
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Held
    // ----------------------------------------------------------------------------------------------------------------

    public function test_held_amounts_outside_the_format_are_refused(): void
    {
        $invoice = $this->onCredit();
        foreach (['0.001', '.5', '99999999999999.99', '1,00', '+1.00', ' ', '0.00', "10\n", '١٠', '1e2', 'NaN', 'INF'] as $i => $amount) {
            $response = $this->bridge(['op' => 'add_payment', 'invoice_id' => $invoice->id, 'amount' => $amount, 'transid' => 'f' . $i]);
            // "10\n" is trimmed to a valid 10: the only one allowed through.
            $amount === "10\n" ? $response->assertOk() : $response->assertStatus(400);
        }
        // A JSON float is cast with PHP's precision (0.30000000000000004 → "0.3"): harmless, not tested.
        foreach ([true, null, 1e20] as $i => $amount) {
            $this->bridge(['op' => 'add_payment', 'invoice_id' => $invoice->id, 'amount' => $amount, 'transid' => 'j' . $i])->assertStatus(400);
        }
        $this->assertSame('10.00', $this->paid($invoice));
    }

    public function test_held_the_largest_amount_is_cut_to_what_is_left(): void
    {
        $invoice = $this->onCredit('0.01');

        $this->pay($invoice, '9999999999999.99', 'big')->assertOk()->assertJsonPath('amount', '0.01')->assertJsonPath('capped', true);
        $this->assertSame([Invoice::STATUS_PAID, 0.0], [$invoice->fresh()->status, (float) Credit::query()->sum('amount')]);
    }

    public function test_held_the_cap_counts_credit_notes_credit_payments_and_refunds(): void
    {
        $invoice = $this->onCredit('124.00');
        EkdosiDocument::query()->where('invoice_id', $invoice->id)->delete(); // a note is refused once the document exists
        // A credit note of 24.00 brings it to 100.00; 10.00 paid from credit; a 20.00 transfer with 5.00 refunded.
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'status' => AdjustmentNoteStatus::Active->value, 'amount' => -24, 'reason' => 'discount']);
        Credit::query()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR', 'amount' => 10]);
        (new RecordPaymentService)->payWithCredit($invoice->id, upToCents: 1000);
        $transfer = ExtensionHelper::addPayment($invoice, 'BankTransfer', '20.00', transactionId: 'bank-r');
        $transfer->update(['refunded_amount' => 5]);
        EkdosiDocument::query()->create(['invoice_id' => $invoice->id, 'mark' => '400001234569999', 'code' => 'ΤΙΜX']);
        $owed = number_format((float) $invoice->fresh()->remaining, 2, '.', '');

        $this->pay($invoice, '500.00', 'rest')->assertOk()->assertJsonPath('amount', $owed);
        $this->assertSame(0.0, (float) Credit::query()->sum('amount'));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_held_refunded_merged_and_customerless_invoices_are_refused(): void
    {
        $refunded = $this->onCredit();
        Invoice::query()->whereKey($refunded->id)->update(['status' => Invoice::STATUS_REFUNDED]);
        $this->pay($refunded, '10.00', 'r')->assertStatus(409);

        $into = $this->onCredit();
        $merged = $this->onCredit();
        Invoice::query()->whereKey($merged->id)->update(['status' => Invoice::STATUS_CANCELLED, 'merged_into_id' => $into->id]);
        $this->pay($merged, '10.00', 'm')->assertStatus(409);

        $orphan = $this->onCredit();
        Invoice::query()->whereKey($orphan->id)->update(['client_id' => null]);
        $this->pay($orphan, '10.00', 'o')->assertStatus(404);

        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_held_a_refunded_then_reopened_invoice_takes_a_new_payment_but_not_the_old_one_again(): void
    {
        $invoice = $this->onCredit();
        $this->pay($invoice, '124.00', 'first')->assertOk();
        $first = InvoiceTransaction::query()->where('transaction_id', 'ekdosi:first')->sole();
        $first->update(['refunded_amount' => 124]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);

        $this->pay($invoice, '124.00', 'first')->assertOk()->assertJsonPath('note', 'already_recorded');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->pay($invoice, '124.00', 'second')->assertOk()->assertJsonPath('amount', '124.00');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_held_transids_are_trimmed_and_100_multibyte_characters_fit_the_column(): void
    {
        $invoice = $this->onCredit();
        $long = str_repeat('😀', 100);

        $this->pay($invoice, '10.00', $long)->assertOk()->assertJsonPath('amount', '10.00');
        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'ekdosi:' . $long)->count(), 'stored whole');
        $this->pay($invoice, '10.00', '  pay-t  ')->assertOk();
        $this->pay($invoice, '10.00', 'pay-t')->assertOk()->assertJsonPath('note', 'already_recorded');
        $this->assertSame('20.00', $this->paid($invoice));
    }

    public function test_held_a_staff_payment_named_like_an_ekdosi_one_on_another_invoice_is_a_409(): void
    {
        $first = $this->onCredit();
        $second = $this->onCredit();
        ExtensionHelper::addPayment($first, 'BankTransfer', '10.00', transactionId: 'ekdosi:x');

        $this->pay($second, '10.00', 'x')->assertStatus(409)->assertJsonPath('error', 'transid_on_another_invoice');
        $this->assertSame('0.00', $this->paid($second));
    }

    public function test_held_userids_is_strict_and_narrows_every_status(): void
    {
        $mine = $this->onCredit();
        $other = $this->onCredit('10.00', [], User::factory()->create()->client());
        $ids = fn (array $body) => collect($this->bridge(['op' => 'invoices'] + $body)->assertOk()->json('invoices'))->pluck('id')->all();

        $this->assertSame([$mine->id], $ids(['status' => 'All', 'userids' => [$this->client->id, $this->client->id]]));
        $this->assertSame([], $ids(['status' => 'All', 'userids' => [PHP_INT_MAX]]));
        $this->assertSame([$mine->id], $ids(['status' => 'All', 'userids' => (object) ['0' => $this->client->id]]), 'a JSON object with keys 0..n is a list');
        $this->assertEqualsCanonicalizing([$mine->id, $other->id], $ids(['status' => 'All', 'userids' => null]));
        foreach ([[true], [[1]], [-1], [1e20], 'all', 1, ['1 OR 1=1']] as $bad) {
            $this->bridge(['op' => 'invoices', 'status' => 'All', 'userids' => $bad])->assertStatus(400);
        }
        $this->bridge(['op' => 'invoices', 'status' => 'All', 'userids' => range(1, 1000)])->assertOk();
    }

    // ----------------------------------------------------------------------------------------------------------------
    // Race helpers
    // ----------------------------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: array<string, mixed>} */
    private function record(Invoice $invoice, string $amount, string $transid): array
    {
        return app(EkdosiPayments::class)->record(['invoice_id' => $invoice->id, 'amount' => $amount, 'transid' => $transid]);
    }

    /** Pauses right after the replay check (a locking read that found nothing) until the other worker passed it too. */
    private function pausedAfterReplayCheck(string $dir, int $me, int $other, Invoice $invoice, string $transid): void
    {
        $paused = false;
        DB::listen(function ($query) use (&$paused, $dir, $me, $other) {
            if (!$paused && str_starts_with($query->sql, 'select * from `invoice_transactions` where `transaction_id` = ?') && str_contains($query->sql, 'for update')) {
                $paused = true;
                touch("$dir/checked-$me");
                $this->waitFor("$dir/checked-$other");
                usleep(200000);
            }
        });
        try {
            file_put_contents("$dir/outcome-$me", json_encode($this->record($invoice, '124.00', $transid)));
        } catch (Throwable $e) {
            file_put_contents("$dir/outcome-$me", 'exception: ' . $e->getMessage());
        }
    }

    private function holdingTheInvoice(string $dir, callable $work): void
    {
        $paused = false;
        DB::listen(function ($query) use (&$paused, $dir) {
            if (!$paused && str_starts_with($query->sql, 'select * from `invoices`') && str_contains($query->sql, 'for update')) {
                $paused = true;
                touch("$dir/holding");
                $this->waitFor("$dir/second-started");
                usleep(400000); // the second one is now waiting for its first lock
            }
        });
        file_put_contents("$dir/outcome-0", json_encode($work()));
    }

    private function afterTheHolder(string $dir, callable $work): void
    {
        $this->waitFor("$dir/holding");
        touch("$dir/second-started");
        file_put_contents("$dir/outcome-1", json_encode($work()));
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
     * @return list<string|null> what each worker wrote as its outcome
     */
    private function race(array $workers): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs pcntl.');
        }
        $dir = sys_get_temp_dir() . '/mycs-ekdosi-race-' . bin2hex(random_bytes(8));
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

            return array_map(fn (int $i) => file_exists("$dir/outcome-$i") ? file_get_contents("$dir/outcome-$i") : null, array_keys($workers));
        } finally {
            self::reapWorkers($pids);
            foreach (glob("$dir/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
