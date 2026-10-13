<?php

namespace Tests\Feature;

use App\Admin\Resources\UserResource\Pages\EditUser;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Client\Credits;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Adversarial review of MT-1e (PR #56, docs/money-trail.md decision 5): account credit paying open invoices on its own.
 * Tests that expose a bug fail on the branch; the rest stay as regression coverage of the invariants J1–J8.
 */
class CreditAutoApplyAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var list<string> credit payments made that the rules forbid, seen at the moment they were written */
    private array $violations = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    // ---------------------------------------------------------------- helpers

    private function invoice(float $price = 100, string $due = '+7 days', ?string $reference = null, ?int $referenceId = null, ?User $user = null, string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => ($user ?? $this->user)->id, 'status' => Invoice::STATUS_PENDING, 'currency_code' => $currency, 'due_at' => now()->modify($due)]);
        $invoice->items()->create(array_filter(['description' => 'Line', 'quantity' => 1, 'price' => $price,
            'reference_type' => $reference, 'reference_id' => $referenceId], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function service(array $attributes = [], ?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge(['user_id' => ($user ?? $this->user)->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10,
            'expires_at' => now()->addMonth()], $attributes));
    }

    private function giveCredit(int $cents, ?User $user = null, string $currency = 'USD'): void
    {
        (new CreditLedger)->add(($user ?? $this->user)->id, $currency, $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
    }

    private function balanceCents(?User $user = null, string $currency = 'USD'): int
    {
        return (new CreditLedger)->balanceCents(($user ?? $this->user)->id, $currency);
    }

    private function creditPayments(Invoice $invoice): int
    {
        return $invoice->transactions()->where('is_credit_transaction', true)->count();
    }

    private function assertJournalHolds(): void
    {
        $this->assertSame(0, Artisan::call('mycs:credits-check'), Artisan::output());
    }

    private function creditAppliedNotes(): int
    {
        return Notification::query()->where('user_id', $this->user->id)->where('title', 'Account credit applied')->count();
    }

    /** Independent reading of decision 5's exclusions, at the moment a credit payment is written (inside its lock). */
    private function watchCreditPayments(): void
    {
        InvoiceTransaction::creating(function (InvoiceTransaction $transaction) {
            if (!$transaction->is_credit_transaction || $transaction->source_transaction_id !== null) {
                return;
            }
            $invoice = Invoice::query()->find($transaction->invoice_id);
            $why = [];
            if (!config('settings.credits_enabled') || !config('settings.credits_auto_use', true)) {
                $why[] = 'settings off';
            }
            if ($invoice->status !== Invoice::STATUS_PENDING) {
                $why[] = 'status ' . $invoice->status;
            }
            if (!(bool) User::query()->whereKey($invoice->user_id)->value('credit_auto_apply')) {
                $why[] = 'opted out';
            }
            if ($invoice->items()->where('reference_type', Credit::class)->exists()) {
                $why[] = 'credit purchase';
            }
            if ($invoice->transactions()->where('status', InvoiceTransactionStatus::Processing->value)->exists()) {
                $why[] = 'processing payment';
            }
            $services = $invoice->items()->where('reference_type', Service::class)->pluck('reference_id');
            if (Service::query()->whereIn('id', $services)->whereNotNull('subscription_id')->where('subscription_id', '!=', '')->exists()) {
                $why[] = 'subscription';
            }
            if ($why !== []) {
                $this->violations[] = "invoice #{$invoice->id} paid " . $transaction->amount . ' from credit: ' . implode(', ', $why);
            }
        });
    }

    // ---------------------------------------------------------------- J3 / J5: order, and the cron

    /**
     * FINDING (J3/J5). The cron bills a renewal and spends the credit on that NEW invoice (CronJob::billRenewal →
     * CreditAutoApply::toInvoice) before the daily step pays what is already overdue, oldest first. A customer whose
     * credit covers the overdue invoice is then suspended: the case decision 5 is written for.
     */
    public function test_a_renewal_invoice_does_not_take_the_credit_an_older_overdue_invoice_needs(): void
    {
        $overdueService = $this->service(['expires_at' => now()->subDays(5)]);
        $overdue = $this->invoice(10, '-5 days', Service::class, $overdueService->id);
        $overdue->items()->sole()->update(['period_start' => now()->subDays(5)->toDateString(), 'period_end' => now()->addDays(25)->toDateString()]);
        $renewing = $this->service(['expires_at' => now()->addDays(2)]);
        $this->giveCredit(1000);
        config(['settings.cronjob_invoice' => 7, 'settings.cronjob_order_suspend' => 2]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $renewal = Invoice::query()->whereKeyNot($overdue->id)->where('user_id', $this->user->id)->sole();
        $this->assertSame(Invoice::STATUS_PAID, $overdue->fresh()->status,
            'credit paid the new renewal (due ' . $renewal->due_at . ', ' . $this->creditPayments($renewal) . ' credit payment) instead of the overdue invoice');
        $this->assertSame('active', $overdueService->fresh()->status, 'suspended although its credit covered the overdue invoice');
        $this->assertSame('active', $renewing->fresh()->status);
        $this->assertJournalHolds();
    }

    /** Held: a renewal fully paid with credit renews its service exactly once, also over a second cron run. */
    public function test_a_renewal_fully_paid_by_credit_renews_once(): void
    {
        $service = $this->service(['expires_at' => now()->addDays(2)->startOfDay()]);
        $expires = $service->expires_at->copy();
        $this->giveCredit(5000);
        config(['settings.cronjob_invoice' => 7]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice = Invoice::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame($expires->copy()->addMonth()->toDateString(), $service->fresh()->expires_at->toDateString());
        $this->assertSame(4000, $this->balanceCents());
        $this->assertSame(0, $this->creditAppliedNotes(), 'a full payment sends «Invoice paid», not «credit applied»');
        $this->assertJournalHolds();
    }

    /** Held: a partial credit leaves the renewal pending and the service not renewed (and not suspended early). */
    public function test_a_partial_credit_does_not_renew_the_service(): void
    {
        $service = $this->service(['expires_at' => now()->addDays(2)->startOfDay()]);
        $expires = $service->expires_at->toDateString();
        $this->giveCredit(400);
        config(['settings.cronjob_invoice' => 7]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice = Invoice::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertSame(6.0, $invoice->remaining);
        $this->assertSame($expires, $service->fresh()->expires_at->toDateString());
        $this->assertSame(1, $this->creditAppliedNotes());
    }

    /** Held: oldest due first, then the lower id on the same due date; the credit stops where it runs out. */
    public function test_equal_due_dates_are_paid_by_id_and_the_credit_stops_when_it_runs_out(): void
    {
        $due = '+3 days';
        $first = $this->invoice(30, $due);
        $second = $this->invoice(30, $due);
        $third = $this->invoice(30, '+1 day');
        $later = $this->invoice(30, '+20 days');
        Invoice::query()->whereKey([$first->id, $second->id])->update(['due_at' => $first->due_at]);
        $this->giveCredit(7000);

        (new CreditAutoApply)->sweep();

        $this->assertSame(Invoice::STATUS_PAID, $third->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $first->fresh()->status);
        $this->assertSame(20.0, $second->fresh()->remaining);
        $this->assertSame(0, $this->creditPayments($later));
        $this->assertSame(0, $this->balanceCents());
        $this->assertJournalHolds();
    }

    /** Held: one customer's failure does not stop the sweep for the others. */
    public function test_one_customers_failure_does_not_stop_the_sweep(): void
    {
        $other = User::factory()->create();
        $broken = $this->invoice(10);
        $fine = $this->invoice(10, user: $other);
        $this->giveCredit(1000);
        $this->giveCredit(1000, $other);
        InvoiceTransaction::creating(function (InvoiceTransaction $transaction) use ($broken) {
            if ((int) $transaction->invoice_id === $broken->id) {
                throw new \RuntimeException('boom');
            }
        });

        (new CreditAutoApply)->sweep();

        $this->assertSame(Invoice::STATUS_PENDING, $broken->fresh()->status);
        $this->assertSame(1000, $this->balanceCents(), 'the failed payment rolled back');
        $this->assertSame(Invoice::STATUS_PAID, $fine->fresh()->status);
        $this->assertJournalHolds();
    }

    // ---------------------------------------------------------------- J1: exclusions under the locks

    /** Held: the opt-out flipped while a pass is running is read under the lock, per invoice. */
    public function test_an_opt_out_during_a_pass_stops_the_next_invoice(): void
    {
        $first = $this->invoice(10, '+1 day');
        $second = $this->invoice(10, '+2 days');
        $this->giveCredit(5000);
        InvoiceTransaction::created(function (InvoiceTransaction $transaction) use ($first) {
            if ((int) $transaction->invoice_id === $first->id) {
                DB::table('users')->where('id', $this->user->id)->update(['credit_auto_apply' => false]);
            }
        });

        (new CreditAutoApply)->forCustomer($this->user->id, 'USD');

        $this->assertSame(Invoice::STATUS_PAID, $first->fresh()->status);
        $this->assertSame(0, $this->creditPayments($second));
    }

    /** Held: a payment started (processing) or a subscription set between selection and payment is seen. */
    public function test_a_processing_payment_or_a_subscription_appearing_during_a_pass_is_seen(): void
    {
        $service = $this->service();
        $first = $this->invoice(10, '+1 day');
        $second = $this->invoice(10, '+2 days');
        $third = $this->invoice(10, '+3 days', Service::class, $service->id);
        $this->giveCredit(5000);
        InvoiceTransaction::created(function (InvoiceTransaction $transaction) use ($first, $second, $service) {
            if ((int) $transaction->invoice_id === $first->id && $transaction->is_credit_transaction) {
                DB::table('invoice_transactions')->insert(['invoice_id' => $second->id, 'amount' => 10, 'status' => InvoiceTransactionStatus::Processing->value,
                    'transaction_id' => 'pi_race', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('services')->where('id', $service->id)->update(['subscription_id' => 'sub_race']);
            }
        });

        (new CreditAutoApply)->forCustomer($this->user->id, 'USD');

        $this->assertSame(1, $this->creditPayments($first));
        $this->assertSame(0, $this->creditPayments($second));
        $this->assertSame(0, $this->creditPayments($third));
        $this->assertSame(4000, $this->balanceCents());
    }

    /** Held: other currency, draft, cancelled, paid and a credit purchase mixed with other lines are never paid. */
    public function test_nothing_outside_the_rules_is_paid(): void
    {
        $this->giveCredit(100000);
        $eur = $this->invoice(10, currency: 'EUR');
        $draft = $this->invoice(10);
        $draft->forceFill(['status' => Invoice::STATUS_DRAFT])->saveQuietly();
        $cancelled = $this->invoice(10);
        $cancelled->update(['status' => Invoice::STATUS_CANCELLED]);
        $mixed = $this->invoice(10, reference: Credit::class);
        $mixed->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 5]);

        (new CreditAutoApply)->sweep();
        foreach ([$eur, $draft, $cancelled, $mixed] as $invoice) {
            $this->assertNull((new CreditAutoApply)->toInvoice($invoice->id));
            $this->assertSame(0, $this->creditPayments($invoice), "invoice {$invoice->id} ({$invoice->fresh()->status})");
        }
        $this->assertSame(100000, $this->balanceCents());

        config(['settings.credits_enabled' => false]);
        $plain = $this->invoice(10);
        $this->assertNull((new CreditAutoApply)->toInvoice($plain));
        $this->assertSame(0, (new CreditAutoApply)->sweep());
        ExtensionHelper::addPayment($this->invoice(10)->id, 'Stripe', 50, null, 'pi_off', currency: 'USD');
        $this->assertSame(0, $this->creditPayments($plain));
    }

    /** Held: credit in one currency pays only that currency's invoices. */
    public function test_eur_credit_pays_only_eur(): void
    {
        $usd = $this->invoice(10, '-3 days');
        $eur = $this->invoice(10, '+3 days', currency: 'EUR');
        $this->giveCredit(1000, currency: 'EUR');

        (new CreditAutoApply)->sweep();

        $this->assertSame(0, $this->creditPayments($usd));
        $this->assertSame(Invoice::STATUS_PAID, $eur->fresh()->status);
        $this->assertSame(0, $this->balanceCents(currency: 'EUR'));
    }

    // ---------------------------------------------------------------- J2: never more, negative pays nothing

    /** Held: negative credit pays nothing, and an overpayment that only partly repays the debt pays nothing either. */
    public function test_negative_credit_pays_nothing(): void
    {
        $overpaid = $this->invoice(100, '+10 days');
        $open = $this->invoice(10, '+1 day');
        // 50 of credit from an overpayment, 10 spent at once on $open, the 40 left taken by staff; the payment deleted: -50 owed.
        ExtensionHelper::addPayment($overpaid->id, 'Stripe', 150, null, 'pi_neg', currency: 'USD');
        $this->assertSame(4000, $this->balanceCents());
        (new CreditLedger)->remove($this->user->id, 'USD', 4000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
        (new RecordPaymentService)->deleteTransaction($overpaid->transactions()->where('transaction_id', 'pi_neg')->sole(), allowNegativeCredit: true);
        $this->assertSame(-5000, $this->balanceCents());

        $third = $this->invoice(30, '+2 days');
        $this->assertNull((new CreditAutoApply)->toInvoice($third));
        $this->assertSame(0, (new CreditAutoApply)->sweep());
        $this->assertSame(-5000, $this->balanceCents());
        $this->assertSame(0, $this->creditPayments($third));
        $this->assertJournalHolds();
    }

    /**
     * FINDING (J2/J8, pre-existing in CreditLedger, made more reachable by MT-1e: every overpayment's credit is now
     * spent at once, so deleting a payment drives credit negative much more often). A payment whose excess only partly
     * repays a negative balance is REFUSED as a whole: CreditLedger::add() refuses a balance that stays below zero
     * («Not enough account credit»), RecordPaymentService::handle rolls back, and money that arrived at the gateway is
     * not recorded (the webhook fails forever; staff «Add Payment» fails too).
     */
    public function test_an_overpayment_that_only_partly_repays_negative_credit_is_recorded(): void
    {
        $overpaid = $this->invoice(100, '+10 days');
        ExtensionHelper::addPayment($overpaid->id, 'Stripe', 150, null, 'pi_debt', currency: 'USD');
        (new CreditLedger)->remove($this->user->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
        (new RecordPaymentService)->deleteTransaction($overpaid->transactions()->sole(), allowNegativeCredit: true);
        $this->assertSame(-5000, $this->balanceCents());
        $third = $this->invoice(30, '+2 days');

        // 5 more than the 100 open: the debt goes to -45; nothing is paid from credit.
        $transaction = ExtensionHelper::addPayment($overpaid->id, 'Stripe', 105, null, 'pi_debt2', currency: 'USD');

        $this->assertNotNull($transaction);
        $this->assertSame(Invoice::STATUS_PAID, $overpaid->fresh()->status);
        $this->assertSame(-4500, $this->balanceCents());
        $this->assertSame(0, $this->creditPayments($third));
        $this->assertJournalHolds();
    }

    /** Held: a webhook replayed with an overpayment applies its credit once. */
    public function test_a_replayed_overpayment_applies_once(): void
    {
        $paid = $this->invoice(100, '+10 days');
        $other = $this->invoice(30, '+1 day');
        $third = $this->invoice(30, '+2 days');

        ExtensionHelper::addPayment($paid->id, 'Stripe', 140, null, 'pi_replay', currency: 'USD');
        ExtensionHelper::addPayment($paid->id, 'Stripe', 140, null, 'pi_replay', currency: 'USD');

        $this->assertSame(Invoice::STATUS_PAID, $other->fresh()->status);
        $this->assertSame(20.0, $third->fresh()->remaining);
        $this->assertSame(0, $this->balanceCents());
        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'pi_replay')->count());
        $this->assertJournalHolds();
    }

    /** Held: an opted-out customer's overpayment stays credit. */
    public function test_an_opted_out_overpayment_stays_credit(): void
    {
        $this->user->forceFill(['credit_auto_apply' => false])->save();
        $paid = $this->invoice(100);
        $other = $this->invoice(30, '+1 day');

        ExtensionHelper::addPayment($paid->id, 'Stripe', 150, null, 'pi_kept', currency: 'USD');

        $this->assertSame(0, $this->creditPayments($other));
        $this->assertSame(5000, $this->balanceCents());
    }

    /**
     * FINDING (J4, low). The credit an overpayment brings is applied in a DB::afterCommit callback registered AFTER the
     * paid invoice's ProcessPaidInvoiceService (InvoiceObserver::updated). When renewing that invoice's service fails,
     * the exception stops the remaining after-commit callbacks: the overpayment's credit is not applied (only the
     * daily pass picks it up), although the comment says «the payment stands; the daily pass tries again» only for a
     * failure of the auto-apply itself.
     */
    public function test_a_failed_renewal_of_the_paid_invoice_does_not_skip_applying_its_overpayment(): void
    {
        $this->markTestIncomplete('Kept as is in MT-1e: a failed renewal is reported, and the daily pass applies the credit the next morning.');
        $service = $this->service();
        $paid = $this->invoice(10, '+10 days', Service::class, $service->id);
        $other = $this->invoice(30, '+1 day');
        Service::updating(function () {
            throw new \RuntimeException('provisioning down');
        });

        try {
            ExtensionHelper::addPayment($paid->id, 'Stripe', 40, null, 'pi_renew_fail', currency: 'USD');
        } catch (\RuntimeException $e) {
            $this->assertSame('provisioning down', $e->getMessage());
        }

        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status, 'the payment itself stands');
        $this->assertSame(Invoice::STATUS_PAID, $other->fresh()->status, 'the overpayment credit was not applied: '
            . $this->balanceCents() . ' cents left as credit');
    }

    // ---------------------------------------------------------------- J4: subscriptions

    /** Held: a PayPal subscription sale's credit pays the subscription's services, not an older unrelated invoice. */
    public function test_a_paypal_sale_credit_goes_to_the_subscription_first(): void
    {
        $older = $this->invoice(20, '-10 days');
        $a = $this->service(['subscription_id' => 'I-ADV']);
        $b = $this->service(['subscription_id' => 'I-ADV']);
        $invoiceA = $this->invoice(10, '+1 day', Service::class, $a->id);
        $invoiceB = $this->invoice(10, '+1 day', Service::class, $b->id);

        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-ADV', 'billing_agreement_id' => 'I-ADV', 'amount' => ['total' => '20.00', 'currency' => 'USD']]);
        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-ADV', 'billing_agreement_id' => 'I-ADV', 'amount' => ['total' => '20.00', 'currency' => 'USD']]);

        $this->assertSame(Invoice::STATUS_PAID, $invoiceA->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoiceB->fresh()->status);
        $this->assertSame(0, $this->creditPayments($older));
        $this->assertSame(0, $this->balanceCents());
        $this->assertJournalHolds();
    }

    /** Held: what a subscription sale leaves after its services waits for the daily pass, then pays oldest first. */
    public function test_a_subscription_sales_leftover_is_applied_by_the_daily_pass(): void
    {
        $older = $this->invoice(20, '-10 days');
        $a = $this->service(['subscription_id' => 'I-LEFT']);
        $invoiceA = $this->invoice(10, '+1 day', Service::class, $a->id);

        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-LEFT', 'billing_agreement_id' => 'I-LEFT', 'amount' => ['total' => '25.00', 'currency' => 'USD']]);
        $this->assertSame(1500, $this->balanceCents());
        $this->assertSame(0, $this->creditPayments($older));

        (new CreditAutoApply)->sweep();
        $this->assertSame(Invoice::STATUS_PAID, $invoiceA->fresh()->status);
        $this->assertSame(5.0, $older->fresh()->remaining);
        $this->assertSame(0, $this->balanceCents());
        $this->assertJournalHolds();
    }

    // ---------------------------------------------------------------- J6: notification and numbering

    /** Held: the notification states what was applied and what is left, once per partial application. */
    public function test_the_notification_has_the_amounts_and_is_sent_once_per_partial_application(): void
    {
        $paidInFull = $this->invoice(20, '+1 day');
        $partial = $this->invoice(100, '+2 days');
        $this->giveCredit(5000);

        (new CreditAutoApply)->forCustomer($this->user->id, 'USD');
        (new CreditAutoApply)->forCustomer($this->user->id, 'USD'); // nothing left: nothing more is said

        $this->assertSame(Invoice::STATUS_PAID, $paidInFull->fresh()->status);
        $note = Notification::query()->where('user_id', $this->user->id)->where('title', 'Account credit applied')->sole();
        $this->assertStringContainsString('#' . $partial->id, $note->body);
        $this->assertStringContainsString('30.00', $note->body, 'the credit applied');
        $this->assertStringContainsString('70.00', $note->body, 'what is left to pay');
    }

    /** Held: a notification that fails never undoes the credit payment. */
    public function test_a_failing_notification_does_not_undo_the_payment(): void
    {
        $invoice = $this->invoice(100);
        $this->giveCredit(3000);
        Notification::creating(function () {
            throw new \RuntimeException('mail down');
        });

        $transaction = (new CreditAutoApply)->toInvoice($invoice);

        $this->assertNotNull($transaction);
        $this->assertSame(70.0, $invoice->fresh()->remaining);
        $this->assertSame(0, $this->balanceCents());
        $this->assertJournalHolds();
    }

    /** Held: with proforma numbering ({YEAR}-{NUMBER}), a partial credit payment gives no number; paying it in full does. */
    public function test_proforma_numbering_only_on_full_payment(): void
    {
        config(['settings.invoice_proforma' => true, 'settings.invoice_number_format' => '{year}-{number}']);
        $invoice = $this->invoice(100);
        $this->assertNull($invoice->number);
        $this->giveCredit(3000);

        (new CreditAutoApply)->toInvoice($invoice);
        $this->assertNull($invoice->fresh()->number, 'a partly paid proforma got a number');

        $this->giveCredit(7000);
        (new CreditAutoApply)->toInvoice($invoice);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(now()->format('Y') . '-1', $invoice->fresh()->number);
    }

    // ---------------------------------------------------------------- J7: the toggle, the migration

    /** Held: the client toggle changes only the signed-in customer's own flag. */
    public function test_the_client_toggle_changes_only_the_own_flag(): void
    {
        Setting::query()->updateOrCreate(['key' => 'credits_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        $victim = User::factory()->create();
        $this->actingAs($this->user);

        Livewire::test(Credits::class)->set('autoApply', false)->set('autoApply', true)->set('autoApply', false);

        $this->assertFalse($this->user->fresh()->credit_auto_apply);
        $this->assertTrue($victim->fresh()->credit_auto_apply);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_staff_switch_it_off_on_the_customer_form(): void
    {
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        Livewire::actingAs($owner)->test(EditUser::class, ['record' => $this->user->getRouteKey()])
            ->assertFormSet(['credit_auto_apply' => true])
            ->fillForm(['credit_auto_apply' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->user->fresh()->credit_auto_apply);
    }

    /** Held: the migration goes down and up, and its template is there once, also after the seeder. */
    public function test_the_migration_goes_down_and_up_with_one_template(): void
    {
        $migration = require base_path('database/migrations/2026_10_18_000000_credit_auto_apply.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'credit_auto_apply'));
        $this->assertSame(0, DB::table('notification_templates')->where('key', 'credit_applied')->count());

        $migration->up();
        (new EmailTemplateSeeder)->run();
        $this->assertTrue(Schema::hasColumn('users', 'credit_auto_apply'));
        $this->assertSame(1, DB::table('notification_templates')->where('key', 'credit_applied')->count());
        $this->assertTrue((bool) DB::table('users')->where('id', $this->user->id)->value('credit_auto_apply'));
    }

    /**
     * FINDING (J1, medium). A customer asked to cancel a service at the end of its period while its renewal invoice was
     * already open. Before MT-1e credit was only spent when the cron made a renewal (never for a service with a
     * cancellation request). Now the daily pass (and any overpayment) pays that open renewal from credit and renews a
     * service the customer is leaving: their credit is spent on a period they cancelled.
     */
    public function test_credit_does_not_renew_a_service_the_customer_asked_to_cancel(): void
    {
        $service = $this->service(['expires_at' => now()->addDays(3)]);
        $renewal = $this->invoice(10, '+3 days', Service::class, $service->id);
        $service->cancellation()->create(['type' => 'end_of_period', 'reason' => 'Leaving']);
        $this->giveCredit(1000);

        (new CreditAutoApply)->sweep();

        $this->assertSame(0, $this->creditPayments($renewal), 'credit paid the renewal of a service with an end-of-period cancellation');
        $this->assertSame(1000, $this->balanceCents());
    }

    // ---------------------------------------------------------------- J8: MT-1c rules

    /** Held: deleting an auto-applied credit payment gives the credit back and reopens the invoice. */
    public function test_deleting_an_auto_applied_credit_payment_gives_it_back(): void
    {
        $invoice = $this->invoice(40);
        $this->giveCredit(10000);
        $transaction = (new CreditAutoApply)->toInvoice($invoice);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        (new RecordPaymentService)->deleteTransaction($transaction);

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(10000, $this->balanceCents());
        $this->assertJournalHolds();
    }

    // ---------------------------------------------------------------- fuzz

    /** @return array<string, array{int}> */
    public static function seeds(): array
    {
        return ['seed 7' => [7], 'seed 42' => [42], 'seed 1337' => [1337]];
    }

    #[DataProvider('seeds')]
    public function test_fuzz(int $seed): void
    {
        mt_srand($seed);
        $this->watchCreditPayments();
        $users = [$this->user, User::factory()->create()];
        $subscriptionService = $this->service(['subscription_id' => 'I-FUZZ']);
        $plainService = $this->service();
        $invoices = [];
        $txn = 0;
        $log = [];

        for ($step = 0; $step < 45; $step++) {
            $user = $users[mt_rand(0, 1)];
            $op = mt_rand(0, 13);
            try {
                switch ($op) {
                    case 0: case 1:
                        $kind = mt_rand(0, 5);
                        [$ref, $refId] = match ($kind) {
                            0 => [Credit::class, null],
                            1 => [Service::class, $user->is($this->user) ? $subscriptionService->id : null],
                            2 => [Service::class, $user->is($this->user) ? $plainService->id : null],
                            default => [null, null],
                        };
                        if ($ref === Service::class && $refId === null) {
                            $ref = null;
                        }
                        $invoices[] = $this->invoice(mt_rand(1, 60), (mt_rand(-20, 20)) . ' days', $ref, $refId, $user, mt_rand(0, 6) === 0 ? 'EUR' : 'USD');
                        $log[] = 'new invoice ' . end($invoices)->id;
                        break;
                    case 2: case 3:
                        if ($invoices) {
                            $invoice = $invoices[array_rand($invoices)];
                            $amount = mt_rand(1, 80);
                            ExtensionHelper::addPayment($invoice->id, 'Stripe', $amount, null, 'pi_f' . (++$txn), currency: $invoice->currency_code);
                            $log[] = "pay {$amount} on {$invoice->id}";
                        }
                        break;
                    case 4:
                        if ($invoices) {
                            $invoice = $invoices[array_rand($invoices)];
                            ExtensionHelper::addPayment($invoice->id, 'Stripe', mt_rand(1, 50), null, 'pi_f' . (++$txn), InvoiceTransactionStatus::Processing, currency: $invoice->currency_code);
                            $log[] = "processing on {$invoice->id}";
                        }
                        break;
                    case 5:
                        $processing = InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Processing->value)->inRandomOrder()->first();
                        if ($processing) {
                            ExtensionHelper::addPayment($processing->invoice_id, 'Stripe', $processing->amount, null, $processing->transaction_id);
                            $log[] = "complete {$processing->transaction_id}";
                        }
                        break;
                    case 6:
                        $this->giveCredit(mt_rand(1, 6000), $user, mt_rand(0, 4) === 0 ? 'EUR' : 'USD');
                        $log[] = 'credit +';
                        break;
                    case 7:
                        $user->forceFill(['credit_auto_apply' => !$user->fresh()->credit_auto_apply])->save();
                        $log[] = 'flip opt-out ' . $user->id;
                        break;
                    case 8: case 9:
                        (new CreditAutoApply)->sweep();
                        $log[] = 'sweep';
                        $this->assertSweptClean($users, $log);
                        break;
                    case 10:
                        $transaction = InvoiceTransaction::query()->inRandomOrder()->first();
                        if ($transaction) {
                            (new RecordPaymentService)->deleteTransaction($transaction, allowNegativeCredit: true);
                            $log[] = "delete txn {$transaction->id}";
                        }
                        break;
                    case 11:
                        $transaction = InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Succeeded->value)
                            ->whereNotNull('gateway_id')->inRandomOrder()->first();
                        if ($transaction && $transaction->refundable_amount > 0) {
                            ExtensionHelper::refundLocally($transaction, round(min((float) $transaction->refundable_amount, mt_rand(1, 30)), 2));
                            $log[] = "refund {$transaction->id}";
                        }
                        break;
                    case 12:
                        if ($invoices) {
                            (new CreditAutoApply)->toInvoice($invoices[array_rand($invoices)]->id);
                            $log[] = 'toInvoice';
                        }
                        break;
                    case 13:
                        config(['settings.credits_auto_use' => mt_rand(0, 3) !== 0]);
                        $log[] = 'auto_use ' . var_export(config('settings.credits_auto_use'), true);
                        break;
                }
            } catch (DisplayException|\InvalidArgumentException $e) {
                $log[] = 'refused: ' . $e->getMessage();
            }

            $context = "seed {$seed} step {$step}\n" . implode("\n", array_slice($log, -12));
            $this->assertSame([], $this->violations, $context);
            $this->assertSame(0, Artisan::call('mycs:credits-check'), $context . "\n" . Artisan::output());
            foreach (Invoice::query()->where('status', Invoice::STATUS_PAID)->get() as $paid) {
                $this->assertLessThanOrEqual(0, $paid->remaining, "paid invoice #{$paid->id} with remaining > 0\n" . $context);
            }
            $this->assertSame(0, MoneyEvent::query()->where('kind', 'payment')->whereNull('invoice_transaction_id')->count(), $context);
        }
    }

    /** After a sweep, a customer who still has credit has no open invoice credit could have paid. */
    private function assertSweptClean(array $users, array $log): void
    {
        if (!CreditAutoApply::enabled()) {
            return;
        }
        foreach ($users as $user) {
            if (!$user->fresh()->credit_auto_apply) {
                continue;
            }
            foreach (['USD', 'EUR'] as $currency) {
                if ($this->balanceCents($user, $currency) <= 0) {
                    continue;
                }
                $open = Invoice::query()->where('user_id', $user->id)->where('currency_code', $currency)->where('status', Invoice::STATUS_PENDING)->get()
                    ->filter(fn (Invoice $invoice) => $invoice->remaining > 0 && CreditAutoApply::eligible($invoice));
                $this->assertSame([], $open->pluck('id')->all(), "credit left unused for user {$user->id} {$currency}\n" . implode("\n", array_slice($log, -12)));
            }
        }
    }
}
