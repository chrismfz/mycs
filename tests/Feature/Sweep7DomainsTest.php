<?php

namespace Tests\Feature;

use App\Console\Commands\Domains\SyncDomains;
use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sweep 7, lens «domains» (adversarial verifier): failing tests for the candidates that stand.
 */
class Sweep7DomainsTest extends TestCase
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
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [true, 'boolean'], 'default_currency' => ['EUR', 'string']]);
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_reminder_days' => '30,7,1,-3',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.domain_billing_enabled' => true]);
        $this->user = User::factory()->create();
        // As the importer leaves a TLD whose WHMCS grace/redemption days are «-1»: not known (CatalogImport).
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => null, 'redemption_days' => null, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
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

    private function domain(string $sld, array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'com', 'fqdn' => "{$sld}.com",
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function renewalInvoice(Domain $domain): Invoice
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->sole()->invoice;
    }

    /**
     * S7-DOMAINS-1: a domain expired 90 days ago, its stage days not known (the usual state after the import), stays
     * «expired» for good: never terminal, so its open renewal invoice is never cancelled (domain_invoices_cancelled), and
     * RenewalBilling itself would no longer bill it («past its last recoverable day»). Yet account credit pays that
     * invoice, the oldest due, before the one of a live domain: the credit sits on a name that cannot be renewed and the
     * live domain's renewal stays unpaid.
     */
    public function test_s7_domains_1_credit_does_not_pay_the_renewal_of_a_domain_past_its_last_recoverable_day(): void
    {
        $dead = $this->domain('dead');
        $this->cron();
        $deadInvoice = $this->renewalInvoice($dead);
        $this->assertSame(Invoice::STATUS_UNPAID, $deadInvoice->fresh()->status, 'precondition: billed before its expiry, unpaid');

        Carbon::setTestNow('2027-01-30 10:00:00'); // 90 days past its expiry
        $live = $this->domain('live', ['expires_at' => '2027-02-05']);
        SyncDomains::$pauseMs = 0;
        $this->artisan('domains:sync')->assertExitCode(0); // moves it by its dates (DomainLifecycle)
        $this->cron();
        $this->assertSame(DomainStatus::Expired, $dead->fresh()->status, 'precondition: still expired, never terminal');
        $this->assertSame(Invoice::STATUS_UNPAID, $deadInvoice->fresh()->status, 'precondition: its renewal invoice was left open');
        $liveInvoice = $this->renewalInvoice($live);
        $this->assertSame(Invoice::STATUS_UNPAID, $liveInvoice->fresh()->status, 'precondition: the live domain is billed, unpaid');
        $this->assertNotSame($deadInvoice->id, $liveInvoice->id);

        // Credit for one invoice (an overpayment, a refund to credit).
        (new CreditLedger)->add($this->user->client()->id, 'EUR', RecordPaymentService::toCents($liveInvoice->fresh()->total),
            MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
        $this->cron();

        $this->assertNotSame(Invoice::STATUS_PAID, $deadInvoice->fresh()->status, 'credit paid the renewal of a domain past its last recoverable day');
        $this->assertSame(Invoice::STATUS_PAID, $liveInvoice->fresh()->status, 'the live domain\'s renewal was left unpaid while the credit went elsewhere');
    }

    /**
     * S7-DOMAINS-2: the renewal of the period now due sits on a cancelled invoice, given up (forgone_at: a cancellation
     * here, or what the importer writes for one cancelled in WHMCS). The cron bills nothing (by design, a period is billed
     * once) and, unlike a service's given-up period, tells staff nothing; the customer still gets «renew your domain»
     * notices, and their «Renew» makes no invoice. Nobody who can act is told and the domain lapses.
     */
    public function test_s7_domains_2_a_given_up_renewal_is_billed_on_the_customers_renew_or_staff_are_told(): void
    {
        $domain = $this->domain('nixpal');
        $this->cron();
        $invoice = $this->renewalInvoice($domain);
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertNotNull(InvoiceItem::query()->where('invoice_id', $invoice->id)->value('forgone_at'), 'precondition: the period is given up');
        EmailLog::query()->delete();

        Carbon::setTestNow('2026-10-25 10:00:00'); // 7 days before its expiry: a notice day
        $this->cron();
        $this->assertTrue(DB::table('domain_reminders')->where('domain_id', $domain->id)->where('days', 7)->exists(), 'precondition: the customer got the renewal notice');
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])->call('renew');

        $open = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID]))->exists();
        $told = str_contains(EmailLog::query()->where('to', 'staff@example.test')->pluck('body')->implode("\n")
            . EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->pluck('body')->implode("\n"), 'nixpal.com');
        $this->assertTrue($open || $told, 'reminded to renew, the customer can make no invoice and staff are told nothing');
    }
}
