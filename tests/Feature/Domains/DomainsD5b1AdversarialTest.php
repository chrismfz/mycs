<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Events\Invoice\Paid;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use Tests\TestCase;

/**
 * Adversarial review of PR #169 (D5b-1, domain billing). Each ADV test fails on the PR's code and proves a finding;
 * each GUARD test holds and pins an attack that did not land.
 */
class DomainsD5b1AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']]);
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.domain_billing_enabled' => true]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        foreach ([['renew', 1, '23.33'], ['renew', 2, '46.66'], ['grace', 1, '100.30'], ['redemption', 1, '37.50']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** @return list<InvoiceItem> */
    private function domainLines(Domain $domain): array
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->orderBy('id')->get()->all();
    }

    /** @return list<InvoiceItem> its renewal lines (kind null) on unpaid invoices */
    private function openRenewals(Domain $domain): array
    {
        return array_values(array_filter($this->domainLines($domain), fn (InvoiceItem $l) => $l->kind === null && $l->invoice->status === Invoice::STATUS_UNPAID));
    }

    private function staffNotices(): string
    {
        return EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->pluck('body')->implode("\n");
    }

    // ------------------------------------------------------------------------------------------------ findings (ADV)

    /**
     * ADV-1 (High): a paid domain renewal is left «paid, not applied» on purpose; the paid_applied step then runs it
     * again on every cron run, and every run ends without an exception, so the Paid event (the customer's «payment
     * received» mail, the snapshot, extensions) fires again on every run, forever (until D5b-2 ships).
     */
    public function test_adv1_a_paid_domain_renewal_does_not_fire_paid_again_on_every_cron_run(): void
    {
        $domain = $this->domain();
        $this->cron();
        $invoice = $this->domainLines($domain)[0]->invoice;
        Event::fake([Paid::class]);
        (new RecordPaymentService)->handle($invoice, null, 23.33, transactionId: 'tx-domain');
        $atPayment = count(Event::dispatched(Paid::class));

        foreach (['2026-10-20 11:00:00', '2026-10-20 12:00:00', '2026-10-21 10:00:00'] as $at) {
            Carbon::setTestNow($at);
            $this->cron();
        }

        $this->assertSame($atPayment, count(Event::dispatched(Paid::class)), 'the Paid event (customer mail) is fired once, not on every cron run');
    }

    /**
     * ADV-2 (High): the period is «billed once» only by period_start >= expiry. A sync that corrects the expiry by a
     * day (timezone, registrar's own date) after the invoice is out makes a second, overlapping renewal invoice.
     */
    public function test_adv2_an_expiry_corrected_forward_by_a_day_is_not_billed_a_second_time(): void
    {
        $domain = $this->domain();
        $this->cron();
        $domain->update(['expires_at' => '2026-11-02']);

        $this->cron();

        $this->assertCount(1, $this->openRenewals($domain), 'two open invoices renewing the same year');
    }

    /**
     * ADV-3 (Medium): staff split the grace fee and the renewal onto two invoices (allowed: both bill something). The fee
     * lookup is per invoice, so the next run adds a second grace fee on the renewal's invoice.
     */
    public function test_adv3_a_split_renewal_does_not_get_a_second_grace_fee(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-10', 'status' => DomainStatus::Grace]);
        $this->cron();
        [$renewal, $fee] = $this->domainLines($domain);
        (new SplitInvoice)->handle($renewal->invoice_id, [$fee->id]);

        $this->cron();

        $fees = array_filter($this->domainLines($domain), fn (InvoiceItem $l) => $l->kind !== null && $l->invoice->status === Invoice::STATUS_UNPAID);
        $this->assertCount(1, $fees, 'the grace fee is billed twice');
    }

    /**
     * ADV-4 (Medium): the customer turns auto renew off (do not renew, D-14) after the invoice is out. The cron's
     * auto-charge still charges the saved card for a renewal they declined (services: only active ones are charged).
     */
    public function test_adv4_a_domain_the_customer_stopped_renewing_is_not_auto_charged(): void
    {
        $this->savedCard();
        $domain = $this->domain();
        $this->cron();
        $invoice = $this->domainLines($domain)[0]->invoice;
        $domain->update(['auto_renew' => false]);

        Carbon::setTestNow('2026-11-01 09:00:00');
        (new AutoCharge)->run();

        $this->assertSame(0, ChargeAttempt::query()->where('invoice_id', $invoice->id)->count(), 'charged a renewal the customer turned off');
    }

    /**
     * ADV-5 (Medium): auto-charge leaves an ended domain's invoice alone (transferred away, cancelled, deleted), but
     * account credit pays it: CreditAutoApply::eligible knows only services. The customer's credit is spent on a
     * renewal that can never happen.
     */
    public function test_adv5_credit_does_not_pay_the_renewal_of_a_domain_transferred_away(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $domain = $this->domain();
        $this->cron();
        $invoice = $this->domainLines($domain)[0]->invoice;
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: no credit yet');
        $domain->update(['status' => DomainStatus::TransferredAway]);
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');

        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'credit paid the renewal of a domain that is no longer ours');
    }

    /**
     * ADV-6 (Medium): the renewal is paid during grace (it waits for D5b-2), then the domain falls into redemption. The
     * restore fee (here 37.50; €96.50 on gTLDs) is never billed and staff are not told: the «staff are told» notice is
     * only for an unpaid invoice with a payment on it, a fully paid one is skipped silently.
     */
    public function test_adv6_a_paid_renewal_that_falls_into_redemption_bills_the_fee_or_tells_staff(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-10', 'status' => DomainStatus::Grace, 'grace_fee_override' => '0.00']);
        $this->cron();
        $invoice = $this->domainLines($domain)[0]->invoice;
        (new RecordPaymentService)->handle($invoice, null, 23.33, transactionId: 'tx-grace');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        EmailLog::query()->delete();

        $domain->update(['status' => DomainStatus::Redemption]);
        $this->cron();

        $billed = collect($this->domainLines($domain))->contains(fn (InvoiceItem $l) => $l->kind === 'redemption_fee');
        $told = str_contains($this->staffNotices(), 'nixpal.com');
        $this->assertTrue($billed || $told, 'the redemption fee is neither billed nor reported');
    }

    /**
     * ADV-7 (Low): a domain still «expired» long after its expiry (imported, never synced, no connection) is billed for a
     * period that ended already, due today, and auto-charged: a year that can no longer be renewed.
     */
    public function test_adv7_a_period_wholly_in_the_past_is_not_billed(): void
    {
        $domain = $this->domain(['expires_at' => '2025-03-01', 'status' => DomainStatus::Expired]);

        $this->cron();

        $past = array_filter($this->domainLines($domain), fn (InvoiceItem $l) => $l->period_end !== null && $l->period_end->lessThanOrEqualTo(today()));
        $this->assertSame([], $past, 'billed 2025-03-01 - 2026-03-01 on 2026-10-20');
    }

    /**
     * ADV-8 (Low; both companies are Exclusive today): with Tax Type «Inclusive» the TLD's catalog price includes VAT,
     * as a product's does; the line stores it as the net price, so VAT is charged on top (services: OrderPricing::toNet).
     */
    public function test_adv8_with_inclusive_tax_the_catalog_price_is_what_the_customer_pays(): void
    {
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'all']);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'inclusive']);
        $domain = $this->domain(); // its TLD's renew price, 23.33, as the catalog shows it

        $this->cron();

        [$line] = $this->domainLines($domain);
        // Fixed by ddb4f2c (toNet). A cent of drift (18.81 + 24% = 23.32) is Δ1's per-line rounding, shared with services.
        $this->assertEqualsWithDelta(23.33, (float) $line->line_total, 0.011, 'catalog 23.33 incl. VAT: billed ' . $line->line_total);
    }

    /**
     * ADV-9 (Medium): staff waive the grace (or redemption) fee: split it off to its own invoice and cancel that one (or
     * cancel the fee's invoice after a split). The next run finds no fee «not cancelled» and bills it again on the
     * renewal invoice, every time staff waive it.
     */
    public function test_adv9_a_stage_fee_staff_waived_is_not_billed_again(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-10', 'status' => DomainStatus::Grace]);
        $this->cron();
        [$renewal, $fee] = $this->domainLines($domain);
        $waived = (new SplitInvoice)->handle($renewal->invoice_id, [$fee->id]);
        $waived->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->cron();

        $fees = array_filter($this->domainLines($domain), fn (InvoiceItem $l) => $l->kind !== null && $l->invoice->status === Invoice::STATUS_UNPAID);
        $this->assertSame([], array_values($fees), 'the fee staff waived is billed again');
    }

    /**
     * ADV-10 (Medium): a renewal staff billed by hand (a Domain line without a period, as «billed» counts only on an
     * open invoice) is paid; nothing renews it until D5b-2, the expiry does not move, and the cron bills the same year
     * again.
     */
    public function test_adv10_a_paid_renewal_staff_billed_by_hand_is_not_billed_again(): void
    {
        $domain = $this->domain();
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-20']);
        $invoice->items()->create(['description' => 'nixpal.com renewal', 'price' => '23.33', 'quantity' => 1, 'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        $this->cron();
        $this->assertCount(1, $this->domainLines($domain), 'precondition: open, it counts as billed');
        (new RecordPaymentService)->handle($invoice->fresh(), null, 23.33, transactionId: 'tx-hand');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->cron();

        $this->assertCount(1, $this->domainLines($domain), 'the year the customer just paid is billed again');
    }

    // ADV-11 (an open 2-year renewal still billing 2 years in redemption): accepted, docs/domains.md 5b-2: the paid
    // years are delivered (restore 1, then renew the rest), so the customer gets what the line charges.

    /** ADV-5b (Medium): auto-charge leaves a domain with auto renew off alone; credit pays it anyway. */
    public function test_adv5b_credit_does_not_pay_a_renewal_the_customer_turned_off(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $domain = $this->domain();
        $this->cron();
        $invoice = $this->domainLines($domain)[0]->invoice;
        $domain->update(['auto_renew' => false]);
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');

        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'credit paid a renewal the customer turned off');
    }

    // --------------------------------------------------------------------------------------------------- guards

    public function test_guard_two_runs_and_a_cancelled_invoice_reopened_bill_a_period_once(): void
    {
        $domain = $this->domain();
        $this->cron();
        $this->cron();
        $this->assertCount(1, $this->domainLines($domain));
        $invoice = $this->domainLines($domain)[0]->invoice;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->cron();
        $invoice->refresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $this->cron();
        $this->assertCount(1, $this->domainLines($domain));
    }

    public function test_guard_ended_pending_and_other_clients_domains_are_not_billed_or_grouped_across(): void
    {
        $ended = [];
        foreach ([DomainStatus::TransferredAway, DomainStatus::Cancelled, DomainStatus::Deleted, DomainStatus::PendingRegister, DomainStatus::PendingTransfer] as $i => $status) {
            $ended[] = $this->domain(['sld' => "e{$i}", 'fqdn' => "e{$i}.com", 'status' => $status]);
        }
        $mine = $this->domain();
        $other = User::factory()->create();
        $theirs = $this->domain(['client_id' => $other->client()->id, 'sld' => 'theirs', 'fqdn' => 'theirs.com']);
        $usd = $this->domain(['sld' => 'usd', 'fqdn' => 'usd.com', 'currency_code' => 'USD', 'recurring_amount' => '9.00']);

        $this->cron();

        foreach ($ended as $domain) {
            $this->assertSame([], $this->domainLines($domain), $domain->status->value);
        }
        $a = $this->domainLines($mine)[0]->invoice;
        $b = $this->domainLines($theirs)[0]->invoice;
        $c = $this->domainLines($usd)[0]->invoice;
        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->id, $c->id);
        $this->assertSame([$this->user->client()->id, $other->client()->id, 'USD'], [(int) $a->client_id, (int) $b->client_id, $c->currency_code]);
    }

    public function test_guard_a_service_ending_on_a_mixed_invoice_takes_only_its_own_line(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25']);
        $domain = $this->domain(['expires_at' => '2026-10-25']);
        $this->cron();
        $invoice = $this->domainLines($domain)[0]->invoice;
        $this->assertSame(2, $invoice->items()->count());

        CancelOpenInvoices::handle([$invoice], 'test', $service);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame([Domain::class], $invoice->items()->pluck('reference_type')->all());
    }

    public function test_guard_a_paid_mixed_invoice_renews_the_service_while_the_domain_waits(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25']);
        $domain = $this->domain(['expires_at' => '2026-10-25']);
        $this->cron();
        $invoice = $this->domainLines($domain)[0]->invoice;
        $before = $service->fresh()->expires_at->toDateString();

        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'tx-mixed');

        $this->assertNotSame($before, $service->fresh()->expires_at->toDateString(), 'the service renewed');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->apply_pending_since, 'the invoice is applied; the domain line waits on its own (ddb4f2c)');
        $this->assertNull($this->domainLines($domain)[0]->applied_at);
    }

    public function test_guard_no_fee_without_a_price_on_a_cancelled_or_part_paid_invoice_and_no_doubling(): void
    {
        $free = $this->domain(['sld' => 'free', 'fqdn' => 'free.com', 'expires_at' => '2026-10-10', 'status' => DomainStatus::Grace, 'grace_fee_override' => '0.00']);
        $cancelled = $this->domain(['sld' => 'cx', 'fqdn' => 'cx.com', 'expires_at' => '2026-10-10', 'status' => DomainStatus::Expired]);
        $part = $this->domain(['sld' => 'part', 'fqdn' => 'part.com', 'expires_at' => '2026-10-10', 'status' => DomainStatus::Expired]);
        $this->cron();
        $this->domainLines($cancelled)[0]->invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        (new RecordPaymentService)->handle($this->domainLines($part)[0]->invoice, null, 1, transactionId: 'tx-part');
        $cancelled->update(['status' => DomainStatus::Grace]);
        $part->update(['status' => DomainStatus::Grace]);

        $this->cron();
        $this->cron();

        $this->assertSame([null], array_map(fn ($l) => $l->kind, $this->domainLines($free)));
        $this->assertSame([null], array_map(fn ($l) => $l->kind, $this->domainLines($cancelled)));
        $this->assertSame([null], array_map(fn ($l) => $l->kind, $this->domainLines($part)));
    }

    public function test_guard_auto_charge_skips_an_ended_domain_and_charges_a_live_one(): void
    {
        $this->savedCard();
        $live = $this->domain();
        $gone = $this->domain(['client_id' => $this->user->client()->id, 'sld' => 'gone', 'fqdn' => 'gone.com', 'expires_at' => '2026-11-02']);
        $this->user->client()->forceFill(['separate_invoices' => true])->save();
        $this->cron();
        $gone->update(['status' => DomainStatus::Deleted]);

        Carbon::setTestNow('2026-11-02 09:00:00');
        (new AutoCharge)->run();

        $this->assertSame(1, ChargeAttempt::query()->where('invoice_id', $this->domainLines($live)[0]->invoice_id)->count());
        $this->assertSame(0, ChargeAttempt::query()->where('invoice_id', $this->domainLines($gone)[0]->invoice_id)->count());
    }

    private function savedCard(): void
    {
        config(['settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0]);
        if (!class_exists(AcPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcPay; class AcPay {
                public static array $charges = [];
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey ?? null];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcPay::$charges = [];
        AcPay::$answer = null;
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->assertNotNull($this->user->client()->fresh()->autoChargeAgreement(), 'precondition: a default saved method');
    }
}
