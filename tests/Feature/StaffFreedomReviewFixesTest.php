<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Events\Invoice\Paid as InvoicePaid;
use App\Events\Invoice\PaidByStaffCovered;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Observers\InvoiceItemObserver;
use App\Services\Ekdosi\EkdosiFeed;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use App\Services\Reports\IncomeReport;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Others\Affiliates\Listeners\RewardAffiliate;
use MyCS\Extensions\Others\Affiliates\Models\Affiliate;
use MyCS\Extensions\Others\Affiliates\Models\AffiliateOrder;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * What the review of #780 asked for (REV-780-1 … -8), on invoices the WHMCS importer brought: a service (31, monthly,
 * paid in WHMCS to 2026-10-10 by invoice 480) and its next renewal (502), unpaid. docs/staff-freedom.md.
 */
class StaffFreedomReviewFixesTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private Invoice $invoice;

    private Staff $staff;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->user->client()->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        $this->importWhmcs();
        $this->invoice = $this->imported(502);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function byHand(?Invoice $invoice = null): Invoice
    {
        $invoice = ($invoice ?? $this->invoice)->fresh();
        $invoice->paid_without_payment = true;
        $invoice->update(['status' => Invoice::STATUS_PAID]);

        return $invoice->fresh();
    }

    private function token(array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => $this->staff->id, 'name' => 'f', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    private function credit(?int $clientId = null): string
    {
        return number_format((float) (Credit::query()->where('client_id', $clientId ?? $this->invoice->client_id)->value('amount') ?? 0), 2, '.', '');
    }

    // ---------------------------------------------------------------- REV-780-1: credit for days is bounded by money kept

    public function test_days_left_are_worth_the_money_the_invoice_kept_never_its_status_or_its_line(): void
    {
        $service = fn (): Service => Service::query()->findOrFail(31);
        // Paid in WHMCS to 2026-10-10 (imported without a period line): the fallback still credits its days left (#776).
        $this->assertSame('1.67', ChangeCycle::unused($service(), CarbonImmutable::today()), '5 of 30 days of 10.00, paid before the cut-over');

        // The next month marked paid by hand: the service runs to 2026-11-10, and not a cent came in for those days.
        $marked = $this->byHand();
        $this->assertSame('2026-11-10', $service()->expires_at->toDateString());
        $this->assertSame(0, $marked->load('transactions')->moneyKeptCents());
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->assertSame('0.00', ChangeCycle::unused($service(), CarbonImmutable::today()), 'paid by hand, no money: nothing comes back as credit');

        // 4.00 of the 10.00 recorded on it: its days are worth 4.00, 21 of 31 of them left.
        (new RecordPaymentService)->recordByStaff($marked, null, '4.00', 'wire-4');
        $this->assertSame(400, $marked->fresh()->load('transactions')->moneyKeptCents());
        $this->assertSame('2.71', ChangeCycle::unused($service(), CarbonImmutable::today()), '4.00 × 21 / 31');

        // The line raised to 50.00 by staff: still 4.00 of money, still 2.71.
        $marked->items()->sole()->update(['price' => '50.00']);
        $this->assertSame('2.71', ChangeCycle::unused($service(), CarbonImmutable::today()));

        // Covered in full (46.00 more): the days are worth what was paid, 50.00.
        (new RecordPaymentService)->recordByStaff($marked->fresh(), null, '46.00', 'wire-46');
        $this->assertSame('33.87', ChangeCycle::unused($service(), CarbonImmutable::today()), '50.00 × 21 / 31');
        // And a refund of part of it takes its share off again.
        ExtensionHelper::refundLocally($marked->transactions()->where('transaction_id', 'wire-46')->sole(), 46.0, notify: false);
        $this->assertSame('2.71', ChangeCycle::unused($service(), CarbonImmutable::today()));
    }

    // ---------------------------------------------------------------- paid by hand is a fact: paid_by_staff_at

    public function test_paid_by_staff_at_is_set_by_the_tick_and_cleared_when_it_leaves_paid_or_money_covers_it(): void
    {
        Event::fake([PaidByStaffCovered::class]);
        $this->assertNull($this->invoice->paid_by_staff_at);

        $marked = $this->byHand();
        $this->assertSame('2026-10-05', $marked->paid_by_staff_at->toDateString());

        $marked->update(['status' => Invoice::STATUS_UNPAID]);
        $this->assertNull($marked->fresh()->paid_by_staff_at, 'not paid any more: no longer «paid by hand»');

        $marked = $this->byHand();
        (new RecordPaymentService)->recordByStaff($marked, null, '4.00', 'wire-a');
        $this->assertNotNull($marked->fresh()->paid_by_staff_at, 'a part of it paid: still by hand for the rest');
        Event::assertNotDispatched(PaidByStaffCovered::class);
        (new RecordPaymentService)->recordByStaff($marked->fresh(), null, '6.00', 'wire-b');
        $this->assertNull($marked->fresh()->paid_by_staff_at, 'real money covers it: an ordinary paid invoice');
        $this->assertSame([Invoice::STATUS_PAID, 0], [$marked->fresh()->status, RecordPaymentService::toCents($marked->fresh()->remaining)]);
        Event::assertDispatchedTimes(PaidByStaffCovered::class, 1);
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'its period was given once, when staff marked it');

        // An ordinary payment in full never sets it.
        $this->assertNull(Invoice::query()->findOrFail(480)->paid_by_staff_at);
    }

    /** REV-780-3: the reward waits for real money, then comes once. */
    public function test_an_affiliate_is_rewarded_when_real_money_covers_a_paid_by_hand_invoice_and_only_once(): void
    {
        Artisan::call('migrate', ['--path' => 'extensions/Others/Affiliates/database/migrations', '--force' => true]);
        $order = Order::query()->create(['client_id' => $this->invoice->client_id, 'currency_code' => 'EUR']);
        Service::query()->whereKey(31)->update(['order_id' => $order->id]);
        $referrer = User::factory()->create();
        $affiliate = Affiliate::query()->create(['client_id' => $referrer->client()->id, 'code' => 'REV780B', 'reward' => 10]);
        AffiliateOrder::query()->create(['order_id' => $order->id, 'affiliate_id' => $affiliate->id]);
        Event::listen(InvoicePaid::class, RewardAffiliate::class); // as the extension's boot does
        Event::listen(PaidByStaffCovered::class, RewardAffiliate::class);
        $rewards = fn (): int => MoneyEvent::query()->where('client_id', $referrer->client()->id)->where('cause', MoneyTrail::CAUSE_AFFILIATE_REWARD)->count();

        $marked = $this->byHand();
        $this->assertSame(0, $rewards(), 'no money, no commission');
        (new RecordPaymentService)->recordByStaff($marked, null, '4.00', 'wire-a');
        $this->assertSame(0, $rewards(), 'part of it: not yet');
        (new RecordPaymentService)->recordByStaff($marked->fresh(), null, '6.00', 'wire-b');

        $this->assertSame(1, $rewards(), 'covered: rewarded');
        $this->assertSame('1.00', $this->credit($referrer->client()->id), '10% of 10.00');
        event(new InvoicePaid($marked->fresh()));
        event(new PaidByStaffCovered($marked->fresh()));
        $this->assertSame(1, $rewards(), 'once');
    }

    /** REV-780-6 with a part payment that was real: deleting a mistaken one leaves it by hand; once covered, a deletion reopens it as ever. */
    public function test_deleting_payments_of_a_paid_by_hand_invoice(): void
    {
        $marked = $this->byHand();
        $mistake = (new RecordPaymentService)->recordByStaff($marked, null, '3.00', 'wire-x');
        (new RecordPaymentService)->deleteTransaction($mistake->fresh());
        $this->assertSame([Invoice::STATUS_PAID, 1000], [$marked->fresh()->status, RecordPaymentService::toCents($marked->fresh()->remaining)]);
        $this->assertNotNull($marked->fresh()->paid_by_staff_at);

        $full = (new RecordPaymentService)->recordByStaff($marked->fresh(), null, '10.00', 'wire-y');
        $this->assertNull($marked->fresh()->paid_by_staff_at);
        (new RecordPaymentService)->deleteTransaction($full->fresh());
        $this->assertSame(Invoice::STATUS_UNPAID, $marked->fresh()->status, 'paid by its money, the money gone: unpaid, as before #780');
    }

    public function test_money_check_income_report_feed_and_the_page_read_the_fact(): void
    {
        $marked = $this->byHand();
        (new RecordPaymentService)->recordByStaff($marked, null, '4.00', 'wire-a');

        $this->assertSame(['marked paid by staff, no payment: still to pay 6.00 EUR'], array_column((new MoneyCheck)->run(['paid_balance']), 'detail'));
        $row = collect(IncomeReport::year('EUR', 2026)['invoices'])->firstWhere('number', (string) ($marked->fresh()->number ?: 502));
        $this->assertStringContainsString('Marked paid by staff, no payment', $row['paid_with']);
        $fed = (new EkdosiFeed)->one(502);
        $this->assertSame(['6.00', '2026-10-05 10:00:00'], [$fed['mycs']['balance'], $fed['mycs']['paid_by_staff_at']]);
        $this->assertNull((new EkdosiFeed)->one(480)['mycs']['paid_by_staff_at']);
        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => 502])->assertSee('Marked paid by staff, no payment: owes');
    }

    /** A paid-by-hand invoice gave its periods and took its number: kept (a deletion would turn its periods into «paid before MyCS»). */
    public function test_kept_a_paid_by_hand_invoice_is_not_deleted(): void
    {
        $marked = $this->byHand();

        try {
            $marked->delete();
            $this->fail('a paid-by-hand invoice was deleted');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('marked paid by staff', $e->getMessage());
        }
        $this->assertNotNull(Invoice::query()->find(502));
    }

    /** The cron's replay of a paid invoice whose lines were not applied (BIL-9): one paid by hand is applied, as staff meant. */
    public function test_the_crons_replay_applies_a_paid_by_hand_invoice_whose_apply_failed(): void
    {
        $marked = $this->byHand();
        // As if the work after the commit had failed: its line not applied, the service not renewed, the marker left.
        DB::table('invoice_items')->where('invoice_id', 502)->update(['applied_at' => null, 'period_start' => null, 'period_end' => null]);
        DB::table('services')->where('id', 31)->update(['expires_at' => '2026-10-10 00:00:00']);
        DB::table('invoices')->where('id', 502)->update(['apply_pending_since' => now()->subHour()]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertNull($marked->fresh()->apply_pending_since);
        $this->assertNotNull($marked->items()->sole()->applied_at, 'applied, not left as «refunded or moved to credit»');
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString());
        $this->assertNotNull($marked->fresh()->paid_by_staff_at);
    }

    // ---------------------------------------------------------------- REV-780-4: the permission

    public function test_the_form_offers_paid_by_hand_only_to_staff_who_may_record_payments(): void
    {
        $editor = Staff::factory()->create(['role_id' => Role::create(['name' => 'Editor' . uniqid(),
            'permissions' => ['admin.invoices.viewAny', 'admin.invoices.update']])->id]);
        $this->assertFalse($editor->can('create', InvoiceTransaction::class));

        $page = $this->livewireAs($editor)->test(EditInvoice::class, ['record' => 502]);
        rescue(fn () => $page->set('data.status', Invoice::STATUS_PAID)->set('data.paid_without_payment', true)->call('save'), report: false);

        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());

        // With it: the tick, and what else «paid» does is said beside it.
        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => 502])->set('data.status', Invoice::STATUS_PAID)
            ->assertSee('Affiliates are rewarded only when real money covers it.')
            ->set('data.paid_without_payment', true)->call('save')->assertHasNoFormErrors();
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
    }

    public function test_the_api_asks_the_key_for_the_permission_to_record_payments(): void
    {
        $this->withToken($this->token(['admin.invoices.update']))
            ->putJson(route('api.v1.admin.invoices.update', $this->invoice), ['status' => 'paid', 'paid_without_payment' => true])->assertStatus(403);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);

        $this->withToken($this->token(['admin.invoices.update', 'admin.invoice_transactions.create']))
            ->putJson(route('api.v1.admin.invoices.update', $this->invoice), ['status' => 'paid', 'paid_without_payment' => true])->assertSuccessful();
        $this->assertNotNull($this->invoice->fresh()->paid_by_staff_at);
    }

    // ---------------------------------------------------------------- no «paid, owes» without staff's word

    /** A zero invoice closes as paid (main's rule). Made to owe afterwards, it is paid by staff's word: the flag, and its permission. */
    public function test_a_paid_invoice_made_to_owe_is_paid_by_staffs_word_and_needs_its_permission(): void
    {
        $discount = $this->invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -10, 'description' => 'Courtesy']);
        $this->invoice->fresh()->update(['status' => Invoice::STATUS_PAID]); // nothing left to pay: allowed, as on main
        $paid = $this->invoice->fresh();
        $this->assertSame([Invoice::STATUS_PAID, null, 0], [$paid->status, $paid->paid_by_staff_at, RecordPaymentService::toCents($paid->remaining)]);
        $line = $paid->items()->sole();

        // Staff who may not record payments: every raise is refused, by a line or by a note (a key: StaffFreedomPaidInvoiceTest).
        $editor = Staff::factory()->create(['role_id' => Role::create(['name' => 'Editor' . uniqid(),
            'permissions' => ['admin.invoices.viewAny', 'admin.invoices.update', 'admin.adjustment_notes.update']])->id]);
        $this->actingAs($editor, 'staff');
        foreach ([
            fn () => $discount->fresh()->update(['status' => AdjustmentNoteStatus::Voided]),
            fn () => $discount->fresh()->update(['amount' => -2]),
            fn () => $discount->fresh()->delete(),
            fn () => $paid->adjustmentNotes()->create(['type' => AdjustmentNoteType::Debit->value, 'amount' => 5, 'description' => 'Fee']),
            fn () => $line->fresh()->update(['price' => '30.00']),
        ] as $n => $raise) {
            try {
                $raise();
                $this->fail("change #{$n} left a paid invoice owing without the permission");
            } catch (DisplayException $e) {
                $this->assertStringContainsString('permission to record payments', $e->getMessage());
            }
        }
        $this->assertSame([0, null], [RecordPaymentService::toCents($this->invoice->fresh()->remaining), $this->invoice->fresh()->paid_by_staff_at]);
        // What lowers it asks for nothing.
        $line->update(['description' => 'Hosting, renamed', 'price' => '9.00']);
        $this->assertNull($this->invoice->fresh()->paid_by_staff_at);

        // Staff who may record payments: the discount voided, it owes, and it is on record as paid by their word.
        $this->actingAs($this->staff, 'staff');
        $discount->fresh()->update(['status' => AdjustmentNoteStatus::Voided]);
        $this->assertSame([Invoice::STATUS_PAID, 900], [$this->invoice->fresh()->status, RecordPaymentService::toCents($this->invoice->fresh()->remaining)]);
        $this->assertNotNull($this->invoice->fresh()->paid_by_staff_at);
    }

    // ---------------------------------------------------------------- REV-780-2: a credit purchase that gave its credit

    public function test_kept_a_credit_purchase_that_gave_its_credit_keeps_its_lines_and_says_the_way(): void
    {
        $funds = Invoice::query()->create(['client_id' => $this->invoice->client_id, 'currency_code' => 'EUR', 'due_at' => now()->addWeek(), 'status' => Invoice::STATUS_UNPAID]);
        $funds->items()->create(['description' => 'Add funds', 'quantity' => 1, 'price' => '50.00', 'taxed' => false, 'reference_type' => Credit::class]);
        (new RecordPaymentService)->handle($funds->fresh(), null, '50.00', transactionId: 'wire-f', enteredByStaff: true);
        $this->assertSame([Invoice::STATUS_PAID, '50.00'], [$funds->fresh()->status, $this->credit()]);
        $line = $funds->items()->sole();
        $token = $this->token(['admin.invoice_items.update', 'admin.invoice_items.delete', 'admin.invoice_items.create']);

        foreach ([10, 80] as $price) {
            $refused = $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => $price])->assertStatus(422);
            $this->assertStringContainsString('buys account credit', (string) $refused->json('message'));
            $this->assertStringContainsString('Refund its payment', (string) $refused->json('message'));
            $this->assertStringNotContainsString('discount with a note', (string) $refused->json('message'));
        }
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertStatus(422);
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), ['invoice_id' => $funds->id, 'description' => 'Extra', 'price' => 5, 'quantity' => 1])->assertStatus(422);
        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $funds->id]);
        $page->assertFormFieldIsDisabled('items.' . array_key_first($page->get('data.items')) . '.price');
        $message = InvoiceItemObserver::creditGivenRefusal();
        $this->assertNotSame($message, __($message, [], 'el'), 'said in Greek too');

        $this->assertSame(['50.00', 1, '50.00'], [(string) $line->fresh()->price, $funds->items()->count(), $this->credit()]);
        // The way it says: refunded, the credit is taken back in full.
        ExtensionHelper::refundLocally($funds->transactions()->sole(), 50.0, notify: false);
        $this->assertSame('0.00', $this->credit());
    }

    // ---------------------------------------------------------------- REV-780-8: a paid invoice in Ekdosi's inbox

    public function test_staff_are_told_to_archive_ekdosis_copy_when_a_paid_invoice_in_its_inbox_changes(): void
    {
        (new RecordPaymentService)->recordByStaff($this->invoice, null, '10.00', 'wire-10');
        config(['settings.ekdosi_enabled' => true]);
        $this->actingAs($this->staff, 'staff');

        StaffLineEdit::run(502, true, fn () => $this->invoice->items()->sole()->update(['price' => '12.00']));

        Notification::assertNotified(__('Paid invoice changed, check Ekdosi\'s inbox'));
    }

    public function test_nobody_is_told_with_the_bridge_off_or_for_an_unpaid_invoice(): void
    {
        $this->actingAs($this->staff, 'staff');
        (new RecordPaymentService)->recordByStaff($this->invoice, null, '4.00', 'wire-4');
        config(['settings.ekdosi_enabled' => true]);
        StaffLineEdit::run(502, true, fn () => $this->invoice->items()->sole()->update(['price' => '12.00'])); // unpaid: not in the inbox
        config(['settings.ekdosi_enabled' => false]);
        (new RecordPaymentService)->recordByStaff($this->invoice->fresh(), null, '8.00', 'wire-8');
        StaffLineEdit::run(502, true, fn () => $this->invoice->items()->sole()->update(['price' => '13.00'])); // paid, the bridge off

        Notification::assertNotNotified(__('Paid invoice changed, check Ekdosi\'s inbox'));
    }

    // ---------------------------------------------------------------- the old view URL

    public function test_the_old_read_only_invoice_url_opens_the_edit_page(): void
    {
        $edit = InvoiceResource::getUrl('edit', ['record' => 502]);

        $this->actingAs($this->staff, 'staff')->get(substr($edit, 0, -strlen('/edit')))->assertRedirect($edit);
    }
}
