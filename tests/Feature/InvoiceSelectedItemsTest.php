<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Admin\Resources\ClientResource\Pages\ShowDomains;
use App\Admin\Resources\ClientResource\Pages\ShowServices;
use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Enums\DomainStatus;
use App\Events\Invoice\Finalized;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D7a «Invoice selected items» (as WHMCS's): staff tick a customer's services and domains, and each one's next unbilled
 * period goes on one invoice per customer and currency now, whatever its due date, by the cron's own rules; what is not
 * billed comes back with why.
 */
class InvoiceSelectedItemsTest extends TestCase
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

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

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

    public function test_services_and_domains_far_from_due_go_on_one_invoice_due_on_the_earliest_and_finished(): void
    {
        Event::fake([Finalized::class]);
        $service = $this->service();
        $domain = $this->domain();

        $result = $this->invoice([$service], [$domain]);

        [$serviceLine] = $this->lines($service);
        [$domainLine] = $this->lines($domain);
        $this->assertSame([$serviceLine->invoice_id], $result['invoices']);
        $this->assertSame($serviceLine->invoice_id, $domainLine->invoice_id, 'one invoice per customer and currency, whatever the due dates');
        $this->assertSame(['2027-03-01', '2027-08-01', '2028-08-01', '23.33'], [$serviceLine->invoice->due_at->toDateString(),
            $domainLine->period_start->toDateString(), $domainLine->period_end->toDateString(), $domainLine->price]);
        $this->assertSame('2027-03-01', $serviceLine->period_start->toDateString());
        $this->assertNull($serviceLine->invoice->finish_pending_at, 'finished: emailed as any renewal invoice');
        Event::assertDispatched(Finalized::class, 1);
        $this->assertSame([], $result['skipped']);
    }

    public function test_the_earliest_line_sets_the_due_date_even_when_it_is_a_domain(): void
    {
        $service = $this->service();
        $domain = $this->domain(['expires_at' => '2026-12-01']);

        $this->invoice([$service], [$domain]);

        $this->assertSame('2026-12-01', $this->lines($service)[0]->invoice->due_at->toDateString());
    }

    public function test_a_period_is_invoiced_once_by_it_or_by_the_cron(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $this->invoice([$service], [$domain]);

        $again = $this->invoice([$service->refresh()], [$domain->refresh()]);
        $this->assertSame([], $again['invoices']);
        $invoiceId = $this->lines($service)[0]->invoice_id;
        $this->assertSame(['#' . $service->id . ' ' . $service->product->name => __('invoices.selected.open_invoice', ['invoice' => $invoiceId]),
            'nixpal.com' => __('invoices.selected.domain_billed', ['invoice' => $invoiceId, 'status' => Invoice::STATUS_UNPAID])], $again['skipped']);

        // Its due days come: the cron bills nothing more.
        Carbon::setTestNow('2027-07-25 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame([1, 1], [count($this->lines($service)), count($this->lines($domain))]);
    }

    public function test_paid_it_renews_and_the_next_period_comes_after_it(): void
    {
        $service = $this->service();
        $this->invoice([$service]);
        $invoice = $this->lines($service)[0]->invoice;
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'bank-1');

        $this->assertSame('2027-04-01', $service->refresh()->expires_at->toDateString());
        $this->invoice([$service]);
        $this->assertSame(['2027-03-01', '2027-04-01'], array_map(fn ($l) => $l->period_start->toDateString(), $this->lines($service)));
    }

    public function test_one_invoice_even_for_a_customer_with_separate_invoices_and_one_per_currency(): void
    {
        $this->user->client()->forceFill(['separate_invoices' => true])->save();
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $a = $this->service();
        $b = $this->service(['expires_at' => '2027-05-01']);
        $usd = $this->service(['currency_code' => 'USD']);

        $result = $this->invoice([$a, $b, $usd]);

        $this->assertCount(2, $result['invoices']);
        $this->assertSame($this->lines($a)[0]->invoice_id, $this->lines($b)[0]->invoice_id);
        $this->assertNotSame($this->lines($a)[0]->invoice_id, $this->lines($usd)[0]->invoice_id);
    }

    public function test_what_is_not_billed_says_why(): void
    {
        $subscription = $this->service(['subscription_id' => 'sub_1']);
        $free = $this->service(['price' => 0]);
        $ended = $this->service(['status' => Service::STATUS_CANCELLED]);
        $off = $this->domain(['sld' => 'off', 'fqdn' => 'off.com', 'auto_renew' => false]);
        $deleted = $this->domain(['sld' => 'gone', 'fqdn' => 'gone.com', 'status' => DomainStatus::Deleted]);

        $result = $this->invoice([$subscription, $free, $ended], [$off, $deleted]);

        $this->assertSame([], $result['invoices']);
        $this->assertSame([__('invoices.selected.subscription'), __('invoices.selected.renewed_free'),
            __('invoices.selected.status', ['status' => Service::statusLabel(Service::STATUS_CANCELLED)]),
            __('invoices.selected.auto_renew_off'), __('invoices.selected.status', ['status' => DomainStatus::Deleted->getLabel()])], array_values($result['skipped']));
        // At no price for its next period it is renewed at once (review F3), as the cron does at its time: nothing to invoice.
        $this->assertSame('2027-04-01', $free->refresh()->expires_at->toDateString());
        $this->assertSame(0, InvoiceItem::query()->count());

        config(['settings.domain_billing_enabled' => false]);
        $this->assertSame([__('invoices.selected.domain_billing_off')], array_values($this->invoice([], [$this->domain()])['skipped']));
    }

    public function test_a_renewal_invoice_a_cron_run_is_still_filling_is_not_finished_by_it(): void
    {
        $cronInvoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now(), 'currency_code' => 'EUR']);
        $cronInvoice->forceFill(['finish_pending_at' => now()])->save();

        $this->invoice([$this->service()]);

        $this->assertNotNull($cronInvoice->refresh()->finish_pending_at);
    }

    public function test_staff_tick_them_on_the_client_page_and_only_the_clients_own_are_billed(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $other = User::factory()->create();
        $foreign = $this->service(['client_id' => $other->client()->id]);
        $client = $this->user->client();

        $page = $this->livewireAs(Staff::factory()->admin()->create())->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()]);
        // Another customer's service is not an option (and the action reads the ids through this client's relations).
        $page->callAction('invoiceSelected', ['services' => [$service->id, $foreign->id], 'domains' => [$domain->id]])
            ->assertHasFormErrors(['services.1']);
        $this->assertSame(0, InvoiceItem::query()->count());

        $this->livewireAs(Staff::factory()->admin()->create())->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()])
            ->callAction('invoiceSelected', ['services' => [$service->id], 'domains' => [$domain->id]])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame($this->lines($service)[0]->invoice_id, $this->lines($domain)[0]->invoice_id);
        $this->assertSame([], $this->lines($foreign));
    }

    public function test_the_bulk_and_single_actions_bill_and_need_the_right_to_create_invoices(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $client = $this->user->client();
        $admin = Staff::factory()->admin()->create();

        $this->livewireAs($admin)->test(ShowServices::class, ['record' => $client->getRouteKey()])
            ->selectTableRecords([$service->id])->callAction(TestAction::make('invoiceSelected')->table()->bulk());
        $this->assertCount(1, $this->lines($service));
        $this->livewireAs($admin)->test(ShowDomains::class, ['record' => $client->getRouteKey()])
            ->selectTableRecords([$domain->id])->callAction(TestAction::make('invoiceSelected')->table()->bulk());
        $this->assertCount(1, $this->lines($domain));

        $other = $this->service(['expires_at' => '2027-06-01']);
        $this->livewireAs($admin)->test(EditService::class, ['record' => $other->getRouteKey()])->callAction('invoiceNextPeriod');
        $this->assertCount(1, $this->lines($other));
        $later = $this->domain(['sld' => 'later', 'fqdn' => 'later.com']);
        $this->livewireAs($admin)->test(ViewDomain::class, ['record' => $later->getRouteKey()])->callAction('invoiceNextPeriod');
        $this->assertCount(1, $this->lines($later));

        $viewer = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.services.viewAny', 'admin.services.view',
            'admin.services.update', 'admin.domains.viewAny', 'admin.domains.view', 'admin.invoices.viewAny'])->create();
        $this->livewireAs($viewer)->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()])->assertActionHidden('invoiceSelected');
        $this->livewireAs($viewer)->test(EditService::class, ['record' => $other->getRouteKey()])->assertActionHidden('invoiceNextPeriod');
    }

    public function test_a_cron_run_does_not_finish_an_invoice_staff_are_still_filling(): void
    {
        // Review F2: while a selection holds its customer's lock, the cron's finish pass leaves its invoice to it.
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now(), 'currency_code' => 'EUR']);
        $invoice->forceFill(['finish_pending_at' => now()])->save();
        $lock = RenewalBilling::selectingLock($this->user->client()->id);
        $this->assertTrue($lock->get());

        (new RenewalBilling)->finishRenewalInvoices();
        $this->assertNotNull($invoice->refresh()->finish_pending_at, 'left to the selection that is filling it');

        $lock->release();
        (new RenewalBilling)->finishRenewalInvoices();
        $this->assertNull($invoice->refresh()->finish_pending_at, 'a leftover once no one fills it');
    }

    public function test_the_single_action_reloads_the_page_and_the_lists_follow_staffs_permissions(): void
    {
        $service = $this->service();
        $admin = Staff::factory()->admin()->create();
        // Review F1: billing may change the service; the page is reloaded so that a later Save does not undo it.
        $this->livewireAs($admin)->test(EditService::class, ['record' => $service->getRouteKey()])->callAction('invoiceNextPeriod')
            ->assertRedirect(ServiceResource::getUrl('edit', ['record' => $service]));

        // Review F4: without the right to see domains, no domain is listed nor billed.
        $domain = $this->domain();
        $billing = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.invoices.viewAny', 'admin.invoices.create',
            'admin.services.viewAny'])->create();
        $this->livewireAs($billing)->test(ClientSummaryPage::class, ['record' => $this->user->client()->getRouteKey()])
            ->mountAction('invoiceSelected')->assertSchemaComponentHidden('domains', 'mountedActionSchema0');
        $this->assertSame([], $this->lines($domain));
    }
}
