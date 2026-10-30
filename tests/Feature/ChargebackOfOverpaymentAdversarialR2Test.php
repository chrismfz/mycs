<?php

namespace Tests\Feature;

use App\Events\Service\RenewalTaxChanged;
use App\Helpers\ExtensionHelper;
use App\Models\Audit;
use App\Models\Client;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #553 (head bb7bdccc): the round-1 fixes (stopAutoCharge under the payment locks,
 * audited; the subscriptions named to staff; allocations told apart from refunds). test_bug_* fail on the PR's head and
 * name a hole; test_holds_* are guarantees attacked that held. MoneyCheck after each money step.
 */
class ChargebackOfOverpaymentAdversarialR2Test extends TestCase
{
    use RefreshDatabase;

    private const STAFF = 'Chargeback needs staff attention';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        $this->user->client()->update(['auto_charge' => true]);
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    private function client(): Client
    {
        return $this->user->client()->fresh();
    }

    private function invoice(float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function overpaid(string $gateway = 'Stripe', string $reference = 'pi_over'): Invoice
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, $gateway, 130.00, transactionId: $reference, currency: 'usd');

        return $invoice->fresh();
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    private function autoCharge(): bool
    {
        return (bool) $this->client()->auto_charge;
    }

    private function staffBodies(): string
    {
        return EmailLog::query()->where('subject', self::STAFF)->pluck('body')->implode("\n");
    }

    private function service(string $status, ?string $subscription, array $markers = []): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00, 'subscription_id' => $subscription]);
        foreach ($markers as $marker) {
            $service->properties()->create(['key' => $marker, 'name' => $marker, 'value' => '1']);
        }

        return $service;
    }

    private function assertMoneyCheckClean(string $step = ''): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, $step . ' ' . json_encode($findings));
    }

    // ---- lock order: the account first, as every money path (Client::lock); the stop re-locks the row it holds

    public function test_holds_the_account_row_is_locked_before_the_invoice_and_payment(): void
    {
        $invoice = $this->overpaid();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $first = fn (string $pattern) => collect($queries)->search(fn (string $sql) => preg_match($pattern, $sql) === 1);
        // Either quoting: SQLite's "…", MariaDB's `…` (CI's test-mariadb).
        $client = $first('/^select \* from ["`]clients["`] where ["`]clients["`]\.["`]id["`] = \?/');
        $invoiceRow = $first('/^select \* from ["`]invoices["`] where ["`]invoices["`]\.["`]id["`] = \?/');
        $transactions = $first('/^select ["`]id["`] from ["`]invoice_transactions["`]/');
        $this->assertIsInt($client);
        $this->assertIsInt($invoiceRow);
        $this->assertLessThan($invoiceRow, $client, 'the account before the invoice (lockInvoice), as AutoCharge, payments, credit');
        $this->assertLessThan($transactions, $client);
        // The stop's update is the only write to the clients row, and it comes after the account lock: no upgrade from a
        // plain read to a lock that another path could interleave with.
        $updates = collect($queries)->filter(fn (string $sql) => preg_match('/^update ["`]clients["`]/', $sql) === 1)->keys();
        $this->assertCount(1, $updates);
        $this->assertGreaterThan($client, $updates->first());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertMoneyCheckClean();
    }

    // ---- the stop through the model: audited, and no observer side effects (renewal tax, group re-pricing)

    public function test_holds_the_stop_is_audited_and_triggers_no_client_observer_side_effect(): void
    {
        Config::set('audit.console', true); // a webhook is an HTTP request; the test runs in the console
        Event::fake([RenewalTaxChanged::class]);
        $this->service('active', 'sub_1');
        $this->overpaid();
        $before = Audit::query()->where('auditable_type', Client::class)->count();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertFalse($this->autoCharge());
        $audit = Audit::query()->where('auditable_type', Client::class)->where('auditable_id', $this->client()->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($before + 1, Audit::query()->where('auditable_type', Client::class)->count());
        $this->assertSame(['auto_charge' => true], array_map('boolval', (array) $audit->old_values));
        $this->assertSame(['auto_charge' => false], array_map('boolval', (array) $audit->new_values));
        Event::assertNotDispatched(RenewalTaxChanged::class);
        $this->assertMoneyCheckClean();
    }

    // ---- replays: the customer turned it back on; the same chargeback again must not stop it again

    public function test_holds_a_replayed_chargeback_does_not_stop_automatic_charging_again(): void
    {
        $this->overpaid();
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'));
        $this->assertFalse($this->autoCharge());
        $this->client()->update(['auto_charge' => true]); // Payment methods

        $this->assertFalse(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'));

        $this->assertTrue($this->autoCharge(), 'a replay is not a new dispute');
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_replayed_chargeback_in_another_currency_does_not_stop_it_again(): void
    {
        $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_eur', 120.00, 'eur');
        $this->assertFalse($this->autoCharge());
        $this->client()->update(['auto_charge' => true]);

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_eur', 120.00, 'eur');

        $this->assertTrue($this->autoCharge());
        $this->assertSame(30.0, $this->credit());
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_replay_after_the_dispute_was_won_does_not_stop_it_again(): void
    {
        $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->client()->update(['auto_charge' => true]);

        $this->assertFalse(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'));

        $this->assertTrue($this->autoCharge());
        $this->assertSame(30.0, $this->credit());
        $this->assertMoneyCheckClean();
    }

    // ---- a waiting chargeback, applied when its payment lands (applyWaitingRefunds, after commit)

    public function test_holds_waiting_chargeback_stops_it_when_applied_and_only_once(): void
    {
        $invoice = $this->invoice(100);
        $this->assertFalse(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'));
        $this->assertTrue($this->autoCharge(), 'nothing known yet: the account is not touched');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertFalse($this->autoCharge());
        $this->assertStringContainsString('Automatic charging of the account is turned off', $this->staffBodies());
        $this->client()->update(['auto_charge' => true]);

        // The gateway resends the chargeback after it was applied: a replay.
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->assertTrue($this->autoCharge());
        $this->assertMoneyCheckClean();
    }

    // ---- accounts with it off: nothing written, nothing claimed

    public function test_holds_account_without_auto_charge_is_not_written_nor_told_it_was_turned_off(): void
    {
        Config::set('audit.console', true);
        $this->client()->update(['auto_charge' => false]);
        $this->overpaid();
        $before = Audit::query()->where('auditable_type', Client::class)->count();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertFalse($this->autoCharge());
        $this->assertSame($before, Audit::query()->where('auditable_type', Client::class)->count(), 'no write, no audit');
        $this->assertStringNotContainsString('Automatic charging of the account is turned off', $this->staffBodies());
        $this->assertMoneyCheckClean();
    }

    // ---- the subscriptions staff are told of

    public function test_holds_subscription_list_skips_empty_ended_and_other_accounts_services(): void
    {
        $active = $this->service('active', 'sub_live');
        $suspended = $this->service('suspended', 'sub_held');
        $empty = $this->service('active', '');
        $none = $this->service('active', null);
        $cancelled = $this->service('cancelled', 'sub_gone'); // its subscription cancelled at the end (no marker)
        $product = $this->createProduct();
        $foreign = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD',
            'price' => 10.00, 'subscription_id' => 'sub_other']);
        $this->overpaid();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $body = $this->staffBodies();
        $this->assertStringContainsString("service #{$active->id}, #{$suspended->id})", $body);
        foreach ([$empty, $none, $cancelled, $foreign] as $service) {
            $this->assertStringNotContainsString("#{$service->id},", $body);
            $this->assertStringNotContainsString("#{$service->id})", $body);
        }
        $this->assertMoneyCheckClean();
    }

    public function test_holds_subscriptions_named_even_when_auto_charge_was_off_and_in_another_currency(): void
    {
        $this->client()->update(['auto_charge' => false]);
        $active = $this->service('active', 'I-PAYPALSUB', ['has_paypal_subscription']);
        $this->overpaid();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_eur', 120.00, 'eur');

        $this->assertStringContainsString("subscriptions still charge by themselves (service #{$active->id})", $this->staffBodies());
        $this->assertStringNotContainsString('is turned off', $this->staffBodies());
        $this->assertSame(30.0, $this->credit());
        $this->assertMoneyCheckClean();
    }

    // ---- ADV-553-R2-2: an ended service whose PayPal subscription was not cancelled (the cancel failed at the end,
    // PayPal::cancelSubscription reports and keeps the marker) still charges the card, but is left out of the list

    public function test_bug_ended_service_whose_paypal_subscription_still_runs_is_not_named(): void
    {
        $terminated = $this->service('terminated', 'I-STILLRUNNING', ['has_paypal_subscription']);
        $this->overpaid();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertMatchesRegularExpression('/subscriptions still charge by themselves \\(service #[0-9, #]*\\b' . $terminated->id . '\\b/', $this->staffBodies(),
            'the marker says the PayPal subscription was never cancelled: it still charges the disputed card, and staff are not told');
    }

    // ---- PayPal won-early (and Stripe's out-of-order win): the account's end state depends on the webhooks' order

    public function test_holds_paypal_won_early_records_no_money(): void
    {
        $invoice = $this->overpaid('PayPal', 'PP-PAY-1');
        ExtensionHelper::chargebackOpened('PayPal', 'PP-PAY-1', 'PP-D-1', 130.00, 'usd');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-PAY-1', null, 130.00, 'usd', 'PP-D-1');

        $this->assertFalse(ExtensionHelper::chargebackAtGateway('PayPal', 'PP-PAY-1', 'PP-REV-1', 130.00, 'usd'));

        $this->assertSame([30.0, Invoice::STATUS_PAID], [$this->credit(), $invoice->fresh()->status]);
        $this->assertMoneyCheckClean();
    }

    // ADV-553-R2-1: the same dispute, lost then won, leaves automatic charging off; won first then lost (PayPal tells the
    // win first; Stripe out of order) leaves it on. The stop is about the card that disputed, not about the order.

    public function test_bug_paypal_won_early_leaves_auto_charge_on_while_the_other_order_turns_it_off(): void
    {
        $this->overpaid('PayPal', 'PP-PAY-1');
        ExtensionHelper::chargebackOpened('PayPal', 'PP-PAY-1', 'PP-D-1', 130.00, 'usd');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-PAY-1', null, 130.00, 'usd', 'PP-D-1');
        ExtensionHelper::chargebackAtGateway('PayPal', 'PP-PAY-1', 'PP-REV-1', 130.00, 'usd');

        $this->assertFalse($this->autoCharge(),
            'chargeback then win turns it off (round-1 test_holds_customer_may_turn_auto_charge_on...); win then chargeback must too');
    }

    public function test_bug_stripe_win_told_before_its_chargeback_leaves_auto_charge_on(): void
    {
        $this->overpaid();
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'); // out of order
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertSame(30.0, $this->credit());
        $this->assertMoneyCheckClean();
        $this->assertFalse($this->autoCharge(), 'the card disputed the charge all the same: the order of the webhooks decides it');
    }
}
