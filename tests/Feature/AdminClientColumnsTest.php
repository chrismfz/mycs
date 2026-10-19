<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Resources\ClientResource\Pages\ShowInvoices;
use App\Admin\Resources\ClientResource\Pages\ShowServices;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** The client list and the client's Services/Invoices tabs, with WHMCS's columns (docs/admin-ux.md). */
class AdminClientColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]));

        $this->client = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Columns'])->client();
        $product = $this->createProduct();
        $this->service = $this->service(['plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'active', 'domain' => 'columns.example.test']);
        $this->service(['plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'suspended', 'domain' => 'other.example.test']);
    }

    /** @param array<string, mixed> $attributes */
    private function service(array $attributes): Service
    {
        return Service::factory()->create(array_merge(['client_id' => $this->client->id, 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1,
            'expires_at' => now()->addMonth()], $attributes));
    }

    private function invoice(float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Line', 'quantity' => 1, 'price' => $price]);

        return $invoice;
    }

    public function test_the_client_list_shows_services_as_active_of_all_and_the_credit(): void
    {
        Credit::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'amount' => '4.00']);
        $idle = User::factory()->create()->client();

        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$this->client, $idle])
            ->assertTableColumnFormattedStateSet('active_services_count', '1 (2)', $this->client)
            ->assertTableColumnFormattedStateSet('active_services_count', '0 (0)', $idle)
            ->assertSee('Credit: USD 4.00')
            ->assertDontSee('Earnings');
    }

    public function test_the_active_services_filter_splits_both_ways(): void
    {
        $idle = User::factory()->create()->client();

        Livewire::test(ListClients::class)
            ->filterTable('active_services', true)
            ->assertCanSeeTableRecords([$this->client])
            ->assertCanNotSeeTableRecords([$idle])
            ->filterTable('active_services', false)
            ->assertCanSeeTableRecords([$idle])
            ->assertCanNotSeeTableRecords([$this->client]);
    }

    public function test_the_services_tab_has_the_cycle_and_dates_and_searches_the_domain(): void
    {
        Livewire::test(ShowServices::class, ['record' => $this->client->id])
            ->assertTableColumnExists('plan.name')
            ->assertTableColumnExists('expires_at')
            ->assertSee('columns.example.test')
            ->searchTable('columns.example')
            ->assertCanSeeTableRecords([$this->service])
            ->assertCountTableRecords(1)
            ->filterTable('status', 'suspended')
            ->assertCanNotSeeTableRecords([$this->service]);
    }

    public function test_the_invoices_tab_lists_the_newest_first_with_dates_and_what_is_due(): void
    {
        $old = $this->invoice(10);
        $new = $this->invoice(20);

        Livewire::test(ShowInvoices::class, ['record' => $this->client->id])
            ->assertCanSeeTableRecords([$new, $old], inOrder: true)
            ->assertTableColumnExists('due_at')
            ->assertTableColumnExists('formattedCurrentBalance');
    }

    /** The totals' lines, notes and transactions are loaded with the rows: more invoices, not more queries. */
    public function test_the_invoices_tab_does_not_query_per_row(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ShowInvoices::class, ['record' => $this->client->id]);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->invoice(10);
        $queries(); // the role and the currency are looked up once, then cached
        $one = $queries();
        foreach (range(1, 5) as $i) {
            $this->invoice($i);
        }

        $this->assertSame($one, $queries());
    }
}
