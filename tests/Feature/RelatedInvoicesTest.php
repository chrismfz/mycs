<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource;
use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\DomainResource\RelationManagers\RelatedInvoicesRelationManager as DomainRelatedInvoices;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\RelationManagers\InvoicesRelationManager;
use App\Admin\Resources\ServiceResource\RelationManagers\RelatedInvoicesRelationManager as ServiceRelatedInvoices;
use App\Models\Client;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Related invoices» on a service and on a domain (owner 2026-11-11): each of its invoice lines with the line's own
 * amount and period, its invoice's status, when it was paid and how; never a line of another service, domain or client;
 * only for staff who may list invoices.
 */
class RelatedInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->client = User::factory()->create()->clients()->firstOrFail();
        $this->actingAs($this->staff(['*']), 'staff');
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function service(?Client $client = null, ?Service $parent = null, string $name = 'Hosting'): Service
    {
        $product = $this->createProduct();
        $product->product->update(['name' => $name]);

        return Service::factory()->create(['client_id' => ($client ?? $this->client)->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'parent_service_id' => $parent?->id, 'status' => 'active', 'expires_at' => '2027-01-01',
            'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
    }

    private function domain(string $fqdn, ?Client $client = null): Domain
    {
        DomainTld::query()->firstOrCreate(['tld' => 'test'], ['is_active' => true, 'sort' => 1]);

        return Domain::factory()->create(['client_id' => ($client ?? $this->client)->id, 'sld' => explode('.', $fqdn)[0], 'tld' => 'test', 'fqdn' => $fqdn]);
    }

    /** @param array<string, mixed> $line */
    private function line(Service|Domain|ServiceUpgrade $of, string $description, array $line = [], ?Client $client = null): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        return $invoice->items()->create(['reference_type' => $of->getMorphClass(), 'reference_id' => $of->getKey(), 'description' => $description,
            'quantity' => 1, 'price' => 10, ...$line]);
    }

    /** Paid as a payment leaves it, without the rules of paying (not what is tested here). */
    private function paid(Invoice $invoice, string $on, string $gateway): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => $gateway], ['name' => $gateway, 'type' => 'gateway']);
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'gateway_id' => $gateway->id, 'amount' => 10, 'status' => 'succeeded',
            'created_at' => $on, 'updated_at' => $on]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => $on]);
    }

    public function test_a_service_shows_its_own_lines_with_the_lines_amount_period_and_payment(): void
    {
        $service = $this->service();
        $paid = $this->line($service, 'Hosting renewal 2026', ['price' => 12.50, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);
        // Another line on the same invoice: the invoice's total is not this line's amount.
        $paid->invoice->items()->create(['description' => 'Something else', 'quantity' => 1, 'price' => 80]);
        $this->paid($paid->invoice, '2026-01-03 10:00:00', 'BankWire');
        $imported = $this->line($service, 'Imported line without a period', ['price' => 7]);
        $open = $this->line($service, 'Hosting renewal 2027', ['period_start' => '2027-01-01', 'period_end' => '2028-01-01']);

        $table = Livewire::test(ServiceRelatedInvoices::class, ['ownerRecord' => $service, 'pageClass' => EditService::class])
            ->assertCanSeeTableRecords([$paid, $imported, $open], inOrder: false)
            ->assertCountTableRecords(3)
            ->assertSee('Hosting renewal 2026')
            ->assertSee('01/01/2026 – 01/01/2027')
            ->assertSee('12.50')
            ->assertDontSee('92.50')
            ->assertDontSee('Something else')
            ->assertSee('03/01/2026')
            ->assertSee('BankWire')
            ->assertSee(InvoiceResource::getUrl('edit', ['record' => $paid->invoice]));

        $this->assertSame('01/01/2026 – 01/01/2027', InvoiceResource\RelatedInvoiceLines::period($paid));
        $this->assertNull(InvoiceResource\RelatedInvoiceLines::period($imported), 'no period on the line: none shown, never a guess');
        // Newest first.
        $this->assertSame([$open->id, $imported->id, $paid->id], $table->instance()->getTableRecords()->pluck('id')->all());
    }

    public function test_a_service_shows_its_addons_and_package_changes_saying_which_and_nothing_of_others(): void
    {
        $service = $this->service();
        $addon = $this->service(parent: $service, name: 'Extra IPv4');
        $upgrade = ServiceUpgrade::query()->forceCreate(['service_id' => $service->id, 'product_id' => $service->product_id, 'plan_id' => $service->plan_id]);
        $own = $this->line($service, 'Own line');
        $charge = $this->line($service, 'Setup fee', ['kind' => InvoiceItem::KIND_CHARGE]);
        $ofAddon = $this->line($addon, 'Addon line');
        $ofUpgrade = $this->line($upgrade, 'Upgrade line');

        // Not its: another service of the same client, another client's service, an upgrade of another service, a domain
        // whose id is the service's.
        $sibling = $this->service();
        $stranger = User::factory()->create()->clients()->firstOrFail();
        $theirs = $this->service($stranger);
        $otherUpgrade = ServiceUpgrade::query()->forceCreate(['service_id' => $sibling->id, 'product_id' => $sibling->product_id, 'plan_id' => $sibling->plan_id]);
        $domain = $this->domain('same-id.test');
        DB::table('domains')->where('id', $domain->id)->update(['id' => $service->id + 1000]);
        $not = [$this->line($sibling, 'Sibling line'), $this->line($theirs, 'Stranger line', client: $stranger), $this->line($otherUpgrade, 'Other upgrade line'),
            $this->line($domain, 'Domain line', ['reference_id' => $service->id])];

        $manager = Livewire::test(ServiceRelatedInvoices::class, ['ownerRecord' => $service, 'pageClass' => EditService::class])
            ->assertCanSeeTableRecords([$own, $charge, $ofAddon, $ofUpgrade], inOrder: false)
            ->assertCanNotSeeTableRecords($not)
            ->assertCountTableRecords(4)
            ->assertSee('Addon: Extra IPv4')
            ->assertSee('Package change')
            ->instance();

        $this->assertSame(['Service', 'Service · Charge', 'Addon: Extra IPv4', 'Package change'],
            array_map(fn (InvoiceItem $line) => $manager->type($line), [$own, $charge, $ofAddon, $ofUpgrade]));

        // The addon's own page: its lines only, not its parent's.
        Livewire::test(ServiceRelatedInvoices::class, ['ownerRecord' => $addon, 'pageClass' => EditService::class])
            ->assertCanSeeTableRecords([$ofAddon])->assertCountTableRecords(1);
    }

    public function test_a_domain_shows_its_own_lines_and_nothing_of_others(): void
    {
        $domain = $this->domain('mine.test');
        $register = $this->line($domain, 'mine.test registration', ['kind' => InvoiceItem::KIND_REGISTER, 'price' => 9.90]);
        $this->paid($register->invoice, '2025-02-01 09:00:00', 'Card');
        $renewal = $this->line($domain, 'mine.test renewal', ['period_start' => '2026-02-01', 'period_end' => '2028-02-01']);
        $imported = $this->line($domain, 'mine.test imported');
        // As the importer may leave it (written past the observer, which gives a line without a period the kind «charge»).
        DB::table('invoice_items')->where('id', $imported->id)->update(['kind' => null]);
        $imported->refresh();
        $charge = $this->line($domain, 'mine.test holder change');
        $fee = $this->line($domain, 'mine.test late fee', ['kind' => InvoiceItem::STAGE_FEES[0]]);

        $stranger = User::factory()->create()->clients()->firstOrFail();
        $service = $this->service();
        $not = [$this->line($this->domain('other.test'), 'Other domain line'), $this->line($this->domain('theirs.test', $stranger), 'Stranger line', client: $stranger),
            $this->line($service, 'Service line', ['reference_id' => $domain->id])];

        Livewire::test(DomainRelatedInvoices::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])
            ->assertCanSeeTableRecords([$register, $renewal, $imported, $charge, $fee], inOrder: false)
            ->assertCanNotSeeTableRecords($not)
            ->assertCountTableRecords(5)
            ->assertSee('9.90')
            ->assertSee('01/02/2026 – 01/02/2028')
            ->assertSee('01/02/2025')
            ->assertSee('Card')
            ->assertSee(InvoiceResource::getUrl('edit', ['record' => $register->invoice]));

        $this->assertSame(['Registration', 'Renewal', null, 'Charge', 'Late fee'],
            array_map(DomainRelatedInvoices::type(...), [$register, $renewal, $imported, $charge, $fee]));
    }

    public function test_only_five_rows_show_at_first_and_the_rest_by_paging(): void
    {
        $service = $this->service();
        foreach (range(1, 7) as $n) {
            $this->line($service, 'Line ' . $n);
        }

        $manager = Livewire::test(ServiceRelatedInvoices::class, ['ownerRecord' => $service, 'pageClass' => EditService::class])->instance();

        $this->assertCount(5, $manager->getTableRecords());
        $this->assertSame(7, $manager->getTableRecords()->total());
    }

    public function test_staff_who_may_not_list_invoices_do_not_see_it(): void
    {
        $service = $this->service();
        $domain = $this->domain('mine.test');

        $this->assertTrue(ServiceRelatedInvoices::canViewForRecord($service, EditService::class));
        $this->assertTrue(DomainRelatedInvoices::canViewForRecord($domain, ViewDomain::class));
        $this->assertContains(ServiceRelatedInvoices::class, ServiceResource::getRelations());
        $this->assertContains(InvoicesRelationManager::class, ServiceResource::getRelations(), '«Invoices» stays next to it');
        $this->assertContains(DomainRelatedInvoices::class, DomainResource::getRelations());

        $this->actingAs($this->staff(['admin.services.viewAny', 'admin.services.view', 'admin.services.update', 'admin.domains.viewAny', 'admin.domains.view']), 'staff');

        $this->assertFalse(ServiceRelatedInvoices::canViewForRecord($service, EditService::class));
        $this->assertFalse(DomainRelatedInvoices::canViewForRecord($domain, ViewDomain::class));
        // On the pages: no tab of it (a tab's content loads when opened, so its label is what the page has).
        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])->assertOk()->assertDontSee('Related invoices');
        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])->assertOk()->assertDontSee('Related invoices');

        $this->actingAs($this->staff(['*']), 'staff');
        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])->assertSee('Related invoices');
        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])->assertSee('Related invoices');
    }
}
