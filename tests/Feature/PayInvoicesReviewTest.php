<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\PayInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent code review of PR F1 (PayInvoices / ApplyCreditAction). Tests named *_guard pin behaviour that is correct;
 * the others proved the findings CR-1…CR-3 of the review (they failed on 204a38a).
 */
class PayInvoicesReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
        $this->user->client()->update(['separate_invoices' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    /** @return Collection<int, Invoice> 10.00 due the 25th, 15.00 the 26th, 20.00 the 24th */
    private function three(): Collection
    {
        $this->service();
        $this->service(['expires_at' => '2026-10-26', 'price' => 15.00]);
        $this->service(['expires_at' => '2026-10-24', 'price' => 20.00]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->orderBy('id')->get();
    }

    private function credit(): int
    {
        return (new CreditLedger)->balanceCents($this->user->client()->id, 'USD');
    }

    /**
     * CR-1: every ordinary mass payment leaves an "excess" on the first invoice that is allocated in the same transaction,
     * yet RecordPaymentService::addCredit still logged a payments warning and mailed staff «Payment needs attention: …
     * could not take … added to the customer's account credit». Nothing needs attention: the credit is already spent.
     */
    public function test_a_normal_mass_payment_does_not_mail_staff_that_it_needs_attention(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $invoices = $this->three();

        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '45.00', 'wire-r1');

        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
        $this->assertSame(0, EmailLog::query()->where('subject', 'Payment needs attention')->count(),
            'staff were told a fully allocated payment became account credit');
    }

    /**
     * CR-2: the payment is dated on the day the transfer arrived (paidAt), its allocations were dated on the day staff
     * typed it in. The same money then showed on two dates (statements, revenue by date, and later the Ekdosi bridge).
     */
    public function test_allocations_carry_the_payment_date(): void
    {
        $invoices = $this->three();

        [$payment, $others] = (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '45.00', 'wire-r2',
            Carbon::parse('2026-10-15 09:00:00'));

        $this->assertSame('2026-10-15', $payment->created_at->toDateString());
        foreach ($others as $allocation) {
            $this->assertSame('2026-10-15', $allocation->fresh()->created_at->toDateString(), 'allocation dated on entry, not on payment');
        }
    }

    /**
     * CR-3: a customer whose credit is negative (staff confirmed it, decision 1) pays exactly what the chosen invoices
     * add up to. The allocation was capped by the (negative) credit row, not by the payment's own budget, so the payment
     * silently settled the old debt: with credit at -30.00, a 45.00 transfer for 20+10+15 paid only the first.
     */
    public function test_negative_credit_does_not_take_part_of_a_payment_staff_spread_over_invoices(): void
    {
        $invoices = $this->three();
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 3000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF,
            note: 'owed', mayGoNegative: true);

        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '45.00', 'wire-r4');

        $this->assertSame(['paid', 'paid', 'paid'], $invoices->map(fn (Invoice $i) => $i->fresh()->status)->all());
        $this->assertSame(-3000, $this->credit());
    }

    /** Guard: the journal and the credit cache agree after a mass payment, deleting an allocation, then the payment. */
    public function test_money_check_stays_clean_through_allocation_and_deletion_guard(): void
    {
        $invoices = $this->three();
        [$payment, $others] = (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '50.00', 'wire-r5');
        $this->assertSame(500, $this->credit());
        $this->assertSame([], (new MoneyCheck)->run());

        foreach ($others as $allocation) {
            (new RecordPaymentService)->deleteTransaction($allocation);
        }
        $this->assertSame(2500 + 500, $this->credit());
        (new RecordPaymentService)->deleteTransaction($payment->fresh());

        $this->assertSame(0, $this->credit());
        $this->assertSame(3, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
        $this->assertSame([], (new MoneyCheck)->run());
    }

    /** Guard: a typed id that a webhook already recorded on another invoice of the customer refuses the whole set. */
    public function test_a_replayed_transaction_id_records_nothing_guard(): void
    {
        $invoices = $this->three();
        (new RecordPaymentService)->handle($invoices[0], null, '1.00', transactionId: 'wire-r6');

        try {
            (new PayInvoices)->recordByStaff([$invoices[1]->id, $invoices[2]->id], null, '35.00', 'wire-r6');
            $this->fail('recorded a replay');
        } catch (DisplayException) {
        }
        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame(0, $this->credit());
    }
}
