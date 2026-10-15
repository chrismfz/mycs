<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Helpers\ExtensionHelper;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\PaymentReminders;
use App\Services\Invoice\SplitInvoice;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\CardExpiryNotices;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use Tests\TestCase;

/**
 * Adversarial review of PR H2b: «no auto-charge» across split / merge / the staff toggle, the card expiry notices, and the
 * WHMCS-era PayPal IPN safety net. Tests marked GUARD pass today and pin behaviour that must not regress.
 */
class AutoChargeExtrasAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const IPN_URL = '/modules/gateways/callback/paypal.php';

    private Client $client;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cc_expiry_notice_day' => 25,
            'settings.paypal_legacy_receivers' => 'billing@example.test, MERCHANT123']);
        if (!class_exists(AcPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcPay; class AcPay {
                /** @var list<array{invoice: int, amount: float, key: ?string}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcPay::$charges = [];
        AcPay::$answer = function ($invoice, $amount) {
            ExtensionHelper::addPayment($invoice->id, 'AcPay', $amount, null, 'ch_' . count(AcPay::$charges), currency: 'USD');

            return true;
        };
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card', 'expiry' => '2026-11-30']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $due): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
    }

    /** A renewal invoice of $lines active services. */
    private function invoice(string $due, int $lines = 1): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        for ($i = 0; $i < $lines; $i++) {
            $invoice->items()->create(['description' => 'Renewal ' . $i, 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
                'reference_id' => $this->service($due)->id]);
        }

        return $invoice->fresh();
    }

    private function flag(Invoice $invoice): void
    {
        Invoice::query()->whereKey($invoice->id)->update(['no_auto_charge' => true]);
    }

    // ---------------------------------------------------------------- «no auto-charge» carried through split / merge

    /** GUARD (was ADV-1, fixed by CR-2): the part split off a flagged invoice keeps the flag. */
    public function test_guard_split_of_a_flagged_invoice_keeps_both_parts_from_auto_charge(): void
    {
        $invoice = $this->invoice('2026-11-10', 2);
        $this->flag($invoice);
        $line = $invoice->items()->orderBy('id')->value('id');

        $new = (new SplitInvoice)->handle($invoice->id, [(int) $line]);

        $this->assertTrue((bool) $new->fresh()->no_auto_charge, 'the split-off part keeps «do not auto-charge»');
        $this->assertSame(0, (new AutoCharge)->run());
        $this->assertSame([], AcPay::$charges);
    }

    /** GUARD (CR-2): a flagged invoice merged into an unflagged one keeps its lines from the cron. */
    public function test_guard_merging_a_flagged_invoice_does_not_make_its_lines_auto_chargeable(): void
    {
        $target = $this->invoice('2026-11-10');
        $kept = $this->invoice('2026-11-10');
        $this->flag($kept);

        $merged = (new MergeInvoices)->handle($target->id, [$kept->id]);

        $this->assertSame($target->id, $merged->id);
        $this->assertSame(0, (new AutoCharge)->run(), 'the lines staff kept from auto-charge are not charged after a merge');
        $this->assertSame([], AcPay::$charges);
    }

    /** GUARD: flagged into flagged target, nothing charged. */
    public function test_guard_merge_into_a_flagged_target_is_not_charged(): void
    {
        $target = $this->invoice('2026-11-10');
        $other = $this->invoice('2026-11-10');
        $this->flag($target);

        (new MergeInvoices)->handle($target->id, [$other->id]);

        $this->assertSame(0, (new AutoCharge)->run());
    }

    /** GUARD: the reminder before the due date does not say «will be charged» for a flagged invoice. */
    public function test_guard_flagged_invoice_is_not_charged_on_its_own_for_the_reminder(): void
    {
        $invoice = $this->invoice('2026-11-15');
        $this->flag($invoice);

        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice->fresh()));
    }

    /**
     * ADV-5: CR-4 compares with the record as Livewire loads it again on confirm, not as the staff saw it. B opens «Do not
     * auto-charge»; A sets it meanwhile; B confirms and the flag is cleared: the invoice is charged by the next cron.
     */
    public function test_adv5_toggle_confirmed_after_another_staff_set_it_does_not_unset_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();
        $invoice = $this->invoice('2026-11-10');

        $page = $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])->mountAction('disableAutoCharge');
        $this->flag($invoice); // staff A, in another tab
        $page->callMountedAction();

        $this->assertTrue($invoice->fresh()->no_auto_charge, 'B asked for «Do not auto-charge»: it stays set');
        $this->assertSame(0, (new AutoCharge)->run());
    }

    // ---------------------------------------------------------------- expiry notices (all GUARD)

    private function expiryMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'card_expiring'));
    }

    /** GUARD: a day of 31 (or more) is clamped to 28, so February still gets its notice. */
    public function test_guard_day_31_is_clamped_and_february_is_noticed(): void
    {
        MailFacade::fake();
        config(['settings.cc_expiry_notice_day' => 31]);
        $this->agreement->update(['expiry' => '2027-02-28']);
        Carbon::setTestNow('2027-02-28 00:10:00');

        $this->assertSame(1, (new CardExpiryNotices)->run());
        $this->assertSame(1, $this->expiryMails());
    }

    /** GUARD: a negative day is off, as 0. */
    public function test_guard_negative_day_is_off(): void
    {
        MailFacade::fake();
        config(['settings.cc_expiry_notice_day' => -5]);
        Carbon::setTestNow('2026-11-25 09:00:00');

        $this->assertSame(0, (new CardExpiryNotices)->run());
    }

    /** GUARD: another account's method is told to that account only; a removed one to no one. */
    public function test_guard_each_method_goes_to_its_own_account_and_removed_ones_to_none(): void
    {
        MailFacade::fake();
        $other = User::factory()->create()->client();
        BillingAgreement::query()->create(['client_id' => $other->id, 'gateway_id' => $this->agreement->gateway_id,
            'name' => 'Visa 1111', 'external_reference' => 'pm_x', 'type' => 'card', 'expiry' => '2026-11-30']);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->agreement->gateway_id,
            'name' => 'Visa 2222', 'external_reference' => 'pm_y', 'type' => 'card', 'expiry' => '2026-11-30'])->delete();
        Carbon::setTestNow('2026-11-25 09:00:00');

        $this->assertSame(2, (new CardExpiryNotices)->run());
        $to = collect(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'card_expiring'))
            ->map(fn (Mail $mail) => $mail->to[0]['address'] ?? null)->sort()->values()->all();
        $this->assertSame(collect([$this->client->owner()->email, $other->owner()->email])->sort()->values()->all(), $to);
    }

    /** GUARD: last month's notice does not hold back this month's (a method renewed with a later expiry in the same row). */
    public function test_guard_a_notice_last_month_does_not_block_this_month(): void
    {
        MailFacade::fake();
        $this->agreement->forceFill(['expiry' => '2026-12-31', 'expiry_notified_at' => '2026-11-30 23:59:59'])->save();
        Carbon::setTestNow('2026-12-25 00:00:01');

        $this->assertSame(1, (new CardExpiryNotices)->run());
    }

    // ---------------------------------------------------------------- the WHMCS-era PayPal IPN

    /** @param  array<string, string>  $fields */
    private function ipn(array $fields): TestResponse
    {
        return $this->call('POST', self::IPN_URL, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /** @return array<string, string> */
    private function subscrPayment(string $txn = '8AB12345CD678901E'): array
    {
        return ['txn_type' => 'subscr_payment', 'payment_status' => 'Completed', 'txn_id' => $txn, 'subscr_id' => 'I-ABCDEF123456',
            'mc_gross' => '12.40', 'mc_currency' => 'EUR', 'item_number' => '30001', 'receiver_email' => 'billing@example.test'];
    }

    private function staffMails(string $subject): int
    {
        return EmailLog::query()->where('subject', $subject)->count();
    }

    /** GUARD (CR-1): in production a sandbox IPN (the IPN simulator VERIFIES anything) never mails «money taken». */
    public function test_guard_a_sandbox_ipn_never_tells_staff_that_money_was_taken_in_production(): void
    {
        $this->app['env'] = 'production';
        Http::fake(['https://ipnpb.sandbox.paypal.com/*' => Http::response('VERIFIED'), 'https://ipnpb.paypal.com/*' => Http::response('INVALID')]);

        foreach (['FAKE0001', 'FAKE0002', 'FAKE0003'] as $txn) {
            $this->ipn(['test_ipn' => '1'] + $this->subscrPayment($txn));
        }

        $this->assertSame(0, $this->staffMails('PayPal payment not recorded'), 'sandbox money is not money: no «record it by hand» mail');
    }

    /** GUARD (CR-1): a genuine payment to someone else's PayPal account, pointed at us, is not presented as ours. */
    public function test_guard_a_payment_to_another_paypal_account_is_not_presented_as_ours(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        $this->ipn(['receiver_email' => 'someone-else@example.net', 'business' => 'someone-else@example.net'] + $this->subscrPayment())->assertOk();

        $mail = EmailLog::query()->where('subject', 'PayPal payment not recorded')->first();
        $this->assertTrue($mail === null || str_contains($mail->body, 'someone-else@example.net'),
            'a payment to another PayPal account is refused, or at least the mail names the receiver');
    }

    /** GUARD (CR-3): a flood from one address does not make the server call PayPal without limit. */
    public function test_guard_forged_ipns_do_not_make_the_server_call_paypal_without_limit(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('INVALID')]);

        for ($i = 0; $i < 40; $i++) {
            $this->ipn($this->subscrPayment('FORGED' . $i));
        }

        $this->assertLessThan(40, count(Http::recorded()), 'a flood from one address is throttled before PayPal is called');
    }

    /** GUARD (CR-6): a staff mail that failed is sent on PayPal's retry. */
    public function test_guard_a_failed_staff_mail_is_sent_when_paypal_retries(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);
        $fail = true;
        EmailLog::creating(function () use (&$fail) {
            if ($fail) {
                $fail = false;

                throw new \RuntimeException('database went away');
            }
        });

        $first = $this->ipn($this->subscrPayment());
        $this->assertNotSame(200, $first->getStatusCode(), 'not acknowledged: PayPal sends it again');
        $this->ipn($this->subscrPayment()); // PayPal's retry

        $this->assertSame(1, $this->staffMails('PayPal payment not recorded'));
    }

    /**
     * ADV-1: the once-key is type|(txn_id ?: subscr_id)|status. Notices that carry neither (recurring_payment_profile_cancel
     * carries recurring_payment_id, mp_cancel carries mp_id) all share one key: only the first customer's is mailed.
     */
    public function test_adv1_notices_without_txn_or_subscr_id_do_not_share_one_key(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        $this->ipn(['txn_type' => 'recurring_payment_profile_cancel', 'recurring_payment_id' => 'I-AAAA1111', 'profile_status' => 'Cancelled', 'receiver_email' => 'billing@example.test'])->assertOk();
        $this->ipn(['txn_type' => 'recurring_payment_profile_cancel', 'recurring_payment_id' => 'I-BBBB2222', 'profile_status' => 'Cancelled', 'receiver_email' => 'billing@example.test'])->assertOk();

        $this->assertSame(2, $this->staffMails('PayPal legacy notice'), 'two customers, two notices');
    }

    /** ADV-2: a field's own line breaks reach the payments log: a verified (or sandbox) IPN writes forged log lines. */
    public function test_adv2_ipn_fields_cannot_forge_log_lines(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message;
        });

        $this->ipn(['item_number' => "30001\n[2026-10-15 10:00:00] production.INFO: Invoice #30001 paid by staff"] + $this->subscrPayment())->assertOk();

        foreach ($logged as $message) {
            $this->assertStringNotContainsString("\n[2026-10-15 10:00:00] production.INFO", $message);
        }
    }

    /**
     * ADV-3: the throttle (30 a minute) is per address and counts forged requests. Behind a reverse proxy without trusted
     * proxies (none set in bootstrap/app.php; GatewayLog's own comment expects it) every caller is one address: 30 forged
     * POSTs a minute and PayPal's genuine IPN is answered 429, minute after minute, until PayPal gives up (about 4 days).
     */
    public function test_adv3_forged_requests_do_not_use_up_the_budget_of_a_genuine_ipn(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->body(), 'txn_id=GENUINE')
            ? Http::response('VERIFIED') : Http::response('INVALID'));

        for ($i = 0; $i < 30; $i++) {
            $this->ipn($this->subscrPayment('FORGED' . $i));
        }
        // Fixed (author): only forged requests count; past the budget the genuine one is answered 503, not lost, and
        // PayPal's next try (minutes later) is taken.
        $this->assertNotSame(429, $this->ipn($this->subscrPayment('GENUINE1'))->getStatusCode());
        Carbon::setTestNow(now()->addMinutes(2));
        $this->ipn($this->subscrPayment('GENUINE1'))->assertOk();

        $this->assertSame(1, $this->staffMails('PayPal payment not recorded'));
    }

    /**
     * ADV-4: with «PayPal accounts of the WHMCS era» not set (its default), a genuine payment is answered 200: PayPal never
     * sends it again, staff get one daily mail without the transaction, and the gateway log keeps no amount or id. Once the
     * setting is fixed, the payment is gone.
     */
    public function test_adv4_a_genuine_payment_is_not_lost_while_the_receivers_are_not_set(): void
    {
        config(['settings.paypal_legacy_receivers' => '']);
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        $answer = $this->ipn($this->subscrPayment());

        $told = EmailLog::query()->where('body', 'like', '%8AB12345CD678901E%')->exists();
        $this->assertTrue($answer->getStatusCode() !== 200 || $told,
            'either PayPal is asked to send it again (non-200) or staff are told its transaction');
    }

    /** GUARD: the staff mail is HTML-escaped (no markup from IPN fields). */
    public function test_guard_ipn_fields_are_escaped_in_the_staff_mail(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        $this->ipn(['item_number' => '<a href="https://evil.example/">pay</a>'] + $this->subscrPayment())->assertOk();

        $body = EmailLog::query()->where('subject', 'PayPal payment not recorded')->sole()->body;
        $this->assertStringNotContainsString('<a href="https://evil.example/">', $body);
    }

    /** GUARD: a GET (or anything but POST) is not an IPN and never reaches PayPal. */
    public function test_guard_get_does_not_call_paypal(): void
    {
        Http::fake();

        $this->get(self::IPN_URL . '?' . http_build_query($this->subscrPayment()));

        Http::assertNothingSent();
    }
}
