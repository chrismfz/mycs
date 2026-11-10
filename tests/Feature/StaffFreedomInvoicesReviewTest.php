<?php

namespace Tests\Feature;

use App\Events\Invoice\Paid as InvoicePaid;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Upgrade;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Others\Affiliates\Listeners\RewardAffiliate;
use MyCS\Extensions\Others\Affiliates\Models\Affiliate;
use MyCS\Extensions\Others\Affiliates\Models\AffiliateOrder;
use Tests\TestCase;

/**
 * Independent and adversarial review of #780 (staff freedom on invoices, owner 2026-11-10). Each test is a path where a
 * paid invoice that no longer holds its total (marked paid by hand, or a line raised after it was paid: both new in #780)
 * still counts as money somewhere else, or where a kept kernel no longer holds. Invented fixtures only.
 */
class StaffFreedomInvoicesReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-03-01 10:00:00');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        foreach (['credits_enabled', 'credits_on_downgrade'] as $key) {
            Setting::query()->whereNull('settingable_type')->updateOrCreate(['key' => $key], ['value' => '1', 'type' => 'boolean']);
        }
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $price, string $unit = 'month'): Product
    {
        $product = Product::factory()->create(['name' => 'P' . $price . $unit, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => '0.00', 'currency_code' => 'USD']);

        return $product->fresh();
    }

    /** An active service of $product paid until $until, and the unpaid invoice of its next period [$until, $end). */
    private function serviceWithOpenRenewal(Product $product, string $price, string $until, string $end): array
    {
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'status' => 'active', 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => $until,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $until]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'taxed' => false, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $until, 'period_end' => $end]);

        return [$service->fresh(), $invoice->fresh()];
    }

    private function credit(): string
    {
        return number_format((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2, '.', '');
    }

    private function received(Invoice $invoice): int
    {
        return (int) $invoice->transactions()->get()->sum(fn ($payment) => RecordPaymentService::toCents($payment->amount)
            - RecordPaymentService::toCents($payment->refunded_amount) - RecordPaymentService::toCents($payment->credited_amount));
    }

    /**
     * REV-780-1 (high). An invoice staff marked paid by hand holds no money (item 3: «no payment row, no journal entry»; its
     * page says «owes 10.00»). The customer then downgrades the service from the client area: DowngradeCredit reads the
     * paid period line through PaidForPeriod, which counts the line's total as paid whenever its invoice is `paid`, and
     * gives the days left back as real account credit. Credit from nothing, by the customer's own click: what the PR
     * refuses for a credit purchase («it would mint credit») happens here one step later.
     */
    public function test_rev_780_1_a_downgrade_gives_no_credit_for_a_period_marked_paid_by_hand_with_no_money(): void
    {
        $big = $this->product('10.00');
        $small = $this->product('2.00');
        DB::table('product_upgrades')->insertOrIgnore(['product_id' => $big->id, 'upgrade_id' => $small->id]);
        [$service, $invoice] = $this->serviceWithOpenRenewal($big, '10.00', '2026-03-01', '2026-04-01');

        // Staff: «paid», no payment recorded (the status select's tick, the Admin API's paid_without_payment).
        $invoice->paid_without_payment = true;
        $invoice->status = Invoice::STATUS_PAID;
        $invoice->save();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-04-01', $service->fresh()->expires_at->toDateString(), 'the service renewed, as staff meant');
        $this->assertSame(0, $this->received($invoice), 'precondition: not a cent came in');
        $this->assertSame(1000, RecordPaymentService::toCents($invoice->fresh()->remaining), 'precondition: it owes all of it');

        Carbon::setTestNow('2026-03-11 10:00:00');
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])->set('upgrade', $small->id)->call('doUpgrade');

        $this->assertSame((int) $small->id, (int) $service->fresh()->product_id, 'the downgrade happened');
        $this->assertSame('0.00', $this->credit(), 'account credit given for days nobody paid for');
    }

    /**
     * REV-780-1b (high). The same root on item 1: a line of a PAID invoice raised by staff. The invoice stays paid and owes
     * the difference; nothing more came in. A cycle change «now» credits «the days left as they were paid» from the line's
     * new total: 10.00 was paid for the year, the line now reads 120.00, and the unused days of 120.00 become credit.
     */
    public function test_rev_780_1b_a_cycle_change_now_credits_only_what_was_paid_after_a_paid_line_is_raised(): void
    {
        $yearly = $this->product('10.00', 'year');
        $monthly = Plan::factory()->create(['priceable_id' => $yearly->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $monthly->id, 'price' => '1.00', 'setup_fee' => '0.00', 'currency_code' => 'USD']);
        [$service, $invoice] = $this->serviceWithOpenRenewal($yearly, '10.00', '2026-03-01', '2027-03-01');
        (new RecordPaymentService)->handle($invoice, null, '10.00', transactionId: 'wire-1', enteredByStaff: true);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        Carbon::setTestNow('2026-08-30 10:00:00');
        $before = ChangeCycle::unused($service->fresh(), CarbonImmutable::today());
        $this->assertSame('5.01', $before, 'precondition: 183 of 365 days left of the 10.00 that was paid');

        // Item 1: staff raise the paid line (a typo, a correction). It stays paid and owes 110.00.
        $invoice->items()->sole()->update(['price' => '120.00']);
        $this->assertSame([Invoice::STATUS_PAID, 11000], [$invoice->fresh()->status, RecordPaymentService::toCents($invoice->fresh()->remaining)]);
        $this->assertSame(1000, $this->received($invoice), 'precondition: 10.00 is all that came in');

        $this->assertSame($before, ChangeCycle::unused($service->fresh(), CarbonImmutable::today()),
            'the days left are worth what was paid for them (10.00 a year), not what the line says since staff changed it');
        $this->assertSame($before, ChangeCycle::quote($service->fresh(), $monthly->fresh(), ChangeCycle::NOW)['credit']);
    }

    /** An Admin API key with these permissions, of an admin or ($role) of a staff member whose role has only these. */
    private function token(array $permissions, bool $role = false): string
    {
        $staff = $role ? Staff::factory()->create(['role_id' => Role::create(['name' => 'Rev' . uniqid(), 'permissions' => $permissions])->id])
            : Staff::factory()->admin()->create();
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => $staff->id, 'name' => 'rev', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => $permissions]);

        return $token;
    }

    /**
     * REV-780-1c (medium). Item 1 lets staff take a line off a paid invoice; «Close as paid» then gives its money back as
     * credit. The period the line gave «stays given», and with the line gone the service looks like one «imported without
     * its lines»: a downgrade then prices its days left at the renewal price and gives them back as credit a second time.
     * 10.00 was paid for the month and 10.00 came back as credit; 5.42 more comes for the same days.
     */
    public function test_rev_780_1c_a_downgrade_gives_no_credit_for_a_period_whose_paid_line_staff_removed_and_credited(): void
    {
        $big = $this->product('10.00');
        $small = $this->product('2.00');
        DB::table('product_upgrades')->insertOrIgnore(['product_id' => $big->id, 'upgrade_id' => $small->id]);
        [$service, $invoice] = $this->serviceWithOpenRenewal($big, '10.00', '2026-03-01', '2026-04-01');
        $invoice->items()->create(['description' => 'Setup of a mailbox', 'price' => '5.00', 'quantity' => 1, 'taxed' => false]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, '15.00', transactionId: 'wire-2', enteredByStaff: true);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-04-01', $service->fresh()->expires_at->toDateString());

        // Staff take the hosting line off the paid invoice (the Admin API; the form's «remove» does the same) and close it.
        $line = $invoice->items()->where('reference_type', Service::class)->sole();
        $this->withToken($this->token(['admin.invoice_items.delete']))->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertNoContent();
        $this->assertSame([Invoice::STATUS_PAID, -1000], [$invoice->fresh()->status, RecordPaymentService::toCents($invoice->fresh()->remaining)]);
        $this->assertTrue((new RecordPaymentService)->settle($invoice->id), '«Close as paid» on a paid invoice paid over');
        $this->assertSame('10.00', $this->credit(), 'precondition: the month\'s money is back as credit');
        $this->assertSame('2026-04-01', $service->fresh()->expires_at->toDateString(), 'the period given stays given');

        Carbon::setTestNow('2026-03-11 10:00:00');
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])->set('upgrade', $small->id)->call('doUpgrade');

        $this->assertSame((int) $small->id, (int) $service->fresh()->product_id, 'the downgrade happened');
        $this->assertSame('10.00', $this->credit(), 'the same days given back as credit twice');
    }

    /**
     * REV-780-2 (high). A PAID credit purchase («Add funds» 50.00, paid, 50.00 of credit given) kept its lines by two rules
     * this PR removes: the paid freeze (item 1) and «this invoice buys account credit and has payments: its lines keep
     * their amounts» (item 2, StaffLineEdit::linesKeepAmounts). Staff now lower its line to 10.00. The credit it gave is
     * not touched (50.00), and the take-back of a refund is capped at what the lines say now (boughtCreditCents: 10.00):
     * refunding the payment takes back 10.00 of the 50.00. The customer has 50.00 back and keeps 40.00 of credit.
     */
    public function test_rev_780_2_a_paid_credit_purchase_lowered_then_refunded_leaves_no_credit_behind(): void
    {
        $funds = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $funds->items()->create(['description' => 'Add funds', 'quantity' => 1, 'price' => '50.00', 'taxed' => false, 'reference_type' => Credit::class]);
        $payment = (new RecordPaymentService)->handle($funds->fresh(), null, '50.00', transactionId: 'wire-3', enteredByStaff: true);
        $this->assertSame([Invoice::STATUS_PAID, '50.00'], [$funds->fresh()->status, $this->credit()], 'precondition: bought and given');

        $line = $funds->items()->sole();
        $response = $this->withToken($this->token(['admin.invoice_items.update']))
            ->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '10.00']);
        if ($response->status() === 422) {
            $this->assertSame('50.00', $this->credit());

            return; // refused: the kernel holds
        }
        $response->assertSuccessful();

        // Its money goes back to the customer in full (a bank transfer returned by hand).
        ExtensionHelper::refundLocally($payment->fresh(), 50.00, notify: false);

        $this->assertSame(0, $this->received($funds), 'precondition: all 50.00 went back');
        $this->assertSame('0.00', $this->credit(), 'the customer has the 50.00 back and still holds credit it bought with them');
    }

    /**
     * REV-780-3 (high where the Affiliates extension is on). «Paid by hand» fires the invoice's Paid event like a payment
     * (InvoiceObserver::updated), and the affiliate reward is a share of the paid invoice's lines given as real account
     * credit (RewardAffiliate, through the ledger). An invoice with no money on it pays a commission.
     */
    public function test_rev_780_3_an_invoice_marked_paid_with_no_money_rewards_no_affiliate(): void
    {
        Artisan::call('migrate', ['--path' => 'extensions/Others/Affiliates/database/migrations', '--force' => true]);
        $product = $this->product('100.00');
        [$service, $invoice] = $this->serviceWithOpenRenewal($product, '100.00', '2026-03-01', '2026-04-01');
        $order = Order::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD']);
        $service->forceFill(['order_id' => $order->id])->save();
        $referrer = User::factory()->create();
        $affiliate = Affiliate::query()->create(['client_id' => $referrer->client()->id, 'code' => 'REV780', 'reward' => 10]);
        AffiliateOrder::query()->create(['order_id' => $order->id, 'affiliate_id' => $affiliate->id]);
        Event::listen(InvoicePaid::class, RewardAffiliate::class); // as the extension's boot does

        $this->withToken($this->token(['admin.invoices.update']))
            ->putJson(route('api.v1.admin.invoices.update', $invoice), ['status' => 'paid', 'paid_without_payment' => true])->assertSuccessful();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0, $this->received($invoice), 'precondition: no money came in');
        $this->assertSame(0, MoneyEvent::query()->where('client_id', $referrer->client()->id)->where('cause', MoneyTrail::CAUSE_AFFILIATE_REWARD)->count(),
            'a commission paid (as account credit) on an invoice nobody paid');
    }

    /**
     * REV-780-4 (medium). «Mark Paid» and «Close as paid» ask for the invoice's permission AND the one to record payments
     * (`admin.invoice_transactions.create`: «it records a payment», «it can move money to credit»). «Paid by hand» renews
     * the same services (and sends a domain's renewal to its registrar) on the invoice's permission alone: a role that
     * was not given payments now turns any unpaid invoice paid, by the status select or the Admin API.
     */
    public function test_rev_780_4_paid_by_hand_needs_the_permission_mark_paid_needs(): void
    {
        $product = $this->product('10.00');
        [$service, $invoice] = $this->serviceWithOpenRenewal($product, '10.00', '2026-03-01', '2026-04-01');
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Editor' . uniqid(),
            'permissions' => ['admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update']])->id]);
        $this->assertTrue($staff->can('update', $invoice));
        $this->assertFalse($staff->can('create', InvoiceTransaction::class), 'precondition: not allowed to record payments (Mark Paid is hidden)');

        $this->withToken($this->token(['admin.invoices.update'], role: true))
            ->putJson(route('api.v1.admin.invoices.update', $invoice), ['status' => 'paid', 'paid_without_payment' => true]);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'made paid without the permission to record a payment');
        $this->assertSame('2026-03-01', $service->fresh()->expires_at->toDateString(), 'and its service renewed');
    }

    /**
     * REV-780-5 (medium; a kernel the PR says it kept). «MoveToClient keeps refusing ... an order's invoice that does not
     * go whole with its order» (PR text, docs/staff-freedom.md row 6). The rule moved out of InvoiceLineMove::refusal into
     * MoveToClient::plan as `!$orderMoves && $order !== null`, but `$order` is only read when the invoice goes WHOLE
     * (`$order = $whole ? ... : null`): for the very case named, an order's invoice that also bills another item, it is
     * null and nothing refuses. The order's invoice is split across two accounts.
     */
    public function test_rev_780_5_kept_an_orders_own_invoice_that_does_not_go_whole_still_refuses_the_move(): void
    {
        $product = $this->product('10.00');
        $to = User::factory()->create();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All' . uniqid(), 'permissions' => ['*']])->id]);
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD']);
        [$first, $second] = array_map(fn () => Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id, 'status' => 'active', 'price' => '10.00', 'currency_code' => 'USD', 'expires_at' => '2026-04-01',
            'order_id' => $order->id]), [1, 2]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        foreach ([$first, $second] as $service) {
            $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1, 'taxed' => false,
                'reference_type' => Service::class, 'reference_id' => $service->id]);
        }
        Order::query()->whereKey($order->id)->update(['invoice_id' => $invoice->id]);

        $outcome = rescue(fn () => app(MoveToClient::class)->service($first->fresh(), $to->client(), $staff), fn ($e) => $e, false);

        $this->assertInstanceOf(DisplayException::class, $outcome, 'the order\'s own invoice was split by the move');
        $this->assertSame(2, $invoice->items()->count(), 'its lines stay together');
        $this->assertSame($this->user->client()->id, (int) $first->fresh()->client_id);
    }

    /**
     * REV-780-2b (medium). The other direction of REV-780-2: the line of a PAID credit purchase raised (50.00 → 80.00). It
     * «owes 30.00»; staff record the 30.00 on it («Add payment»), which now lands on the paid invoice instead of becoming
     * credit (RecordPaymentService::excessCents). The line gave its credit already (applied_at), so nothing more is given:
     * the customer paid 80.00 for «Add funds 80.00» and holds 50.00. Before #780 the same 30.00 became credit.
     */
    public function test_rev_780_2b_money_paid_on_a_raised_paid_credit_purchase_is_not_swallowed(): void
    {
        $funds = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $funds->items()->create(['description' => 'Add funds', 'quantity' => 1, 'price' => '50.00', 'taxed' => false, 'reference_type' => Credit::class]);
        (new RecordPaymentService)->handle($funds->fresh(), null, '50.00', transactionId: 'wire-4', enteredByStaff: true);
        $this->assertSame([Invoice::STATUS_PAID, '50.00'], [$funds->fresh()->status, $this->credit()]);

        $response = $this->withToken($this->token(['admin.invoice_items.update']))
            ->putJson(route('api.v1.admin.invoice-items.update', $funds->items()->sole()), ['price' => '80.00']);
        if ($response->status() === 422) {
            $this->assertSame('50.00', $this->credit());

            return; // refused: the kernel holds
        }
        (new RecordPaymentService)->recordByStaff($funds->fresh(), null, '30.00', 'wire-5');

        $this->assertSame(8000, $this->received($funds) + (int) round((float) $funds->transactions()->sum('credited_amount') * 100), 'precondition: 80.00 came in');
        $this->assertSame('80.00', $this->credit(), 'the customer paid 80.00 for 80.00 of credit');
    }

    /**
     * REV-780-6 (low). «Paid by hand» is undone by an unrelated correction. An invoice staff marked paid (owes 10.00) gets
     * a payment of 3.00 by mistake; staff delete that payment. RecordPaymentService::deleteTransaction reopens any paid
     * invoice «its payments no longer cover», which this one never was: it flips to unpaid (overdue: reminders, suspension
     * of the service staff said was paid), where before the 3.00 it was paid and owed the same 10.00.
     */
    public function test_rev_780_6_deleting_a_mistaken_payment_leaves_an_invoice_marked_paid_by_hand_as_it_was(): void
    {
        $product = $this->product('10.00');
        [, $invoice] = $this->serviceWithOpenRenewal($product, '10.00', '2026-03-01', '2026-04-01');
        $invoice->paid_without_payment = true;
        $invoice->status = Invoice::STATUS_PAID;
        $invoice->save();
        $this->assertSame([Invoice::STATUS_PAID, 1000], [$invoice->fresh()->status, RecordPaymentService::toCents($invoice->fresh()->remaining)]);

        $mistake = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '3.00', 'wire-6');
        $this->assertSame([Invoice::STATUS_PAID, 700], [$invoice->fresh()->status, RecordPaymentService::toCents($invoice->fresh()->remaining)], 'it landed on what the paid invoice owes');
        (new RecordPaymentService)->deleteTransaction($mistake->fresh());

        $this->assertSame([Invoice::STATUS_PAID, 1000], [$invoice->fresh()->status, RecordPaymentService::toCents($invoice->fresh()->remaining)],
            'back where it was before the mistaken payment: paid by staff\'s word, owing 10.00');
    }
}
