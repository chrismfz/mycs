<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Domains\DomainReminders;
use App\Services\Invoice\PaymentReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of D7-1 (PR #172, domain expiry notices), written without the author's context. Failing tests are
 * findings (ADV-D7-n); passing ones document attacks the code defends.
 */
class DomainRemindersAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        MailFacade::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.mail_disable' => false, 'settings.domain_billing_enabled' => true, 'settings.cronjob_payment_reminder' => 6,
            'settings.cronjob_overdue_reminders' => '1,2']);
        $this->user = User::factory()->create();
        $this->tld = $this->tld('com', 40, 0, ['renew' => '23.33', 'grace' => '100.30']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, string>  $prices */
    private function tld(string $name, ?int $grace, ?int $redemption, array $prices): DomainTld
    {
        $tld = DomainTld::query()->create(['tld' => $name, 'grace_days' => $grace, 'redemption_days' => $redemption, 'is_active' => true]);
        foreach ($prices as $operation => $price) {
            DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => $price]);
        }

        return $tld;
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-19',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** An open invoice with the domain's renewal line (its next period from $start), issued $created. */
    private function renewalInvoice(Domain $domain, string $start, string $due, string $created = '2026-10-01', string $price = '23.33'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => $due]);
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => $price,
            'period_start' => $start, 'period_end' => Carbon::parse($start)->addYear()->toDateString()]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => $created]);

        return $invoice->fresh();
    }

    /** @return list<string> template keys queued */
    private function sent(): array
    {
        return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all();
    }

    private function html(int $index = 0): string
    {
        return MailFacade::queued(Mail::class)->values()[$index]->renderedHtml;
    }

    private function notify(): int
    {
        return (new DomainReminders)->send();
    }

    private function agreement(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        BillingAgreement::create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-ADV', 'type' => 'paypal']);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings (fail on the PR's code)
    // ---------------------------------------------------------------------------------------------------------------

    /** ADV-D7-1: the sync moves the expiry by a day (registrar's date, timezone): the same «30 days» notice goes again. */
    public function test_adv1_a_sync_moving_the_expiry_by_a_day_does_not_send_the_same_notice_again(): void
    {
        $domain = $this->domain(); // 30 days
        $this->assertSame(1, $this->notify());

        Carbon::setTestNow('2026-10-21 09:00:00');
        $domain->update(['expires_at' => '2026-11-20']); // the registrar says the 20th: 30 days again
        $this->assertSame(0, $this->notify(), 'a second «expires in 30 days» for the same renewal');
    }

    /** ADV-D7-1b: moved a day earlier: 28 days left, «30» is within catch-up and goes again. */
    public function test_adv1b_a_sync_moving_the_expiry_a_day_earlier_does_not_send_the_same_notice_again(): void
    {
        $domain = $this->domain();
        $this->assertSame(1, $this->notify());

        Carbon::setTestNow('2026-10-21 09:00:00');
        $domain->update(['expires_at' => '2026-11-18']);
        $this->assertSame(0, $this->notify(), 'a second «30 days» notice two days later');
    }

    /**
     * ADV-D7-2: a TLD whose grace is not known (grace_days null: the lifecycle makes it Expired, never Grace). Its renewal
     * invoice is overdue: no expired notice (lastDay = expiry, «nothing left to recover») and the invoice's overdue
     * reminders are suppressed as «covered by domain notices». Before D7 the customer got the overdue reminder.
     */
    public function test_adv2_an_expired_domain_with_unknown_grace_and_an_overdue_invoice_gets_something(): void
    {
        $tld = $this->tld('net', null, null, ['renew' => '15.00']);
        $domain = $this->domain(['sld' => 'unk', 'tld' => 'net', 'fqdn' => 'unk.net', 'domain_tld_id' => $tld->id,
            'status' => DomainStatus::Expired, 'expires_at' => '2026-10-17']);
        $this->renewalInvoice($domain, '2026-10-17', '2026-10-17');

        $notices = $this->notify();
        $reminders = (new PaymentReminders)->send();

        $this->assertGreaterThanOrEqual(1, $notices + $reminders, 'an overdue renewal invoice and the customer hears nothing');
    }

    /**
     * ADV-D7-3: notice days only before the expiry (myip's WHMCS «60,30,15,10,5», docs §2b: «η myip βάζει τα δικά της»).
     * Past the due date the overdue reminders are suppressed and no notice comes after the expiry: silence while in grace.
     */
    public function test_adv3_days_only_before_the_expiry_do_not_silence_the_overdue_reminders(): void
    {
        config(['settings.domain_reminder_days' => '60,30,15,10,5']);
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-18']);
        $this->renewalInvoice($domain, '2026-10-18', '2026-10-18');

        $notices = $this->notify();
        $reminders = (new PaymentReminders)->send();

        $this->assertSame(0, $notices);
        $this->assertSame(1, $reminders, 'overdue invoice of a domain in grace: no reminder at all');
    }

    /**
     * ADV-D7-4: an invoice staff made with a line on a domain (a transfer, a service on it; InvoiceItemReference allows any
     * line on a domain) while its expiry is months away. Every line is on an auto-renew domain, so it is «covered», but no
     * notice will name it for months: it is never reminded.
     */
    public function test_adv4_a_staff_invoice_on_a_domain_far_from_its_expiry_still_gets_its_reminders(): void
    {
        $domain = $this->domain(['expires_at' => '2027-05-01']);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-18']);
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'description' => 'Trade / owner change', 'quantity' => 1, 'price' => '30.00']);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => '2026-10-01']);

        $this->assertSame(0, $this->notify());
        $this->assertSame(1, (new PaymentReminders)->send(), 'overdue 2 days and not reminded');
    }

    /**
     * ADV-D7-4b: the renewal paid (waiting for its registrar) and the domain moved into grace: ADV-6 tells staff to bill the
     * grace fee by hand. That invoice (a line on the domain) is «covered», and the notices are off (next year paid): silence.
     */
    public function test_adv4b_a_grace_fee_billed_by_hand_after_a_paid_renewal_is_reminded(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-17']);
        $paid = $this->renewalInvoice($domain, '2026-10-17', '2026-10-17');
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);
        $fee = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-18']);
        $fee->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => 'grace_fee', 'description' => 'Grace fee', 'quantity' => 1, 'price' => '100.30']);
        DB::table('invoices')->where('id', $fee->id)->update(['created_at' => '2026-10-17']);

        $notices = $this->notify();
        $reminders = (new PaymentReminders)->send();

        $this->assertSame(0, $notices);
        $this->assertSame(1, $reminders, 'the fee invoice is overdue and nobody reminds of it');
    }

    /** ADV-D7-5: a payment on its way (bank transfer, 3DS, PayPal pending): the notice still says «Please pay it». */
    public function test_adv5_no_please_pay_while_a_payment_is_on_its_way(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-27']); // 7 days
        $invoice = $this->renewalInvoice($domain, '2026-10-27', '2026-10-27');
        $invoice->transactions()->create(['amount' => '23.33', 'status' => InvoiceTransactionStatus::Processing]);

        $this->notify();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $html = $this->sent() === [] ? '' : $this->html();
        $this->assertStringNotContainsString('Please pay it', $html, 'PaymentReminders skips such an invoice; the notice asks to pay again');
    }

    /**
     * ADV-D7-6: «charged automatically» is decided by AutoCharge::agreementFor() alone, not what AutoCharge::due() charges
     * (all services active, none on a subscription). A domain + suspended service invoice is never auto-charged, yet the
     * notice says it will be and the last call (1 day) is dropped; PaymentReminders::chargedOnItsOwn() says the opposite.
     */
    public function test_adv6_the_last_call_is_sent_when_the_invoice_will_not_actually_be_charged(): void
    {
        $this->agreement();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'suspended', 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-21']);
        $domain = $this->domain(['expires_at' => '2026-10-21']);
        $invoice = $this->renewalInvoice($domain, '2026-10-21', '2026-10-21');
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);

        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice->fresh()), 'not charged on its own');
        $this->assertSame(1, $this->notify(), 'the last call dropped as if it were charged automatically');
    }

    /**
     * ADV-D7-7: before the expiry the «late fee» falls back to the redemption fee when grace has days but no grace price
     * (null = no fee: addStageFee adds nothing in grace). The customer reads «after that date it costs 80,00 more» while
     * renewing in the 40 days of grace costs nothing more.
     */
    public function test_adv7_no_redemption_fee_announced_as_the_cost_right_after_the_expiry(): void
    {
        $tld = $this->tld('org', 40, 30, ['renew' => '12.00', 'redemption' => '80.00']);
        $this->domain(['sld' => 'nofee', 'tld' => 'org', 'fqdn' => 'nofee.org', 'domain_tld_id' => $tld->id]);

        $this->assertSame(1, $this->notify());

        $this->assertStringNotContainsString('80,00', $this->html(), 'grace (40 days) has no fee; the redemption fee is shown as «after that date»');
    }

    /**
     * ADV-D7-8: in grace the expired notice says «renewable until <end of redemption>, now with a late fee of <grace fee>».
     * Past the grace the fee becomes the redemption's (D5b-1 replaces it on the invoice): the amount is wrong for most of
     * the window the notice names.
     */
    public function test_adv8_the_expired_notice_does_not_quote_the_grace_fee_up_to_the_end_of_redemption(): void
    {
        $tld = $this->tld('io', 40, 30, ['renew' => '40.00', 'grace' => '100.30', 'redemption' => '200.00']);
        $domain = $this->domain(['sld' => 'late', 'tld' => 'io', 'fqdn' => 'late.io', 'domain_tld_id' => $tld->id,
            'status' => DomainStatus::Grace, 'expires_at' => '2026-10-17']);
        $this->renewalInvoice($domain, '2026-10-17', '2026-10-17');

        $this->assertSame(1, $this->notify());
        $html = $this->html();

        $this->assertStringContainsString('26/12/2026', $html, 'last day: end of redemption');
        $this->assertStringContainsString('200,00', $html, 'renewable until 26/12 «with a late fee of 100,30»: after 26/11 it is 200,00');
    }

    /**
     * ADV-D7-9: «free» is read from the domain's price (recurring_amount 0.00), not from what is owed: an open renewal
     * invoice with a price (hosting gone, staff priced it, price changed after billing) gets «nothing to pay», and the
     * invoice's own reminders are suppressed because the notices «name it» (the free template never does).
     */
    public function test_adv9_a_free_notice_is_not_sent_while_its_renewal_invoice_asks_for_money(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-27', 'recurring_amount' => '0.00']);
        $invoice = $this->renewalInvoice($domain, '2026-10-27', '2026-10-27', price: '23.33');

        $this->notify();

        $this->assertSame(['domain_renewal_notice'], $this->sent(), 'told «nothing to pay» with invoice #' . $invoice->id . ' open for 23,33');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Defended attacks (pass)
    // ---------------------------------------------------------------------------------------------------------------

    public function test_defended_garbage_unsorted_and_repeated_days(): void
    {
        config(['settings.domain_reminder_days' => ' 7, 30,30 , abc, 400, -3, 0, ,-9999']);
        $this->assertSame([30, 7, 0, -3], DomainReminders::days());
        config(['settings.domain_reminder_days' => '']);
        $this->assertSame([], DomainReminders::days());
        $this->domain();
        $this->assertSame(0, $this->notify(), 'empty: off');
    }

    public function test_defended_the_cron_twice_the_same_day_and_the_next_day_send_once(): void
    {
        $this->domain();
        $this->assertSame(1, $this->notify());
        $this->assertSame(0, $this->notify());
        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->assertSame(0, $this->notify(), 'the catch-up days do not resend the 30th');
        $this->assertCount(1, $this->sent());
    }

    public function test_defended_catch_up_over_two_days_sends_the_latest_only(): void
    {
        config(['settings.domain_reminder_days' => '9,8,7']);
        $this->domain(['expires_at' => '2026-10-26']); // 6 days: 9 (3 ago, out), 8, 7 reached

        $this->assertSame(1, $this->notify());
        $this->assertSame([7, 8, 9], DB::table('domain_reminders')->orderBy('days')->pluck('days')->all());
    }

    public function test_defended_no_notice_for_ended_or_unrecoverable_domains(): void
    {
        foreach ([DomainStatus::Deleted, DomainStatus::Cancelled, DomainStatus::TransferredAway, DomainStatus::PendingRegister] as $i => $status) {
            $this->domain(['sld' => "d{$i}", 'fqdn' => "d{$i}.com", 'status' => $status, 'expires_at' => '2026-10-17']);
        }
        // Redemption over: its last day is past.
        $this->domain(['sld' => 'gone', 'fqdn' => 'gone.com', 'status' => DomainStatus::Redemption, 'expires_at' => '2026-10-17', 'grace_days_override' => 1, 'redemption_days_override' => 1]);

        $this->assertSame(0, $this->notify());
    }

    public function test_defended_renewed_by_hand_between_notices_gets_no_more_for_the_old_expiry(): void
    {
        $domain = $this->domain();
        $this->notify();
        $domain->update(['expires_at' => '2027-11-19']);

        Carbon::setTestNow('2026-11-12 09:00:00'); // the old 7th
        $this->assertSame(0, $this->notify());
        Carbon::setTestNow('2026-11-22 09:00:00'); // the old -3
        $this->assertSame(0, $this->notify());
    }

    public function test_defended_do_not_renew_gets_invoice_reminders_not_notices(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-18', 'auto_renew' => false]);
        $this->renewalInvoice($domain, '2026-10-18', '2026-10-18');

        $this->assertSame(0, $this->notify());
        $this->assertSame(1, (new PaymentReminders)->send(), 'not covered: its invoice reminder goes');
    }

    public function test_defended_a_mixed_invoice_still_gets_its_invoice_reminders(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-10-18']);
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-18']);
        $invoice = $this->renewalInvoice($domain, '2026-10-18', '2026-10-18');
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);

        $this->assertFalse(DomainReminders::covers($invoice->fresh()->load('items'), 'overdue'));
        $this->assertSame(1, (new PaymentReminders)->send());
    }

    public function test_defended_two_domains_one_not_renewing_is_not_covered(): void
    {
        $a = $this->domain(['expires_at' => '2026-10-18']);
        $b = $this->domain(['sld' => 'b', 'fqdn' => 'b.com', 'expires_at' => '2026-10-18', 'auto_renew' => false]);
        $invoice = $this->renewalInvoice($a, '2026-10-18', '2026-10-18');
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $b->id, 'description' => 'Renewal b', 'quantity' => 1, 'price' => '23.33',
            'period_start' => '2026-10-18', 'period_end' => '2027-10-18']);
        // A stage fee line of a: same domain twice, still all covered → only b decides.
        $this->assertFalse(DomainReminders::covers($invoice->fresh()->load('items'), 'overdue'));
    }

    public function test_defended_a_cancelled_renewal_invoice_is_not_named(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-27']);
        $invoice = $this->renewalInvoice($domain, '2026-10-27', '2026-10-27');
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(1, $this->notify());
        $this->assertStringNotContainsString('#' . ($invoice->number ?: $invoice->id), $this->html());
        $this->assertStringContainsString('We will send you its invoice', $this->html());
    }

    public function test_defended_greek_templates_render(): void
    {
        config(['settings.app_language' => 'el']);
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-17']);
        $this->renewalInvoice($domain, '2026-10-17', '2026-10-17');

        $this->assertSame(1, $this->notify());
        $mail = MailFacade::queued(Mail::class)->first();
        $this->assertSame('Το domain nixpal.com έληξε', $mail->renderedSubject);
        $this->assertStringNotContainsString('{{', $mail->renderedHtml);
        $this->assertStringNotContainsString('{%', $mail->renderedHtml);
        $this->assertStringContainsString('Εξόφληση προτιμολογίου', $mail->renderedHtml);
    }

    public function test_defended_a_price_in_no_currency_shows_no_empty_amount(): void
    {
        $this->domain(['currency_code' => 'USD']); // no USD price on the TLD

        $this->assertSame(1, $this->notify());
        $this->assertStringNotContainsString(' at **', $this->html());
        $this->assertStringNotContainsString('costs  more', $this->html());
    }
}
