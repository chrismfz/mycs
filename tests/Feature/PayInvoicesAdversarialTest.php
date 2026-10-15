<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\PayInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR F1 (feat/mass-pay): «Add payment to invoices» (PayInvoices) and «Apply credit».
 * Tests named test_f_adv_N_* failed on 204a38ac (real findings, fixed with the code review's CR-1/CR-3); test_held_* are
 * attacks that held and stay as regressions.
 */
class PayInvoicesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        config(['settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
        $this->user->client()->update(['separate_invoices' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, array{0: mixed, 1: string}>  $settings */
    private function settings(array $settings): void
    {
        foreach ($settings as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function service(array $attributes = [], ?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => ($user ?? $this->user)->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    /** @return Collection<int, Invoice> three renewal invoices: 10.00 due the 25th, 15.00 the 26th, 20.00 the 24th */
    private function three(): Collection
    {
        $this->service();
        $this->service(['expires_at' => '2026-10-26', 'price' => 15.00]);
        $this->service(['expires_at' => '2026-10-24', 'price' => 20.00]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->orderBy('id')->get();
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function credit(): string
    {
        return $this->money((new CreditLedger)->balanceCents($this->user->client()->id, 'USD') / 100);
    }

    /** MoneyCheck findings, without the time-based ones a test cannot reach. */
    private function moneyFindings(): array
    {
        return (new MoneyCheck)->run(array_values(array_diff(MoneyCheck::RULES, ['paid_not_applied', 'gateway_received'])));
    }

    /** @param  list<int>  $ids */
    private function refused(array $ids, mixed $amount, ?string $transactionId = null): ?string
    {
        try {
            (new PayInvoices)->recordByStaff($ids, null, $amount, $transactionId);
        } catch (DisplayException $e) {
            return $e->getMessage();
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings (fail on the PR)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * F-ADV-1 (medium). A customer whose account credit is negative (decision 1: a deleted payment whose credit was
     * already spent; they owe it). Staff record a 45.00 transfer for exactly the three invoices it pays (20 + 10 + 15).
     * PayInvoices puts it on the first and spreads its credited part with allocateFromTransaction, which caps every
     * allocation at the customer's credit balance: the -5.00 debt is silently paid first out of the transfer, and the
     * last chosen invoice stays open with 5.00, while staff read «Payment recorded on 3 invoices». The money staff
     * pointed at these invoices should pay them (the debt stays a debt), or the action should refuse/say so.
     */
    public function test_f_adv_1_negative_credit_silently_eats_a_payment_meant_for_the_chosen_invoices(): void
    {
        $invoices = $this->three();
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF,
            note: 'owed', mayGoNegative: true);
        $this->assertSame('-5.00', $this->credit());

        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '45.00', 'wire-neg');

        $this->assertSame(['paid', 'paid', 'paid'], $invoices->map(fn (Invoice $i) => $i->fresh()->status)->all(),
            'a 45.00 transfer for 45.00 of chosen invoices left one of them open: the debt took 5.00 of it');
        $this->assertSame('-5.00', $this->credit());
    }

    /**
     * F-ADV-2 (low/medium). Every «Add payment to invoices» whose amount goes beyond the first invoice (i.e. every real
     * use) runs the first invoice's share through handle() as an overpayment: staff get the system mail «Payment needs
     * attention: Invoice #N could not take 25.00 of a payment; it was added to the customer's account credit» and the
     * payments log a warning, although the 25.00 paid the other chosen invoices in the same transaction and no credit
     * is left. A false alarm on every mass payment trains staff to ignore the real ones.
     */
    public function test_f_adv_2_an_exact_mass_payment_mails_staff_a_false_overpayment_alarm(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $invoices = $this->three();

        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '45.00', 'wire-alarm');

        $this->assertSame('0.00', $this->credit());
        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
        $this->assertSame([], EmailLog::query()->where('subject', 'Payment needs attention')->pluck('body')->all(),
            'staff were told a payment became credit, though it paid the invoices they chose');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Attacks that held (regressions)
    // ---------------------------------------------------------------------------------------------------------------

    public function test_held_amounts_that_are_not_a_positive_number_are_refused(): void
    {
        $ids = $this->three()->pluck('id')->all();

        foreach (['0', '0.00', '0.004', '-45', 'abc', '', null, '1e13', 'INF', true, [], '45,00'] as $amount) {
            $this->assertNotNull($this->refused($ids, $amount), 'accepted ' . var_export($amount, true));
        }
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_held_one_invoice_or_the_same_one_twice_is_refused(): void
    {
        $invoices = $this->three();

        $this->assertNotNull($this->refused([$invoices[0]->id], '10.00'));
        $this->assertNotNull($this->refused([$invoices[0]->id, $invoices[0]->id, (string) $invoices[0]->id], '10.00'));
        $this->assertNotNull($this->refused([$invoices[0]->id, 999999], '10.00'), 'a missing invoice');
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_held_draft_cancelled_or_merged_invoices_are_refused_and_nothing_is_recorded(): void
    {
        $invoices = $this->three();
        foreach ([Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED] as $status) {
            $invoices[2]->forceFill(['status' => $status])->save();
            $this->assertNotNull($this->refused($invoices->pluck('id')->all(), '45.00', 'wire-s'), $status);
        }
        $invoices[2]->forceFill(['status' => Invoice::STATUS_CANCELLED, 'merged_into_id' => $invoices[0]->id])->save();
        $this->assertNotNull($this->refused($invoices->pluck('id')->all(), '45.00', 'wire-s'), 'merged');
        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('0.00', $this->credit());
    }

    public function test_held_an_invoice_in_another_currency_or_without_a_customer_is_refused(): void
    {
        $invoices = $this->three();
        DB::table('invoices')->where('id', $invoices[1]->id)->update(['currency_code' => 'EUR']);
        $this->assertNotNull($this->refused($invoices->pluck('id')->all(), '45.00'));
        DB::table('invoices')->where('id', $invoices[1]->id)->update(['currency_code' => 'USD', 'client_id' => null]);
        $this->assertNotNull($this->refused($invoices->pluck('id')->all(), '45.00'));
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_held_a_replayed_transaction_id_records_nothing_on_other_invoices(): void
    {
        $invoices = $this->three();
        (new PayInvoices)->recordByStaff([$invoices[0]->id, $invoices[1]->id], null, '25.00', 'wire-r');
        $this->service(['expires_at' => '2026-10-23', 'price' => 7.00]);
        $this->service(['expires_at' => '2026-10-22', 'price' => 8.00]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $open = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->where('id', '!=', $invoices[2]->id)->pluck('id')->all();
        $this->assertCount(2, $open);
        $before = InvoiceTransaction::query()->count();

        $this->assertNotNull($this->refused($open, '15.00', 'wire-r'));

        $this->assertSame($before, InvoiceTransaction::query()->count());
        $this->assertSame(2, Invoice::query()->whereKey($open)->where('status', Invoice::STATUS_UNPAID)->count());
        $this->assertSame([], $this->moneyFindings());
    }

    public function test_held_the_id_of_a_processing_payment_on_a_later_invoice_is_refused_and_rolled_back(): void
    {
        $invoices = $this->three(); // due: [1] 26th, [0] 25th, [2] 24th (first)
        $processing = $invoices[1]->transactions()->create(['amount' => 15, 'status' => InvoiceTransactionStatus::Processing, 'transaction_id' => 'sepa-9']);

        $this->assertNotNull($this->refused($invoices->pluck('id')->all(), '45.00', 'sepa-9'));

        $this->assertSame(InvoiceTransactionStatus::Processing, $processing->fresh()->status);
        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('0.00', $this->credit());
        $this->assertSame(3, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
    }

    public function test_held_a_backdated_mass_payment_partly_refunded_and_unwound_keeps_the_money_check_clean(): void
    {
        $invoices = $this->three();
        [$payment, $others] = (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '50.00', 'wire-u',
            Carbon::parse('2026-10-15 09:00:00'));
        $this->assertSame('2026-10-15', $payment->created_at->toDateString());
        $this->assertSame('5.00', $this->credit());
        $this->assertSame([], $this->moneyFindings());

        // Only the part on its own invoice is refundable from the payment; the rest paid the others.
        $this->assertSame('20.00', $this->money($payment->fresh()->refundable_amount));
        try {
            ExtensionHelper::refundLocally($payment->fresh(), 21.00, notify: false);
            $this->fail('refunded more than what is on its invoice');
        } catch (\InvalidArgumentException) {
        }

        // Unwind: each allocation back to credit, then the payment itself.
        foreach ($others as $allocation) {
            (new RecordPaymentService)->deleteTransaction($allocation->fresh());
        }
        $this->assertSame('30.00', $this->credit());
        (new RecordPaymentService)->deleteTransaction($payment->fresh());
        $this->assertSame('0.00', $this->credit());
        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame([], $this->moneyFindings());
    }

    public function test_held_less_than_the_first_invoice_pays_it_in_part_and_nothing_else(): void
    {
        $invoices = $this->three();
        [$payment, $others] = (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '7.50', 'wire-p');

        $this->assertSame([], $others);
        $this->assertSame('0.00', $this->money($payment->credited_amount));
        $this->assertSame('12.50', $this->money($invoices[2]->fresh()->remaining));
        $this->assertSame('0.00', $this->credit());
        $this->assertSame([], $this->moneyFindings());
    }

    public function test_held_with_auto_use_on_the_chosen_invoices_are_paid_before_an_earlier_unchosen_one(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $chosen = $this->three();
        $this->service(['expires_at' => '2026-10-21', 'price' => 30.00]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $unchosen = Invoice::query()->whereNotIn('id', $chosen->pluck('id'))->sole();

        (new PayInvoices)->recordByStaff($chosen->pluck('id')->all(), null, '50.00', 'wire-a');

        $this->assertSame(['paid', 'paid', 'paid'], $chosen->map(fn (Invoice $i) => $i->fresh()->status)->all());
        // What is left after the chosen ones is credit, and credit pays the customer's other open invoices (decision 5).
        $this->assertSame('25.00', $this->money($unchosen->fresh()->remaining));
        $this->assertSame('0.00', $this->credit());
        $this->assertSame([], $this->moneyFindings());
    }

    public function test_held_with_credits_disabled_the_payment_still_pays_every_chosen_invoice(): void
    {
        $this->settings(['credits_enabled' => [false, 'boolean']]);
        $invoices = $this->three();

        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '45.00', 'wire-off');

        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
        $this->assertSame('0.00', $this->credit());
        $this->assertSame([], $this->moneyFindings());
    }

    public function test_held_existing_positive_credit_is_not_spent_by_the_mass_payment(): void
    {
        $invoices = $this->three();
        (new CreditLedger)->add($this->user->client()->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');

        [, $others] = (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '25.00', 'wire-c');

        // 20 on the first, 5 on the next: the customer's own 10.00 stays where it was.
        $this->assertSame('5.00', $this->money(collect($others)->sum('amount')));
        $this->assertSame('10.00', $this->credit());
        $this->assertSame([], $this->moneyFindings());
    }

    public function test_held_staff_without_add_payment_cannot_pay_invoices_or_apply_credit(): void
    {
        $invoices = $this->three();
        (new CreditLedger)->add($this->user->client()->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update'])->create();

        try {
            $this->livewireAs($staff)->test(ListInvoices::class)
                ->selectTableRecords($invoices->pluck('id')->all())
                ->callAction(TestAction::make('payInvoices')->table()->bulk(), data: ['amount' => '45.00', 'paid_at' => '2026-10-20']);
        } catch (\Throwable) {
        }
        try {
            $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoices[0]->id])
                ->callAction('applyCredit', data: ['amount' => '5.00']);
        } catch (\Throwable) {
        }

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('5.00', $this->credit());
    }

    public function test_held_immutable_invoices_block_the_bulk_payment(): void
    {
        $invoices = $this->three();
        config(['settings.immutable_invoices_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        try {
            $this->livewireAs($staff)->test(ListInvoices::class)
                ->selectTableRecords($invoices->pluck('id')->all())
                ->callAction(TestAction::make('payInvoices')->table()->bulk(), data: ['amount' => '45.00', 'paid_at' => '2026-10-20']);
        } catch (\Throwable) {
        }

        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_held_apply_credit_with_negative_or_no_credit_or_on_a_paid_invoice_does_nothing(): void
    {
        $invoices = $this->three();
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF,
            note: 'owed', mayGoNegative: true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoices[0]->id])->assertActionHidden('applyCredit');
        $this->assertNull((new RecordPaymentService)->payWithCredit($invoices[0], upToCents: 1000));

        (new CreditLedger)->add($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');
        (new RecordPaymentService)->handle($invoices[0], null, (float) $invoices[0]->total, transactionId: 'paid');
        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoices[0]->id])->assertActionHidden('applyCredit');
        $this->assertSame('45.00', $this->credit());
        $this->assertSame([], $this->moneyFindings());
    }

    public function test_held_apply_credit_rejects_zero_and_negative_amounts(): void
    {
        $invoice = $this->three()->first();
        (new CreditLedger)->add($this->user->client()->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        foreach (['0', '-3.00', '0.001'] as $amount) {
            $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->id])
                ->callAction('applyCredit', data: ['amount' => $amount])
                ->assertHasActionErrors(['amount']);
        }
        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('5.00', $this->credit());
    }

    public function test_held_a_payment_landing_on_a_subscription_invoice_pays_the_others_and_leaves_no_auto_spend(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $this->service(['expires_at' => '2026-10-24', 'price' => 20.00, 'subscription_id' => 'I-SUB1']);
        $this->service(['expires_at' => '2026-10-25']);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $invoices = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->orderBy('id')->get();
        $this->assertCount(2, $invoices);

        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '35.00', 'wire-sub');

        $this->assertSame(['paid', 'paid'], $invoices->map(fn (Invoice $i) => $i->fresh()->status)->all());
        $this->assertSame('5.00', $this->credit());
        $this->assertSame([], $this->moneyFindings());
    }

    /** Note of the review: «Apply credit» is not offered on a credit purchase (credit would buy credit). */
    public function test_apply_credit_is_not_offered_on_a_credit_purchase(): void
    {
        (new CreditLedger)->add($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');
        $buy = Invoice::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'due_at' => '2026-10-25', 'status' => Invoice::STATUS_UNPAID]);
        $buy->items()->create(['description' => 'Account credit', 'price' => 20, 'quantity' => 1, 'reference_type' => Credit::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->livewireAs(Staff::factory()->admin()->create())->test(EditInvoice::class, ['record' => $buy->id])->assertActionHidden('applyCredit');
    }
}
