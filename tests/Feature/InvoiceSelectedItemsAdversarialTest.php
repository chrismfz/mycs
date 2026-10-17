<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Enums\DomainStatus;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR #171 (D7a «Invoice selected items»). Failing tests are findings; passing ones document attacks
 * that were defended.
 */
class InvoiceSelectedItemsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']]);
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_billing_enabled' => true]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([['renew', 1, '23.33'], ['grace', 1, '100.30']] as [$operation, $years, $price]) {
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

    private function service(array $attributes = [], string $planType = 'recurring'): Service
    {
        $product = $this->createProduct();
        if ($planType !== 'recurring') {
            Plan::query()->whereKey($product->plan->id)->update(['type' => $planType]);
        }

        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2027-03-01',
        ]);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2027-08-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** @return list<InvoiceItem> */
    private function lines(Service|Domain $item): array
    {
        return InvoiceItem::query()->where('reference_type', $item::class)->where('reference_id', $item->id)->orderBy('id')->get()->all();
    }

    private function invoice(array $services = [], array $domains = []): array
    {
        return (new InvoiceSelectedItems)->handle($services, $domains);
    }

    // ---------------------------------------------------------------- findings (fail on the PR as it is)

    /**
     * ADV-1: a one-time (or free-type) service has no next period: the cron never bills it (no expires_at, and
     * periodEnd() is null for these plans), but «Invoice selected items» bills it again at full price, a period from
     * today with no end. The client page lists every active service, so a one-time product is one tick away.
     */
    public function test_a_one_time_service_is_not_billed_again(): void
    {
        $oneTime = $this->service(['expires_at' => null, 'price' => 50.00], planType: 'one-time');

        $result = $this->invoice([$oneTime]);

        $this->assertSame([], $this->lines($oneTime), 'a one-time product was billed a «next period» it does not have');
        $this->assertSame([], $result['invoices']);
    }

    /** ADV-1b: the same for a one-time service that does carry an expiry date (imported, or set by staff). */
    public function test_a_one_time_service_with_an_expiry_is_not_billed_again(): void
    {
        $oneTime = $this->service(['expires_at' => '2027-01-01', 'price' => 50.00], planType: 'one-time');

        $this->invoice([$oneTime]);

        $this->assertSame([], $this->lines($oneTime));
    }

    /**
     * ADV-2: credit goes to the new invoice, due months from now, before an older open invoice of the same customer
     * that is due (or overdue) now. The cron pays oldest due first (credits step before invoices_created, MT-1e); here
     * the far one takes the credit and the due one stays unpaid, and its service falls to dunning/suspension while the
     * customer's credit sat on a period not yet started.
     */
    public function test_credit_pays_an_older_due_invoice_before_a_far_one_made_by_the_selection(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $client = $this->user->client();
        $overdueService = $this->service(['expires_at' => '2026-10-10']);
        $old = Invoice::query()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => '2026-10-10', 'currency_code' => 'EUR']);
        $old->items()->create(['reference_type' => Service::class, 'reference_id' => $overdueService->id, 'price' => 10.00, 'quantity' => 1,
            'description' => 'renewal', 'period_start' => '2026-10-10', 'period_end' => '2026-11-10']);
        (new CreditLedger)->add($client->id, 'EUR', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'credit');

        $far = $this->service(['expires_at' => '2027-09-01']);
        $result = $this->invoice([$far]);

        $this->assertCount(1, $result['invoices']);
        $this->assertSame(Invoice::STATUS_PAID, $old->refresh()->status, 'the overdue invoice should get the credit first');
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->find($result['invoices'][0])->status,
            'the credit was spent on a period starting in 2027 while an overdue invoice stays unpaid');
    }

    /**
     * ADV-3: staff with the right to create invoices but not to see domains (admin.domains.viewAny / view): the client page
     * hides the customer's domains from them (ClientSummaryPage), yet the action's form lists every domain (fqdn, expiry)
     * and bills the ones they tick.
     */
    public function test_staff_without_the_right_to_see_domains_cannot_bill_them_from_the_client_page(): void
    {
        $domain = $this->domain();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.services.viewAny', 'admin.services.view',
            'admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.create'])->create();

        $this->livewireAs($staff)->test(ClientSummaryPage::class, ['record' => $this->user->client()->getRouteKey()])
            ->callAction('invoiceSelected', ['services' => [], 'domains' => [$domain->id]]);

        $this->assertSame([], $this->lines($domain), 'a domain the staff member may not see was billed');
    }

    // ---------------------------------------------------------------- attacks that were defended (pass)

    public function test_defended_the_same_service_and_domain_selected_twice_in_one_call_are_billed_once(): void
    {
        $service = $this->service();
        $domain = $this->domain();

        $result = $this->invoice([$service, $service->replicate()->setRawAttributes($service->getAttributes()), $service], [$domain, $domain]);

        $this->assertCount(1, $this->lines($service));
        $this->assertCount(1, $this->lines($domain));
        $this->assertCount(1, $result['invoices']);
    }

    public function test_defended_a_grace_domain_and_a_far_service_on_one_invoice_due_today_do_not_suspend_the_far_service(): void
    {
        $this->settings(['cronjob_suspend_requires_invoice' => [false, 'boolean']]);
        $service = $this->service(['expires_at' => '2027-06-01']);
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-01']);

        $result = $this->invoice([$service], [$domain]);

        $this->assertCount(1, $result['invoices']);
        $invoice = Invoice::query()->find($result['invoices'][0]);
        $this->assertSame('2026-10-20', $invoice->due_at->toDateString());
        // The renewal (one year in grace) and the grace fee, once.
        $kinds = collect($this->lines($domain))->pluck('kind')->all();
        $this->assertSame([null, 'grace_fee'], $kinds);
        $this->assertSame(['23.33', '100.30'], collect($this->lines($domain))->pluck('price')->all());

        // The invoice is overdue for a month: the service, paid until 2027-06-01, is not suspended.
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_ACTIVE, $service->refresh()->status);
        $this->assertCount(1, $this->lines($service), 'and not billed a second time');
    }

    /**
     * ADV-4: staff invoice a period months early by mistake and cancel that invoice. The cancellation gives the period up
     * (Δ3, InvoiceObserver::givePeriodsUp sets forgone_at): when the period comes the cron bills nothing, and the service
     * is suspended for non-payment with no invoice to pay (with «suspend requires an invoice» off, the default). Decided
     * for the cron's own invoices; with a tool that bills ahead on purpose, a cancelled premature invoice should not
     * end a service's billing.
     */
    public function test_a_premature_selection_invoice_cancelled_by_staff_does_not_stop_the_service_being_billed_at_its_time(): void
    {
        $service = $this->service(['expires_at' => '2027-03-01']);
        $result = $this->invoice([$service]);
        Invoice::query()->find($result['invoices'][0])->update(['status' => Invoice::STATUS_CANCELLED]);

        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $lines = $this->lines($service->refresh());
        $this->assertCount(2, $lines, 'the cron bills the period at its time');
        $this->assertSame('2027-03-01', $lines[1]->period_start->toDateString());
    }

    /** ADV-4b: the same for a domain: a cancelled premature renewal is «billed once, whatever became of it» (D5b). */
    public function test_a_premature_selection_invoice_of_a_domain_cancelled_by_staff_does_not_stop_its_renewal_billing(): void
    {
        $domain = $this->domain(['expires_at' => '2027-03-01']);
        $result = $this->invoice([], [$domain]);
        Invoice::query()->find($result['invoices'][0])->update(['status' => Invoice::STATUS_CANCELLED]);

        Carbon::setTestNow('2027-02-20 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertCount(2, $this->lines($domain), 'the cron bills the domain renewal at its time');
    }

    public function test_defended_a_suspended_service_with_nothing_paid_ahead_is_not_billed(): void
    {
        $service = $this->service(['status' => Service::STATUS_SUSPENDED, 'expires_at' => '2026-09-01']);

        $result = $this->invoice([$service]);

        $this->assertSame([], $this->lines($service));
        $this->assertSame([__('invoices.selected.nothing')], array_values($result['skipped']));
    }

    public function test_defended_quantity_and_mixed_currencies(): void
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $three = $this->service(['quantity' => 3]);
        $usd = $this->service(['currency_code' => 'USD']);
        $domain = $this->domain();

        $result = $this->invoice([$three, $usd], [$domain]);

        $this->assertCount(2, $result['invoices']);
        $eur = Invoice::query()->find($this->lines($three)[0]->invoice_id);
        $this->assertSame($eur->id, $this->lines($domain)[0]->invoice_id);
        $this->assertEqualsWithDelta(53.33, (float) $eur->refresh()->total, 0.001, '3 x 10.00 + 23.33');
        $this->assertNotSame($eur->id, $this->lines($usd)[0]->invoice_id);
    }

    public function test_defended_the_selection_never_adds_to_or_finishes_a_cron_invoice_of_the_same_customer(): void
    {
        $client = $this->user->client();
        $cronInvoice = Invoice::query()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => '2027-03-01', 'currency_code' => 'EUR']);
        $cronInvoice->forceFill(['finish_pending_at' => now()])->save();

        $result = $this->invoice([$this->service()]);

        $this->assertNotContains($cronInvoice->id, $result['invoices']);
        $this->assertSame(0, $cronInvoice->items()->count());
        $this->assertNotNull($cronInvoice->refresh()->finish_pending_at);
    }

    public function test_defended_a_far_invoice_is_not_charged_or_paid_by_credit_when_auto_use_is_off(): void
    {
        $client = $this->user->client();
        (new CreditLedger)->add($client->id, 'EUR', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'credit');

        $result = $this->invoice([$this->service()]);

        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->find($result['invoices'][0])->status);
        $this->assertSame('50.00', number_format((float) Credit::query()->where('client_id', $client->id)->value('amount'), 2, '.', ''));
    }

    public function test_defended_the_cron_bills_nothing_more_after_a_selection_of_a_grace_domain(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-01']);
        $this->invoice([], [$domain]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame([null, 'grace_fee'], collect($this->lines($domain))->pluck('kind')->all());
    }
}
