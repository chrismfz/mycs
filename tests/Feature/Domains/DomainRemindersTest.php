<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainReminders;
use App\Services\Invoice\PaymentReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D7 expiry notices (docs/domains.md §2b, D-14): on domain_reminder_days (default 30,7,1,-3, as ICANN's ERRP), once per
 * domain, day and expiry; none for «do not renew», nor once its next year is paid; after the expiry only while it can be
 * recovered. Invoice reminders leave out invoices that only renew such domains (their notices name the invoice).
 */
class DomainRemindersTest extends TestCase
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
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
        DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => 'grace', 'years' => 1, 'currency_code' => 'EUR', 'price' => '100.30']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-19',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** @return list<array{0: string, 1: string}> template key and subject of the mails queued */
    private function sent(): array
    {
        return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => [$mail->templateKey, $mail->renderedSubject])->values()->all();
    }

    private function notify(): int
    {
        return (new DomainReminders)->send();
    }

    public function test_the_defaults_are_30_7_1_after_3_and_the_invoice_15_days_before(): void
    {
        $this->assertSame([30, 7, 1, -3], DomainReminders::days());
        $this->assertSame(15, RenewalBilling::domainInvoiceDays());
        config(['settings.domain_invoice_days' => '']);
        $this->assertSame((int) config('settings.cronjob_invoice', 7), RenewalBilling::domainInvoiceDays(), 'set empty: as the services');
    }

    public function test_thirty_days_before_one_notice_with_its_price_sent_once(): void
    {
        $this->domain(); // expires in 30 days

        $this->assertSame(1, $this->notify());
        $this->assertSame(0, $this->notify(), 'once');

        [[$key, $subject]] = $this->sent();
        $this->assertSame(['domain_renewal_notice', 'Your domain nixpal.com expires on 19/11/2026'], [$key, $subject]);
        $html = MailFacade::queued(Mail::class)->first()->renderedHtml;
        $this->assertStringContainsString('23,33', $html);
        // Owner 2026-10-17: the point is paying before the price goes up.
        $this->assertStringContainsString('100,30', $html, 'what renewing after the expiry costs on top');
    }

    public function test_none_for_do_not_renew_nor_once_its_next_year_is_paid(): void
    {
        $this->domain(['auto_renew' => false]);
        $paid = $this->domain(['sld' => 'paid', 'fqdn' => 'paid.com']);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-19']);
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $paid->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => '23.33',
            'period_start' => '2026-11-19', 'period_end' => '2027-11-19']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]); // paid, waiting for its registrar

        $this->assertSame(0, $this->notify());
        $this->assertSame([], $this->sent());
    }

    public function test_seven_days_before_it_names_the_open_invoice_and_the_invoice_reminder_is_not_sent_too(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-26']); // 6 days: past the 7th, within catch-up
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-26']);
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => '23.33',
            'period_start' => '2026-10-26', 'period_end' => '2027-10-26']);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => '2026-10-11']);

        $this->assertSame(1, $this->notify());
        $this->assertSame(0, (new PaymentReminders)->send(), 'one mail, not two: the notice names the invoice');

        $this->assertSame(['domain_renewal_notice'], array_column($this->sent(), 0));
        $this->assertStringContainsString('#' . ($invoice->fresh()->number ?: $invoice->id), MailFacade::queued(Mail::class)->first()->renderedHtml);
        $this->assertSame([7, 30], DB::table('domain_reminders')->orderBy('days')->pluck('days')->all(), 'the 30th, missed, counts as sent');
    }

    public function test_the_last_call_is_not_sent_for_a_renewal_charged_on_its_own(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-21']);
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        BillingAgreement::create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-D7', 'type' => 'paypal']);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-21']);
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => '23.33',
            'period_start' => '2026-10-21', 'period_end' => '2027-10-21']);

        $this->assertSame(0, $this->notify());

        $domain->update(['expires_at' => '2026-10-27']); // its 7th: told it will be charged
        $this->assertSame(1, $this->notify());
        $this->assertStringContainsString('charged automatically', MailFacade::queued(Mail::class)->first()->renderedHtml);
    }

    public function test_a_free_domain_gets_its_own_notice(): void
    {
        $this->domain(['recurring_amount' => '0.00']);

        $this->notify();

        $this->assertSame('domain_free_renewal_notice', $this->sent()[0][0]);
    }

    public function test_after_the_expiry_only_while_it_can_be_recovered(): void
    {
        $grace = $this->domain(['expires_at' => '2026-10-17', 'status' => DomainStatus::Grace]); // 3 days past
        $lag = $this->domain(['sld' => 'lag', 'fqdn' => 'lag.com', 'expires_at' => '2026-10-17']); // still Active: not synced yet
        $lost = $this->domain(['sld' => 'lost', 'fqdn' => 'lost.com', 'expires_at' => '2026-10-17', 'status' => DomainStatus::Expired, 'grace_days_override' => 0]);

        $this->assertSame(1, $this->notify());

        $this->assertSame([['domain_expired_notice', 'Your domain nixpal.com has expired']], $this->sent());
        $html = MailFacade::queued(Mail::class)->first()->renderedHtml;
        $this->assertStringContainsString('26/11/2026', $html, 'renewable until its grace ends');
        $this->assertStringContainsString('100,30', $html, 'now with the late renewal fee');
        $this->assertSame(0, DB::table('domain_reminders')->whereIn('domain_id', [$lag->id, $lost->id])->count());
        $this->assertNotNull($grace);
    }

    public function test_renewed_its_next_year_gets_its_notices_again(): void
    {
        $domain = $this->domain();
        $this->notify();
        $domain->update(['expires_at' => '2027-11-19']);

        Carbon::setTestNow('2027-10-20 09:00:00');
        $this->assertSame(1, $this->notify());
    }

    public function test_a_long_missed_notice_is_not_sent_late(): void
    {
        $this->domain(['expires_at' => '2026-11-05']); // 16 days: the 30th was 14 days ago

        $this->assertSame(0, $this->notify());
    }
}
