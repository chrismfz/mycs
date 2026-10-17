<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\CronStat;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR #175 (D7: open invoices of lost domains cancelled by the cron). Each ADV test fails on the
 * PR's code and proves a finding; each GUARD test holds and pins an attack that did not land.
 */
class DomainLostInvoicesAdversarialTest extends TestCase
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
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.domain_billing_enabled' => true, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.domain_reminder_days' => '', 'settings.domain_invoice_days' => 15]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(string $sld, array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'com', 'fqdn' => "{$sld}.com",
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Deleted, 'expires_at' => '2026-08-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function invoice(string $status = Invoice::STATUS_UNPAID): Invoice
    {
        return Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => $status, 'currency_code' => 'EUR', 'due_at' => '2026-08-01']);
    }

    private function renewal(Invoice $invoice, Domain $domain, string $start = '2026-08-01', array $extra = []): InvoiceItem
    {
        return $invoice->items()->create($extra + ['description' => "Renewal {$domain->fqdn}", 'price' => '23.33', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => $start, 'period_end' => Carbon::parse($start)->addYear()->toDateString()]);
    }

    private function service(): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15']);
    }

    private function hostingLine(Invoice $invoice, Service $service, array $extra = []): InvoiceItem
    {
        return $invoice->items()->create($extra + ['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id, 'period_start' => '2026-11-15', 'period_end' => '2026-12-15']);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function balance(): int
    {
        return (new CreditLedger)->balanceCents($this->user->client()->id, 'EUR');
    }

    private function staffNotices(): string
    {
        return EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->pluck('body')->implode("\n");
    }

    // ------------------------------------------------------------------------------------------------ findings (ADV)

    /**
     * ADV-1 (High): staff used «Renew now» (D5b-2): the registrar was charged for the period, logged OK against the unpaid
     * invoice, which the customer still owes (the later payment adopts it). The customer then moves the domain away
     * (transferred_away, or staff mark it cancelled). The cron cancels the invoice: a year we paid the registry for is
     * given away, with no one deciding so. CancelOpenInvoices only knows `applied_at` as «delivered»; «Renew now» does
     * not set it on an unpaid line.
     */
    public function test_adv1_a_renewal_already_charged_at_the_registrar_is_not_cancelled_when_the_domain_moves_away(): void
    {
        $domain = $this->domain('renewed', ['status' => DomainStatus::TransferredAway]);
        $invoice = $this->invoice();
        $line = $this->renewal($invoice, $domain);
        DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
            'invoice_id' => $invoice->id, 'request' => ['years' => 1, 'target_expiry' => '2027-08-01', 'line' => $line->id]]);

        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'a renewal the registrar was already charged for was cancelled: the debt is gone');
    }

    /**
     * ADV-1b (High): the same with a charge whose outcome is unknown (pending in the log: a timeout, a 5xx). It may have
     * been charged; the invoice is its only claim.
     */
    public function test_adv1b_a_renewal_with_a_pending_registrar_charge_is_not_cancelled(): void
    {
        $domain = $this->domain('pending', ['status' => DomainStatus::Cancelled]);
        $invoice = $this->invoice();
        $line = $this->renewal($invoice, $domain);
        DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_PENDING,
            'invoice_id' => $invoice->id, 'request' => ['years' => 1, 'target_expiry' => '2027-08-01', 'line' => $line->id]]);

        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'a renewal with a charge of unknown outcome was cancelled');
    }

    /**
     * ADV-2 (Medium): staff add a line of their own (no reference: «DNS work», «migration») to the domain's renewal
     * invoice. A domain has no setup fee or promotion of its own, so a loose line is not the domain's; yet with nothing
     * else referenced on it the invoice is cancelled whole and the staff line with it.
     */
    public function test_adv2_a_staff_line_with_no_reference_is_not_cancelled_with_the_domain(): void
    {
        $domain = $this->domain('loose');
        $invoice = $this->invoice();
        $this->renewal($invoice, $domain);
        $invoice->items()->create(['description' => 'DNS migration work', 'price' => '50.00', 'quantity' => 1]);

        $this->cron();

        $fresh = $invoice->fresh();
        $this->assertNotSame(Invoice::STATUS_CANCELLED, $fresh->status, 'a staff line that is not the domain\'s was cancelled with it');
    }

    /**
     * ADV-3 (Low): the lost domains are read once, before the loop; neither the cron nor CancelOpenInvoices looks at the
     * domain again under a lock. Staff (or a sync) restoring a domain while the step runs still see its invoice cancelled.
     */
    public function test_adv3_a_domain_restored_while_the_step_runs_keeps_its_invoice(): void
    {
        $first = $this->domain('first');
        $second = $this->domain('second', ['expires_at' => '2026-11-01']);
        $one = $this->invoice();
        $this->renewal($one, $first);
        $two = $this->invoice();
        $this->renewal($two, $second, '2026-11-01');
        // Staff restore «second» the moment the step has cancelled «first»'s invoice.
        Invoice::updated(function (Invoice $invoice) use ($one, $second) {
            if ((int) $invoice->id === (int) $one->id && $invoice->status === Invoice::STATUS_CANCELLED) {
                Domain::query()->whereKey($second->id)->update(['status' => DomainStatus::Active->value]);
            }
        });

        $this->cron();

        $this->assertSame(Invoice::STATUS_CANCELLED, $one->fresh()->status, 'precondition');
        $this->assertSame(DomainStatus::Active, $second->fresh()->status, 'precondition: restored');
        $this->assertSame(Invoice::STATUS_UNPAID, $two->fresh()->status, 'the invoice of a domain restored meanwhile was cancelled');
    }

    /**
     * ADV-4 (Low): the step's count is «invoices found − invoices left open»; an invoice found only through the domain's
     * charge (an owner change: never cancelled) is counted as cancelled. The cron's stats say 2, one was.
     */
    public function test_adv4_the_count_is_of_invoices_cancelled_only(): void
    {
        $domain = $this->domain('counted');
        $renewal = $this->invoice();
        $this->renewal($renewal, $domain);
        $charge = $this->invoice();
        $charge->items()->create(['description' => 'Owner change', 'price' => '30.00', 'quantity' => 1, 'kind' => InvoiceItem::KIND_CHARGE,
            'reference_type' => Domain::class, 'reference_id' => $domain->id]);

        $this->cron();

        $this->assertSame([Invoice::STATUS_CANCELLED, Invoice::STATUS_UNPAID], [$renewal->fresh()->status, $charge->fresh()->status], 'precondition');
        $this->assertSame(1, (int) CronStat::query()->where('key', 'domain_invoices_cancelled')->value('value'));
    }

    /**
     * ADV-5 (Low, cut-over): an imported WHMCS invoice keeps a domain line as reference_type null with the domain's relid
     * (ImportFromWhmcs). myip's hook cancelled such invoices after 30 days; nothing does now, and nobody is told.
     */
    public function test_adv5_an_imported_renewal_invoice_of_a_lost_domain_is_cancelled_or_staff_are_told(): void
    {
        $this->markTestIncomplete('ADV-5: open for D6 (the domain importer links WHMCS Domain lines to their domains; docs/importer.md, docs/domains.md D7).');
        $domain = $this->domain('imported');
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Domain Renewal - imported.com - 1 Year/s (01/08/2026 - 31/07/2027)', 'price' => '23.33', 'quantity' => 1,
            'reference_type' => null, 'reference_id' => $domain->id]);

        $this->cron();

        $this->assertTrue($invoice->fresh()->status === Invoice::STATUS_CANCELLED || str_contains($this->staffNotices(), "#{$invoice->id}"),
            'an imported open renewal of a lost domain stays open forever, unnoticed');
    }

    /**
     * ADV-6 (Low, design): staff ended a domain (cancelled); the customer still wants to pay its renewal, so staff reopen
     * the invoice the cron cancelled. The next run cancels it again (another cancellation credit note), every night:
     * staff cannot keep a renewal of a domain they ended open, short of making the domain live again.
     */
    public function test_adv6_an_invoice_staff_reopened_is_not_cancelled_again_every_night(): void
    {
        $domain = $this->domain('wants', ['status' => DomainStatus::Cancelled]);
        $invoice = $this->invoice();
        $this->renewal($invoice, $domain);
        $this->cron();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'precondition');

        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]); // staff reopen it for the customer
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'the invoice staff reopened was cancelled again by the next run');
    }

    // ------------------------------------------------------------------------------------------- defended (GUARD)

    /** GUARD: credit the customer put on a domain-only invoice goes back to them, all of it. */
    public function test_guard_credit_on_a_domain_only_invoice_goes_back(): void
    {
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
        $invoice = $this->invoice();
        $this->renewal($invoice, $this->domain('credit'));
        (new RecordPaymentService)->payWithCredit($invoice->id);
        $this->assertSame(0, $this->balance(), 'precondition: credit spent');

        $this->cron();

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(1000, $this->balance());
    }

    /**
     * GUARD: a mixed invoice with VAT, credit on it beyond what stays: only the domain's lines go (another live domain's,
     * a service's and a charge of the lost one stay), the rest is paid by the credit and only the excess goes back.
     */
    public function test_guard_mixed_invoice_with_tax_and_credit_is_paid_again_correctly(): void
    {
        $lost = $this->domain('lost');
        $alive = $this->domain('alive', ['status' => DomainStatus::Active, 'expires_at' => '2026-11-01']);
        $service = $this->service();
        $invoice = $this->invoice();
        $this->hostingLine($invoice, $service, ['taxed' => true, 'tax_rate' => 24]); // 12.40
        $this->renewal($invoice, $lost, extra: ['taxed' => true, 'tax_rate' => 24]); // 28.93
        $invoice->items()->create(['description' => 'Restore fee', 'price' => '37.50', 'quantity' => 1, 'kind' => 'redemption_fee',
            'reference_type' => Domain::class, 'reference_id' => $lost->id]);
        $charge = $invoice->items()->create(['description' => 'Owner change', 'price' => '5.00', 'quantity' => 1, 'kind' => InvoiceItem::KIND_CHARGE,
            'reference_type' => Domain::class, 'reference_id' => $lost->id]);
        $other = $this->renewal($invoice, $alive, '2026-11-01');
        $rest = $invoice->fresh()->items->whereIn('id', [$charge->id, $other->id])->sum(fn (InvoiceItem $l) => $l->totalCents()) + 1240;
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 6000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
        (new RecordPaymentService)->payWithCredit($invoice->id, upToCents: 6000);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: part paid by credit');

        $this->cron();

        $fresh = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $fresh->status);
        $this->assertEqualsCanonicalizing([$charge->id, $other->id, $fresh->items->firstWhere('reference_type', Service::class)->id], $fresh->items->pluck('id')->all());
        $this->assertSame($rest, RecordPaymentService::toCents($fresh->total));
        $this->assertSame(6000 - $rest, $this->balance(), 'only what is more than the rest goes back');
    }

    /** GUARD: credit below what stays keeps paying it; nothing goes back. */
    public function test_guard_mixed_invoice_with_less_credit_than_the_rest_keeps_it(): void
    {
        $service = $this->service();
        $invoice = $this->invoice();
        $this->hostingLine($invoice, $service);
        $this->renewal($invoice, $this->domain('lost'));
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
        (new RecordPaymentService)->payWithCredit($invoice->id);

        $this->cron();

        $fresh = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $fresh->status);
        $this->assertSame(500, RecordPaymentService::toCents($fresh->remaining));
        $this->assertSame(0, $this->balance());
    }

    /** GUARD: a payment that landed after the cron read the invoice (a webhook) is seen under the lock: not cancelled. */
    public function test_guard_a_payment_landing_after_the_read_is_seen(): void
    {
        $domain = $this->domain('race');
        $invoice = $this->invoice();
        $this->renewal($invoice, $domain);
        $stale = Invoice::query()->whereKey($invoice->id)->get(); // what the cron read
        (new RecordPaymentService)->handle($invoice->fresh(), null, '23.33', transactionId: 'tx-webhook');

        $left = CancelOpenInvoices::handle($stale, 'x', $domain);

        $this->assertSame([], $left);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** GUARD: a part payment by a gateway keeps a mixed invoice whole and open, staff told once for both lost domains. */
    public function test_guard_money_on_a_mixed_invoice_keeps_it_and_staff_are_told_once(): void
    {
        $invoice = $this->invoice();
        $this->renewal($invoice, $this->domain('one'));
        $this->renewal($invoice, $this->domain('two'));
        (new RecordPaymentService)->handle($invoice->fresh(), null, '5.00', transactionId: 'tx-part');

        $this->cron();
        $this->cron();

        $this->assertSame(2, $invoice->fresh()->items()->count());
        $this->assertSame(1, substr_count($this->staffNotices(), "Invoice #{$invoice->id} left open"));
    }

    /** GUARD: a draft is cancelled too (finalised later it would bill a lost domain). */
    public function test_guard_a_draft_is_cancelled(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_DRAFT);
        $this->renewal($invoice, $this->domain('draft'));

        $this->cron();

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    /** GUARD: renewal and fee split over two invoices (a split by staff): both follow the domain. */
    public function test_guard_split_renewal_and_fee_both_go(): void
    {
        $domain = $this->domain('split');
        $service = $this->service();
        $a = $this->invoice();
        $this->hostingLine($a, $service);
        $this->renewal($a, $domain);
        $b = $this->invoice();
        $b->items()->create(['description' => 'Grace fee', 'price' => '100.30', 'quantity' => 1, 'kind' => 'grace_fee',
            'reference_type' => Domain::class, 'reference_id' => $domain->id]);

        $this->cron();

        $this->assertSame([Service::class], $a->fresh()->items->pluck('reference_type')->all());
        $this->assertSame(Invoice::STATUS_CANCELLED, $b->fresh()->status);
    }

    /**
     * GUARD: staff end a domain (cancelled), the cron cancels its renewal, staff then set it back to active: its period
     * is billed again (the cancellation does not give the period up, keep_periods_billable).
     */
    public function test_guard_a_domain_set_back_to_active_is_billed_again(): void
    {
        $domain = $this->domain('back', ['status' => DomainStatus::Cancelled, 'expires_at' => '2026-11-01']);
        $invoice = $this->invoice();
        $this->renewal($invoice, $domain, '2026-11-01');
        $this->cron();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'precondition');

        $domain->forceFill(['status' => DomainStatus::Active])->save();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $open = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_UNPAID))->count();
        $this->assertSame(1, $open);
    }

    /** GUARD: a paid renewal waiting for D5b-2 (paid, not applied) is never touched. */
    public function test_guard_a_paid_line_waiting_for_the_registrar_is_left(): void
    {
        $domain = $this->domain('waiting', ['status' => DomainStatus::TransferredAway]);
        $invoice = $this->invoice();
        $line = $this->renewal($invoice, $domain);
        (new RecordPaymentService)->handle($invoice->fresh(), null, '23.33', transactionId: 'tx-paid');

        $this->cron();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue($line->fresh()->exists);
    }
}
