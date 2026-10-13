<?php

namespace Tests\Feature;

use App\Admin\Resources\UserResource\Pages\ShowCredits;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\MoneyEventNote;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use Paymenter\Extensions\Others\Affiliates\Listeners\RewardAffiliate;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateOrder;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Adversarial review of MT-1b (CreditLedger, docs/money-trail.md §2). Claimed invariants under attack:
 *  C1 every change of credits.amount goes through CreditLedger (or deleteTransaction) with its events, atomically;
 *  C2 per customer and currency, credits.amount == Σ creditEffectCents() after any sequence of the money paths;
 *  C3 staff/API never take a balance below zero, setBalance journals exactly the difference, the API never deletes a
 *     non-zero balance, one credit row per customer and currency;
 *  C4 staff reasons only in money_event_notes, never in money_events;
 *  C5 mycs:credits-check changes nothing without --write, and with --write matches the journal under concurrent writes.
 * Tests that hold stay as regression coverage.
 */
class CreditLedgerAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'format' => '1.000,00']);
    }

    // ---------------------------------------------------------------- helpers

    private static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function balanceCents(User $user, string $currency = 'USD'): ?int
    {
        $amount = Credit::query()->where('user_id', $user->id)->where('currency_code', $currency)->value('amount');

        return $amount === null ? null : self::cents($amount);
    }

    private function apiToken(): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'adv', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => ['admin.credits.create', 'admin.credits.update', 'admin.credits.delete', 'admin.credits.view']]);

        return $token;
    }

    private function invoiceFor(User $user, int $cents, string $currency = 'USD', bool $creditPurchase = false): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'unpaid', 'currency_code' => $currency]);
        $invoice->items()->create(array_filter([
            'reference_type' => $creditPurchase ? Credit::class : null,
            'description' => $creditPurchase ? 'Deposit' : 'Hosting',
            'quantity' => 1,
            'price' => $cents / 100,
        ], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    /** C2 (exact cents, no float tolerance) + C3 (one row per customer/currency). */
    private function assertCreditsMatchJournal(string $context = ''): void
    {
        $journal = [];
        foreach (MoneyEvent::query()->orderBy('id')->get() as $event) {
            $effect = $event->creditEffectCents();
            if ($effect !== 0 || in_array($event->kind->value, ['credit_in', 'credit_out', 'reversal'], true)) {
                $key = $event->user_id . '/' . $event->currency_code;
                $journal[$key] = ($journal[$key] ?? 0) + $effect;
            }
        }
        $actual = [];
        foreach (Credit::query()->get() as $credit) {
            $key = $credit->user_id . '/' . $credit->currency_code;
            $this->assertArrayNotHasKey($key, $actual, "C3 two credit rows for {$key}. {$context}");
            $actual[$key] = self::cents($credit->amount);
        }
        foreach (array_unique(array_merge(array_keys($journal), array_keys($actual))) as $key) {
            $this->assertSame($journal[$key] ?? 0, $actual[$key] ?? 0, "C2 credit vs journal for {$key}. {$context}");
        }
    }

    /** C4: the journal has no free text; every note hangs off a staff/API credit move. */
    private function assertNotesApart(array $reasons, string $context = ''): void
    {
        $this->assertSame(0, MoneyEvent::query()->whereNotNull('reason')->count(), "C4 money_events.reason written. {$context}");
        $dump = json_encode(DB::table('money_events')->get());
        foreach ($reasons as $reason) {
            $this->assertStringNotContainsString($reason, $dump, "C4 a staff reason inside money_events. {$context}");
        }
        foreach (MoneyEventNote::query()->with('moneyEvent')->get() as $note) {
            $this->assertSame(MoneyTrail::CAUSE_STAFF_ADJUSTMENT, $note->moneyEvent->cause, "C4 a note on a non-staff event. {$context}");
        }
    }

    // ---------------------------------------------------------------- fuzz (C2, C3, C4, C5)

    public function test_fuzz_seed_11(): void
    {
        $this->fuzz(11, 70);
    }

    public function test_fuzz_seed_12(): void
    {
        $this->fuzz(12, 70);
    }

    public function test_fuzz_seed_13(): void
    {
        $this->fuzz(13, 70);
    }

    public function test_fuzz_seed_14(): void
    {
        $this->fuzz(14, 70);
    }

    /**
     * Random sequences mixing: gateway payments (exact, partial, over, on cancelled), credit payments, allocation of a
     * sale's credit, credit purchases, local refunds, transaction deletions, staff add/remove (ledger, with a reason),
     * setBalance, API store/update/destroy, and mycs:credits-check with and without --write. Two customers, two
     * currencies. After every step: balances == journal, one row each, never negative (no negative purchase lines
     * here), the check reports nothing and --write changes nothing, reasons only in notes.
     */
    private function fuzz(int $seed, int $steps): void
    {
        mt_srand($seed);
        $svc = new RecordPaymentService;
        $ledger = new CreditLedger;
        $token = $this->apiToken();
        $users = [$this->user, User::factory()->create()];
        $currencies = ['USD', 'EUR'];
        $invoices = [];
        $reasons = [];
        $log = [];
        $n = 0;
        for ($i = 0; $i < 4; $i++) {
            $invoices[] = $this->invoiceFor($users[mt_rand(0, 1)], mt_rand(100, 20000), $currencies[mt_rand(0, 1)], mt_rand(0, 3) === 0)->id;
        }

        for ($step = 0; $step < $steps; $step++) {
            $roll = mt_rand(1, 100);
            $invoiceId = $invoices[array_rand($invoices)];
            $invoice = Invoice::query()->find($invoiceId);
            $transactions = InvoiceTransaction::query()->orderBy('id')->get();
            $user = $users[mt_rand(0, 1)];
            $currency = $currencies[mt_rand(0, 1)];
            $op = 'op';
            try {
                if ($roll <= 6) {
                    $invoices[] = $this->invoiceFor($user, mt_rand(100, 20000), $currency, mt_rand(0, 2) === 0)->id;
                    $op = 'new invoice #' . end($invoices);
                } elseif ($roll <= 30) {
                    $remaining = max(0, self::cents($invoice->fresh()->remaining));
                    $cents = match (mt_rand(0, 3)) {
                        0 => $remaining > 0 ? $remaining : mt_rand(1, 5000),
                        1 => $remaining + mt_rand(1, 5000),
                        2 => mt_rand(1, max(1, $remaining)),
                        default => mt_rand(1, 20000),
                    };
                    $status = mt_rand(0, 9) === 0 ? InvoiceTransactionStatus::Processing : InvoiceTransactionStatus::Succeeded;
                    $txid = 'cl' . $seed . '-' . (++$n);
                    if ($transactions->isNotEmpty() && mt_rand(0, 4) === 0) {
                        $old = $this->pickOne($transactions);
                        if ($old->transaction_id !== null) {
                            $txid = $old->transaction_id;
                            $invoiceId = $old->invoice_id;
                            $invoice = Invoice::query()->find($invoiceId);
                        }
                    }
                    ExtensionHelper::addPayment($invoiceId, 'Stripe', $cents / 100, null, $txid, $status, currency: $invoice->currency_code);
                    $op = "pay #{$invoiceId} {$cents}c {$status->value} {$txid}";
                } elseif ($roll <= 38) {
                    $t = $svc->payWithCredit($invoiceId, (bool) mt_rand(0, 1));
                    $op = "payWithCredit #{$invoiceId} -> " . ($t?->id ?? 'none');
                } elseif ($roll <= 42) {
                    $sources = $transactions->filter(fn ($t) => $t->status === InvoiceTransactionStatus::Succeeded && self::cents($t->credited_amount) > 0);
                    $op = 'allocate: none';
                    if ($sources->isNotEmpty()) {
                        $source = $this->pickOne($sources);
                        $t = $svc->allocateFromTransaction($source, $invoiceId);
                        $op = "allocate tx{$source->id} -> #{$invoiceId}: " . ($t?->id ?? 'none');
                    }
                } elseif ($roll <= 49) {
                    $refundable = $transactions->filter(fn ($t) => $t->status === InvoiceTransactionStatus::Succeeded
                        && self::cents($t->amount) - self::cents($t->refunded_amount) - self::cents($t->credited_amount) > 0);
                    $op = 'refund: none';
                    if ($refundable->isNotEmpty()) {
                        $t = $this->pickOne($refundable);
                        $max = self::cents($t->amount) - self::cents($t->refunded_amount) - self::cents($t->credited_amount);
                        $cents = mt_rand(0, 1) ? $max : mt_rand(1, $max);
                        $op = "refundLocally tx{$t->id} {$cents}c";
                        ExtensionHelper::refundLocally($t, $cents / 100);
                    }
                } elseif ($roll <= 57) {
                    $op = 'delete: none';
                    if ($transactions->isNotEmpty()) {
                        $t = $this->pickOne($transactions);
                        $op = "delete tx{$t->id}";
                        $svc->deleteTransaction($t);
                    }
                } elseif ($roll <= 67) {
                    $cents = mt_rand(1, 8000);
                    $reason = 'adv-staff-' . $seed . '-' . (++$n);
                    $reasons[] = $reason;
                    if (mt_rand(0, 1)) {
                        $ledger->add($user->id, $currency, $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: $reason);
                        $op = "staff add {$user->id} {$currency} {$cents}c";
                    } else {
                        $op = "staff remove {$user->id} {$currency} {$cents}c";
                        $ledger->remove($user->id, $currency, $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: $reason);
                    }
                } elseif ($roll <= 82) {
                    $reason = 'adv-api-' . $seed . '-' . (++$n);
                    $reasons[] = $reason;
                    $row = Credit::query()->where('user_id', $user->id)->where('currency_code', $currency)->first();
                    if ($row === null) {
                        $amount = mt_rand(0, 3) === 0 ? 0 : mt_rand(1, 9000) / 100;
                        $status = $this->withToken($token)->postJson(route('api.v1.admin.credits.store'),
                            ['user_id' => $user->id, 'currency_code' => $currency, 'amount' => $amount, 'reason' => $reason])->status();
                        $op = "api store {$user->id} {$currency} {$amount} -> {$status}";
                    } elseif (mt_rand(0, 3) === 0) {
                        $before = self::cents($row->amount);
                        $status = $this->withToken($token)->deleteJson(route('api.v1.admin.credits.destroy', $row->id))->status();
                        $op = "api destroy {$row->id} ({$before}c) -> {$status}";
                        if ($before !== 0) {
                            $this->assertSame(422, $status, "C3 the API deleted a non-zero balance. {$op}");
                            $this->assertNotNull(Credit::query()->find($row->id));
                        }
                    } else {
                        $amount = mt_rand(0, 4) === 0 ? 0 : mt_rand(0, 9000) / 100;
                        $before = self::cents($row->amount);
                        $events = MoneyEvent::query()->count();
                        $status = $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $row->id),
                            ['amount' => $amount, 'reason' => $reason])->status();
                        $op = "api update {$row->id} {$before}c -> {$amount} = {$status}";
                        $this->assertSame(200, $status, $op);
                        $this->assertSame(self::cents($amount), self::cents($row->fresh()->amount), $op);
                        $diff = self::cents($amount) - $before;
                        $this->assertSame($diff === 0 ? 0 : 1, MoneyEvent::query()->count() - $events, "C3 setBalance wrote more than one move. {$op}");
                        if ($diff !== 0) {
                            $last = MoneyEvent::query()->orderByDesc('id')->first();
                            $this->assertSame($diff, $last->creditEffectCents(), "C3 setBalance journaled something else than the difference. {$op}");
                        }
                    }
                } elseif ($roll <= 90) {
                    $snapshot = Credit::query()->orderBy('id')->get(['id', 'user_id', 'currency_code', 'amount'])->toArray();
                    $write = (bool) mt_rand(0, 1);
                    $code = Artisan::call('mycs:credits-check', $write ? ['--write' => true] : []);
                    $op = 'credits-check' . ($write ? ' --write' : '') . " -> {$code}";
                    $this->assertSame(0, $code, "C5 the check found a difference that should not exist. {$op}\n" . Artisan::output());
                    $this->assertEquals($snapshot, Credit::query()->orderBy('id')->get(['id', 'user_id', 'currency_code', 'amount'])->toArray(),
                        "C5 the check changed balances that matched. {$op}");
                } else {
                    $op = "cancel #{$invoiceId}: not open";
                    if (in_array($invoice->status, ['unpaid', 'draft'], true)) {
                        $invoice->update(['status' => 'cancelled']);
                        $op = "cancel #{$invoiceId}";
                    }
                }
            } catch (DisplayException|InvalidArgumentException $e) {
                $op .= ' refused: ' . $e->getMessage();
            }
            $log[] = "{$step}: {$op}";
            $context = "seed {$seed}\n" . implode("\n", array_slice($log, -15));
            $this->assertCreditsMatchJournal($context);
            $this->assertSame(0, Credit::query()->where('amount', '<', 0)->count(), "C3 a balance went below zero. {$context}");
            $this->assertNotesApart($reasons, $context);
        }
    }

    // ---------------------------------------------------------------- C2/C3: the toCents() cap (> 1e12)

    /**
     * RecordPaymentService::toCents() returns 0 for |amount| > 1e12, and CreditLedger read the stored balance through
     * it: a balance above 1,000,000,000,000.00 was read as 0 on the next move, the row overwritten with just that move
     * while the journal kept the whole history. Fixed: the ledger caps a balance at CreditLedger::MAX_CENTS
     * (9,999,999,999.99) and reads it strictly. The typo with extra zeros is refused, the books still match.
     */
    public function test_a_balance_above_the_to_cents_cap_still_matches_the_journal(): void
    {
        $ledger = new CreditLedger;
        try {
            $ledger->add($this->user->id, 'USD', 100_000_000_000_000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'typo: extra zeros');
            $this->fail('A balance of 1,000,000,000,000.00 was accepted.');
        } catch (DisplayException) {
        }
        $ledger->add($this->user->id, 'USD', CreditLedger::MAX_CENTS, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'up to the cap');
        try {
            $ledger->add($this->user->id, 'USD', 1, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'one past the cap');
            $this->fail('A balance past the cap was accepted.');
        } catch (DisplayException) {
        }
        $ledger->remove($this->user->id, 'USD', 100, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'one');

        $this->assertSame(CreditLedger::MAX_CENTS - 100, $this->balanceCents($this->user));
        $this->assertCreditsMatchJournal('balance at the cap');
    }

    /**
     * API: setting a balance above the cap silently set it to 0 (and journaled the whole balance out). Fixed in b1512527
     * (max:9999999999.99 on the requests): held since.
     */
    public function test_the_api_does_not_silently_zero_a_balance_given_a_huge_amount(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'x');
        $id = Credit::query()->where('user_id', $this->user->id)->value('id');

        $response = $this->withToken($this->apiToken())->putJson(route('api.v1.admin.credits.update', $id),
            ['amount' => 10_000_000_000_000, 'reason' => 'set']);

        $balance = $this->balanceCents($this->user);
        $this->assertTrue($response->status() === 422 || $balance === 1_000_000_000_000_000,
            "Asked for 10,000,000,000,000.00, got HTTP {$response->status()} and a balance of {$balance} cents.");
    }

    /**
     * API: destroy read the balance through toCents() too, so a balance above 1e12 counted as zero and was deleted.
     * The ledger no longer reaches such a balance; a row written outside it (legacy data) must still not be deleted,
     * nor have its currency changed, nor be read as zero by a payment or the check.
     */
    public function test_the_api_never_deletes_a_non_zero_balance_even_above_the_cap(): void
    {
        $credit = Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 0]);
        DB::table('credits')->where('id', $credit->id)->update(['amount' => '1000000000001.00']);

        $token = $this->apiToken();
        $status = $this->withToken($token)->deleteJson(route('api.v1.admin.credits.destroy', $credit->id))->status();
        $this->assertSame(422, $status, 'The API deleted a balance of 1,000,000,000,001.00.');
        $this->assertNotNull(Credit::query()->find($credit->id));

        $status = $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $credit->id), ['currency_code' => 'EUR'])->status();
        $this->assertSame(422, $status, 'The API changed the currency of a balance of 1,000,000,000,001.00.');

        $this->assertSame(1, Artisan::call('mycs:credits-check'), 'The check read a balance of 1,000,000,000,001.00 as zero.');

        $this->expectException(LogicException::class);
        (new CreditLedger)->add($this->user->id, 'USD', 100, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'y');
    }

    // ---------------------------------------------------------------- C4 / decision 2: the reason

    /**
     * docs/money-trail.md §2, decision 2: the staff reason is mandatory «in the form AND the service». CreditLedger
     * accepted a staff_adjustment without a note. Fixed in b1512527 (CreditLedger::requireReason): held since.
     */
    public function test_the_ledger_refuses_a_staff_adjustment_without_a_reason(): void
    {
        $refused = false;
        try {
            (new CreditLedger)->add($this->user->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException|LogicException|InvalidArgumentException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'A staff adjustment was journaled without a reason.');
        $this->assertSame(0, MoneyEvent::query()->count());
    }

    /** Held: whitespace reasons are refused by the request rules; the note is trimmed, the journal stays free of it. */
    public function test_api_reason_rules_and_note_placement(): void
    {
        $token = $this->apiToken();
        $this->withToken($token)->postJson(route('api.v1.admin.credits.store'),
            ['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 10, 'reason' => '   '])->assertStatus(422);
        $id = $this->withToken($token)->postJson(route('api.v1.admin.credits.store'),
            ['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 10, 'reason' => '  Customer Jane Doe, jane@example.test  '])
            ->assertSuccessful()->json('data.id');
        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $id), ['amount' => 4, 'reason' => ''])->assertStatus(422);

        $this->assertSame(['Customer Jane Doe, jane@example.test'], MoneyEventNote::query()->pluck('note')->all());
        $this->assertNotesApart(['Jane', 'jane@example.test']);
        $this->assertCreditsMatchJournal();
    }

    // ---------------------------------------------------------------- C3: API rules (held)

    public function test_api_currency_change_negative_amount_and_duplicates_are_refused(): void
    {
        $token = $this->apiToken();
        $usd = $this->withToken($token)->postJson(route('api.v1.admin.credits.store'),
            ['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 0])->assertSuccessful()->json('data.id');
        $this->withToken($token)->postJson(route('api.v1.admin.credits.store'),
            ['user_id' => $this->user->id, 'currency_code' => 'USD', 'amount' => 5, 'reason' => 'dup'])->assertStatus(422);
        $eur = $this->withToken($token)->postJson(route('api.v1.admin.credits.store'),
            ['user_id' => $this->user->id, 'currency_code' => 'EUR', 'amount' => 7, 'reason' => 'eur'])->assertSuccessful()->json('data.id');

        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $usd), ['currency_code' => 'EUR'])->assertStatus(422);
        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $eur), ['currency_code' => 'USD'])->assertStatus(422);
        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $eur), ['amount' => -1, 'reason' => 'neg'])->assertStatus(422);
        $this->withToken($token)->putJson(route('api.v1.admin.credits.update', $eur), ['amount' => '-0.01', 'reason' => 'neg'])->assertStatus(422);

        $this->assertSame(700, $this->balanceCents($this->user, 'EUR'));
        $this->assertSame(0, $this->balanceCents($this->user, 'USD'));
        $this->assertCreditsMatchJournal();
    }

    // ---------------------------------------------------------------- C1: atomicity (held)

    public function test_a_rolled_back_caller_leaves_neither_balance_nor_event_nor_note(): void
    {
        try {
            DB::transaction(function () {
                (new CreditLedger)->add($this->user->id, 'USD', 1234, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'gone');
                throw new \RuntimeException('caller fails afterwards');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNull($this->balanceCents($this->user));
        $this->assertSame(0, MoneyEvent::query()->count());
        $this->assertSame(0, MoneyEventNote::query()->count());
    }

    /** A refused removal in a currency the customer has no row in leaves no (zero) row and no event behind. */
    public function test_a_refused_removal_leaves_nothing_behind(): void
    {
        try {
            (new CreditLedger)->remove($this->user->id, 'EUR', 1, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'n');
        } catch (DisplayException) {
        }

        $this->assertSame(0, Credit::query()->count());
        $this->assertSame(0, MoneyEvent::query()->count() + MoneyEventNote::query()->count());
    }

    // ---------------------------------------------------------------- C5: credits-check

    /** Held: without --write nothing is written, whatever is wrong (a row off, a row missing, a journal-only key). */
    public function test_the_check_without_write_changes_nothing(): void
    {
        $other = User::factory()->create();
        $ledger = new CreditLedger;
        $ledger->add($this->user->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'a');
        $ledger->add($other->id, 'EUR', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'b');
        DB::table('credits')->where('user_id', $this->user->id)->update(['amount' => 3]);
        DB::table('credits')->where('user_id', $other->id)->delete();
        DB::table('credits')->insert(['user_id' => $other->id, 'currency_code' => 'USD', 'amount' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $credits = DB::table('credits')->orderBy('id')->get()->toArray();
        $events = DB::table('money_events')->count();

        $this->assertSame(1, Artisan::call('mycs:credits-check'));

        $this->assertEquals($credits, DB::table('credits')->orderBy('id')->get()->toArray());
        $this->assertSame($events, DB::table('money_events')->count());

        $this->assertSame(0, Artisan::call('mycs:credits-check', ['--write' => true]));
        $this->assertCreditsMatchJournal('after --write');
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    /*
     * Finding (fixed in b1512527, not kept as a test): the read-only check read the journal, then the balances, with no
     * snapshot, so a move committing in between was reported as a mismatch (exit 1). b1512527 reads both inside one
     * transaction (one REPEATABLE READ snapshot on MariaDB). A single test connection sees its own writes, so the
     * snapshot cannot be modelled here; the original simulation injected a ledger move right after the journal query.
     */

    /** Held: with --write, a move committed between the first read and the per-row lock is in the rebuilt balance. */
    public function test_the_check_with_write_matches_the_journal_when_a_move_commits_before_the_lock(): void
    {
        $ledger = new CreditLedger;
        $ledger->add($this->user->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'a');
        DB::table('credits')->where('user_id', $this->user->id)->update(['amount' => 1]); // a real difference to rebuild
        $this->injectAfterFirstQueryOn('"credits"', fn () => $ledger->add($this->user->id, 'USD', 333, MoneyTrail::CAUSE_OVERPAYMENT, MoneyTrail::SOURCE_MANUAL));

        Artisan::call('mycs:credits-check', ['--write' => true]);

        $this->assertCreditsMatchJournal('after --write with a concurrent move');
    }

    /** Held: a move that lands at the per-row lock (simulated right after the customer lock query) is in the rebuilt balance. */
    public function test_the_check_with_write_matches_the_journal_when_a_move_lands_at_the_lock(): void
    {
        $ledger = new CreditLedger;
        $ledger->add($this->user->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'a');
        DB::table('credits')->where('user_id', $this->user->id)->update(['amount' => 1]);
        $this->injectAfterFirstQueryOn('from "users"', fn () => $ledger->add($this->user->id, 'USD', 444, MoneyTrail::CAUSE_OVERPAYMENT, MoneyTrail::SOURCE_MANUAL));

        Artisan::call('mycs:credits-check', ['--write' => true]);

        $this->assertCreditsMatchJournal('after --write with a move at the lock');
    }

    private function injectAfterFirstQueryOn(string $needle, \Closure $write): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $needle, $write) {
            if ($fired || !str_contains($query->sql, $needle) || !str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            $fired = true;
            $write();
        });
    }

    // ---------------------------------------------------------------- affiliates (held)

    public function test_an_affiliate_reward_goes_through_the_ledger(): void
    {
        Artisan::call('migrate', ['--path' => 'extensions/Others/Affiliates/database/migrations', '--force' => true]);
        $affiliateUser = User::factory()->create();
        $affiliate = Affiliate::query()->create(['user_id' => $affiliateUser->id, 'code' => 'ADV', 'reward' => 10]);
        $product = $this->createProduct();
        $order = Order::query()->create(['user_id' => $this->user->id, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['user_id' => $this->user->id, 'order_id' => $order->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'price' => 33.33, 'currency_code' => 'USD']);
        AffiliateOrder::query()->create(['order_id' => $order->id, 'affiliate_id' => $affiliate->id]);
        // Stored paid, its line added while it was open (MT-1c: never created paid, a paid invoice keeps its lines).
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 33.33, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);

        (new RewardAffiliate)->handle((object) ['invoice' => $invoice->fresh()]);

        $this->assertSame(333, $this->balanceCents($affiliateUser));
        $event = MoneyEvent::query()->where('user_id', $affiliateUser->id)->sole();
        $this->assertSame([MoneyTrail::CAUSE_AFFILIATE_REWARD, 'system'], [$event->cause, $event->source]);
        $this->assertCreditsMatchJournal();
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    // ---------------------------------------------------------------- admin page (held)

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_admin_page_refuses_sub_cent_and_overdraw_and_leaves_nothing_behind(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $page = fn () => Livewire::actingAs($owner)->test(ShowCredits::class, ['record' => $this->user->getKey()]);

        $page()->callTableAction('addCredit', data: ['currency_code' => 'USD', 'amount' => '0.004', 'reason' => 'tiny'])
            ->assertHasTableActionErrors(['amount']);
        $page()->callTableAction('addCredit', data: ['currency_code' => 'USD', 'amount' => '-5', 'reason' => 'neg'])
            ->assertHasTableActionErrors(['amount']);
        $page()->callTableAction('removeCredit', data: ['currency_code' => 'EUR', 'amount' => '1.00', 'reason' => 'none there'])
            ->assertNotified();
        $page()->callTableAction('addCredit', data: ['currency_code' => 'EUR', 'amount' => '2.50', 'reason' => 'ok'])
            ->assertHasNoTableActionErrors();
        $page()->callTableAction('removeCredit', data: ['currency_code' => 'EUR', 'amount' => '2.51', 'reason' => 'over'])
            ->assertNotified();

        $this->assertNull($this->balanceCents($this->user, 'USD'), 'A refused action left a USD row.');
        $this->assertSame(250, $this->balanceCents($this->user, 'EUR'));
        $this->assertSame(['ok'], MoneyEventNote::query()->pluck('note')->all());
        $this->assertCreditsMatchJournal();
    }
}
