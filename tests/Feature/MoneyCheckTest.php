<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Models\Staff;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\GatewayLog;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * MT-3 (docs/money-trail.md §5, §6): the daily money check, one test per rule (what breaks it and that a clean book
 * passes), the command (exit code, mail to staff once per finding set), the gateway log's prune, throttle and
 * unmatched routes, and the admin filter for paid invoices not applied (R6).
 */
class MoneyCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    private function invoice(float $price = 10, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function paid(float $price = 10): Invoice
    {
        $invoice = $this->invoice($price);
        (new RecordPaymentService)->handle($invoice, null, $price, transactionId: 'bank-' . $invoice->id);

        return $invoice->fresh();
    }

    /** @return list<string> the rules that found something */
    private function rules(?array $only = null): array
    {
        return array_values(array_unique(array_column((new MoneyCheck)->run($only), 'rule')));
    }

    public function test_a_clean_book_has_nothing_to_report(): void
    {
        $paid = $this->paid();
        $this->invoice();
        $credit = (new CreditLedger)->add($this->user->id, 'USD', 500, 'staff_adjustment', 'staff', note: 'Goodwill');
        $refunded = $this->paid(20);
        ExtensionHelper::refundLocally($refunded->transactions()->sole(), 5.0);

        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
        $this->artisan('mycs:money-check')->assertExitCode(0);
        $this->assertNotNull($paid);
    }

    public function test_a_paid_invoice_with_something_left_to_pay(): void
    {
        $invoice = $this->paid();
        DB::table('invoice_items')->insert(['invoice_id' => $invoice->id, 'description' => 'Added later', 'quantity' => 1, 'price' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['paid_balance'], $this->rules(['paid_balance']));
    }

    public function test_an_open_invoice_its_payments_cover(): void
    {
        $invoice = $this->invoice();
        (new RecordPaymentService)->handle($invoice, null, 4.0, transactionId: 'part');
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['price' => 4]);

        $this->assertSame(['open_settled'], $this->rules(['open_settled']));
    }

    public function test_a_cancelled_invoice_that_renewed_a_service(): void
    {
        $invoice = $this->invoice(10, Invoice::STATUS_CANCELLED);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['applied_at' => now()]);

        $this->assertSame(['cancelled_applied'], $this->rules(['cancelled_applied']));
    }

    public function test_a_paid_invoice_not_applied_for_a_day(): void
    {
        $invoice = $this->paid();
        DB::table('invoices')->where('id', $invoice->id)->update(['apply_pending_since' => now()->subHours(2)]);
        $this->assertSame([], $this->rules(['paid_not_applied']), 'the cron tries again first');

        DB::table('invoices')->where('id', $invoice->id)->update(['apply_pending_since' => now()->subHours(MoneyCheck::PAID_NOT_APPLIED_HOURS + 1)]);
        $this->assertSame(['paid_not_applied'], $this->rules(['paid_not_applied']));
    }

    public function test_an_open_invoice_of_cancelled_services_only(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => Service::STATUS_CANCELLED, 'currency_code' => 'USD']);
        $other = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD']);
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        $this->assertSame(['open_for_cancelled'], $this->rules(['open_for_cancelled']));

        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $other->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        $this->assertSame([], $this->rules(['open_for_cancelled']), 'it still bills a running service');
    }

    public function test_a_paid_invoice_for_a_cancelled_service_only_when_mycs_took_the_money_and_still_holds_it(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => Service::STATUS_CANCELLED, 'currency_code' => 'USD']);
        $line = fn (Invoice $invoice) => $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        // Imported history (paid in WHMCS, applied before MyCS kept applied_at): not a finding.
        $imported = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line($imported);
        $transaction = $imported->transactions()->create(['amount' => 10, 'status' => InvoiceTransactionStatus::Succeeded, 'transaction_id' => 'whmcs-1']);
        (new MoneyTrail)->payment($imported->fresh(), $transaction, MoneyTrail::SOURCE_IMPORT);
        DB::table('invoices')->where('id', $imported->id)->update(['status' => Invoice::STATUS_PAID]);
        $this->assertSame([], $this->rules(['paid_for_cancelled']), 'an imported paid invoice is history');

        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line($invoice);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 10.0, transactionId: 'wire-1');
        $this->assertSame(['paid_for_cancelled'], $this->rules(['paid_for_cancelled']));

        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 10.0);
        $this->assertSame([], $this->rules(['paid_for_cancelled']), 'refunded: nothing left on it');
    }

    public function test_a_credit_balance_the_journal_does_not_add_up_to_and_a_negative_one(): void
    {
        $credit = (new CreditLedger)->add($this->user->id, 'USD', 500, 'staff_adjustment', 'staff', note: 'Goodwill');
        DB::table('credits')->where('id', $credit->id)->update(['amount' => -2]);

        $this->assertSame(['credit_journal', 'credit_negative'], $this->rules(['credit_journal', 'credit_negative']));
    }

    public function test_a_payment_without_its_journal_entry_and_an_entry_without_its_payment(): void
    {
        $invoice = $this->invoice();
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded->value, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['payment_not_journaled'], $this->rules(['payment_not_journaled', 'journal_without_payment']));

        DB::table('invoice_transactions')->where('invoice_id', $invoice->id)->delete();
        $paid = $this->paid();
        DB::table('invoice_transactions')->where('invoice_id', $paid->id)->delete(); // gone without its reversal
        $this->assertSame(['journal_without_payment'], $this->rules(['payment_not_journaled', 'journal_without_payment']));
    }

    public function test_a_deleted_payment_leaves_the_journal_consistent(): void
    {
        $paid = $this->paid();
        (new RecordPaymentService)->deleteTransaction($paid->transactions()->sole());

        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_refunds_the_journal_does_not_show_and_more_back_than_came_in(): void
    {
        $paid = $this->paid(20);
        $transaction = $paid->transactions()->sole();
        DB::table('invoice_transactions')->where('id', $transaction->id)->update(['refunded_amount' => 25]);

        $this->assertSame(['refund_journal', 'over_refunded'], $this->rules(['refund_journal', 'over_refunded']));
    }

    public function test_one_gateway_payment_id_that_succeeded_twice(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway']);
        (new RecordPaymentService)->handle($this->invoice(), $gateway, 10.0, transactionId: 'pi_same'); // the gateway recorded it
        $this->assertSame([], $this->rules(['duplicate_gateway_id']));

        DB::table('invoice_transactions')->insert(['invoice_id' => $this->invoice()->id, 'transaction_id' => 'pi_same', 'amount' => 10,
            'status' => InvoiceTransactionStatus::Succeeded->value, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['duplicate_gateway_id'], $this->rules(['duplicate_gateway_id']));
    }

    public function test_a_gateway_request_that_never_finished(): void
    {
        $row = PaymentGatewayEvent::query()->create(['gateway' => 'stripe', 'endpoint' => 'webhook', 'method' => 'POST']);
        $this->assertSame([], $this->rules(['gateway_received']), 'a request still running');

        DB::table('payment_gateway_events')->where('id', $row->id)->update(['created_at' => now()->subMinutes(MoneyCheck::GATEWAY_RECEIVED_MINUTES + 1)]);
        $this->assertSame(['gateway_received'], $this->rules(['gateway_received']));
    }

    public function test_the_command_fails_and_mails_staff_once_per_set_of_findings(): void
    {
        $invoice = $this->paid();
        DB::table('invoices')->where('id', $invoice->id)->update(['apply_pending_since' => now()->subDays(2)]);

        $this->artisan('mycs:money-check')->assertExitCode(1);
        $this->artisan('mycs:money-check')->assertExitCode(1);
        $this->assertSame(1, DB::table('email_logs')->where('subject', 'like', 'Money check:%')->count(), 'the same findings are not mailed every day');

        $this->travel(8)->days();
        $this->artisan('mycs:money-check')->assertExitCode(1);
        $this->assertSame(2, DB::table('email_logs')->where('subject', 'like', 'Money check:%')->count(), 'reminded after a week');

        $this->artisan('mycs:money-check --rule=nope')->assertExitCode(2);
        $this->artisan('mycs:money-check --rule=credit_journal')->assertExitCode(0);
    }

    public function test_credits_check_still_rebuilds_from_the_journal(): void
    {
        $credit = (new CreditLedger)->add($this->user->id, 'USD', 500, 'staff_adjustment', 'staff', note: 'Goodwill');
        DB::table('credits')->where('id', $credit->id)->update(['amount' => 1]);

        $this->artisan('mycs:credits-check')->assertExitCode(1);
        $this->artisan('mycs:credits-check --write')->assertExitCode(0);
        $this->assertEqualsWithDelta(5.0, (float) $credit->fresh()->amount, 0.001);
    }

    public function test_the_prune_deletes_only_old_refused_requests_that_moved_no_money(): void
    {
        $old = now()->subDays(91);
        $make = fn (array $values) => tap(PaymentGatewayEvent::query()->create(['gateway' => 'stripe', 'endpoint' => 'webhook', 'method' => 'POST'] + $values),
            fn ($row) => DB::table('payment_gateway_events')->where('id', $row->id)->update(['created_at' => $old]));
        $forged = $make(['outcome' => PaymentGatewayEvent::OUTCOME_REJECTED, 'reason' => 'http_400', 'signature_verified' => false]);
        $verified = $make(['outcome' => PaymentGatewayEvent::OUTCOME_REJECTED, 'reason' => 'currency_mismatch', 'signature_verified' => true]);
        $money = $make(['outcome' => PaymentGatewayEvent::OUTCOME_REJECTED, 'reason' => 'invoice_not_found', 'amount' => 10]);
        $recorded = $make(['outcome' => PaymentGatewayEvent::OUTCOME_RECORDED, 'amount' => 10]);
        $recent = PaymentGatewayEvent::query()->create(['gateway' => 'stripe', 'endpoint' => 'webhook', 'method' => 'POST', 'outcome' => PaymentGatewayEvent::OUTCOME_REJECTED]);

        $this->artisan('mycs:gateway-log-prune')->assertExitCode(0);

        $this->assertSame([$verified->id, $money->id, $recorded->id, $recent->id], PaymentGatewayEvent::query()->orderBy('id')->pluck('id')->all());
        $this->assertNotNull($forged);
    }

    public function test_one_address_writes_at_most_so_many_rows_a_minute_and_is_still_answered(): void
    {
        Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway']);
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => 'whsec_test']))->boot();
        $key = 'gateway-log:' . sha1('127.0.0.1');
        for ($i = 0; $i < GatewayLog::ROWS_PER_MINUTE; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=forged'], '{}')
            ->assertStatus(400);

        $this->assertSame(0, PaymentGatewayEvent::query()->count());
    }

    public function test_a_request_to_a_gateway_url_that_matches_no_route_is_logged(): void
    {
        Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway']);
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => 'whsec_test']))->boot();

        $this->get('/extensions/stripe/webhook')->assertStatus(405); // a POST route
        $this->post('/extensions/stripe/nowhere')->assertStatus(404);
        $this->get('/extensions/not-a-gateway/webhook')->assertStatus(404);

        $rows = PaymentGatewayEvent::query()->orderBy('id')->get();
        $this->assertSame([['stripe', 'GET', 405, 'no_route'], ['stripe', 'POST', 404, 'no_route']],
            $rows->map(fn ($row) => [$row->gateway, $row->method, $row->http_status, $row->reason])->all());
        $this->assertSame([PaymentGatewayEvent::OUTCOME_REJECTED], $rows->pluck('outcome')->unique()->values()->all());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_admin_can_list_paid_invoices_not_applied(): void
    {
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $waiting = $this->paid();
        $applied = $this->paid();
        DB::table('invoices')->where('id', $waiting->id)->update(['apply_pending_since' => now()->subHour()]);

        $this->livewireAs($owner)->test(ListInvoices::class)
            ->filterTable('paid_not_applied')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$applied]);
    }
}
