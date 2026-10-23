<?php

namespace Tests\Feature;

use App\Classes\Settings;
use App\Helpers\ExtensionHelper;
use App\Mail\SystemMail;
use App\Models\Credit;
use App\Models\CronStat;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #385 (the cron's «Add Funds» cancellation). The failing tests are the findings (ADV-n); the
 * passing ones pin what was attacked and held: selection edges, setting values that are not a number, credit paid on
 * it given back, no mail to the customer.
 */
class AddFundsCancelAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->user = User::factory()->create();
        config(['settings.cronjob_add_funds_cancel' => 30]);
    }

    private function invoice(int $daysOld, array $lines, string $status = 'unpaid'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => $status, 'currency_code' => 'USD', 'due_at' => now()->subDays($daysOld)]);
        foreach ($lines as $line) {
            $invoice->items()->create($line + ['quantity' => 1, 'price' => 50]);
        }
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => now()->subDays($daysOld)]);

        return $invoice->fresh();
    }

    private function addFunds(int $daysOld = 31, string $status = 'unpaid'): Invoice
    {
        return $this->invoice($daysOld, [['reference_type' => Credit::class, 'description' => 'Add Funds']], $status);
    }

    private function balance(): int
    {
        $credit = Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->first();

        return $credit === null ? 0 : CreditLedger::cents($credit);
    }

    private function stat(): ?int
    {
        $value = CronStat::query()->where('key', 'add_funds_cancelled')->value('value');

        return $value === null ? null : (int) $value;
    }

    public function test_selection_edges(): void
    {
        $draft = $this->addFunds(status: 'draft');
        $empty = $this->invoice(31, []);
        $discount = $this->invoice(31, [['reference_type' => Credit::class, 'description' => 'Add Funds'], ['description' => 'Discount', 'price' => -5]]);
        $justOld = $this->addFunds(29);

        $this->artisan('app:cron-job');

        $this->assertSame(['draft', 'unpaid', 'unpaid', 'unpaid'],
            [$draft->fresh()->status, $empty->fresh()->status, $discount->fresh()->status, $justOld->fresh()->status]);
    }

    public function test_setting_values(): void
    {
        foreach (['', 'abc', null, '-3'] as $value) {
            config(['settings.cronjob_add_funds_cancel' => $value]);
            $old = $this->addFunds(400);
            $this->artisan('app:cron-job');
            $this->assertSame('unpaid', $old->fresh()->status, var_export($value, true));
        }
    }

    public function test_credit_paid_in_part_goes_back(): void
    {
        (new CreditLedger)->add($this->user->client()->id, 'USD', 2000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_SYSTEM, note: 'test');
        $old = $this->addFunds();
        (new RecordPaymentService)->payWithCredit($old->id);
        $this->assertSame(0, $this->balance());

        $this->artisan('app:cron-job');

        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame(2000, $this->balance());
    }

    /**
     * ADV-1 (low): a payment still processing (SEPA, a PayPal review) on an old «Add Funds» does not stop the cron. The
     * money still ends as credit when it settles, but as an overpayment on a cancelled invoice (with a credit note),
     * not as the purchase: the customer sees the invoice they are paying cancelled. Invoice::whereOverdue and
     * MassPay::refusal both treat a processing payment as «being paid»; this selection does not.
     */
    public function test_processing_payment_then_settles(): void
    {
        $old = $this->addFunds();
        ExtensionHelper::addProcessingPayment($old->id, null, 50, null, 'sepa-1', currency: 'USD');

        $this->artisan('app:cron-job');
        $status = $old->fresh()->status;

        ExtensionHelper::addPayment($old->id, null, 50, null, 'sepa-1', currency: 'USD');

        $this->assertSame(5000, $this->balance(), 'cron left it ' . $status);
        $this->assertSame('unpaid', $status, 'cancelled while its payment was processing');
    }

    public function test_count_and_mail(): void
    {
        Mail::fake();
        $old = $this->addFunds();
        $partPaid = $this->addFunds();
        ExtensionHelper::addPayment($partPaid->id, null, 10.00, null, 'part-1', currency: 'USD');

        $this->artisan('app:cron-job');

        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame(1, $this->stat());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    /**
     * ADV-2 (low): the step returns count(selected) - count(left open). An invoice paid (or cancelled by staff) between
     * the selection and its lock is skipped by CancelOpenInvoices (returns null) yet counted as cancelled in CronStat.
     */
    public function test_paid_between_select_and_lock_is_not_counted(): void
    {
        $old = $this->addFunds();
        $done = false;
        Invoice::retrieved(function (Invoice $invoice) use ($old, &$done) {
            if (!$done && $invoice->id === $old->id) {
                $done = true;
                DB::table('invoices')->where('id', $old->id)->update(['status' => 'paid']);
            }
        });

        $this->artisan('app:cron-job');

        $this->assertSame('paid', DB::table('invoices')->where('id', $old->id)->value('status'));
        $this->assertSame(0, $this->stat());
    }

    /**
     * ADV-3 (low): an «Add Funds» part paid by a gateway is left open with CancelOpenInvoices' generic notice «refund it
     * or move it to credit, then cancel it». Moving a credit purchase's payment to credit is refused
     * (ExtensionHelper::refundToCredit: «A credit purchase is not refunded to credit»): the notice tells staff to do
     * what the app will not do. (The notice then repeats on every run until staff act, as the part-paid orders' does.)
     */
    public function test_part_paid_notice_does_not_ask_for_what_is_refused(): void
    {
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $partPaid = $this->addFunds();
        ExtensionHelper::addPayment($partPaid->id, null, 10.00, null, 'part-1', currency: 'USD');

        $this->artisan('app:cron-job');

        $bodies = [];
        foreach ([...Mail::sent(SystemMail::class), ...Mail::queued(SystemMail::class)] as $mail) {
            $bodies[] = html_entity_decode((string) json_encode($mail->mail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
        }
        $all = implode("\n", $bodies);
        $this->assertStringContainsString("#{$partPaid->id} left open", $all);
        $this->assertStringNotContainsString('move it to credit', $all);
    }

    /**
     * ADV-4 (nit, as #383's): the form takes a decimal (type number, no integer rule) and the cron truncates it, so
     * «1.5» days cancels after 1, earlier than staff set. A whole-number rule on the setting, as the other day counts.
     */
    public function test_setting_takes_whole_days_only(): void
    {
        $setting = collect(Settings::settings())->flatten(1)->firstWhere('name', 'cronjob_add_funds_cancel');

        $this->assertNotNull($setting);
        $this->assertStringContainsString('integer', implode('|', (array) ($setting['validation'] ?? [])));
    }
}
