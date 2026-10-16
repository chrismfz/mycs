<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Admin\Resources\Common\RelationManagers\PropertiesRelationManager;
use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserAuthenticationLog;
use App\Services\Admin\ClientSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The client's «Summary» (WHMCS's client profile): one screen with its details, invoices by status, services, credit,
 * last login and mails; its actions only for staff who may take them.
 */
class ClientSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Summary']);
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 7.5, 'quantity' => 2, 'domain' => 'summary.example.test', 'expires_at' => now()->addMonth()]);
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function invoice(string $status, float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $invoice->items()->create(['description' => 'Line', 'quantity' => 1, 'price' => $price]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]); // as imported: only the status matters here

        return $invoice->fresh();
    }

    public function test_it_sums_invoices_per_status_and_counts_services(): void
    {
        $client = $this->user->client();
        DB::table('invoices')->where('client_id', $client->id)->delete(); // the factory's own, if any
        $this->invoice(Invoice::STATUS_PAID, 10);
        $this->invoice(Invoice::STATUS_PAID, 0.2);
        $this->invoice(Invoice::STATUS_UNPAID, 20)->transactions()->create(['amount' => 5]);
        $this->invoice(Invoice::STATUS_CANCELLED, 3);
        Credit::query()->create(['client_id' => $client->id, 'currency_code' => 'USD', 'amount' => '4.00']);
        Service::factory()->create(['client_id' => $client->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'suspended', 'currency_code' => 'USD', 'price' => 1, 'quantity' => 1]);
        Ticket::query()->create(['subject' => 'Help', 'status' => 'open', 'priority' => 'low', 'client_id' => $client->id]);
        Ticket::query()->create(['subject' => 'Done', 'status' => 'closed', 'priority' => 'low', 'client_id' => $client->id]);

        $summary = ClientSummary::for($client->fresh(), $this->staff(['*']));

        $this->assertSame(2, $summary['invoices']['statuses'][Invoice::STATUS_PAID]['count']);
        $this->assertStringContainsString('10.20', implode(' ', $summary['invoices']['statuses'][Invoice::STATUS_PAID]['total']));
        $this->assertSame(1, $summary['invoices']['statuses'][Invoice::STATUS_UNPAID]['count']);
        $this->assertStringContainsString('15.00', implode(' ', $summary['invoices']['due']), 'what is still due, not the total');
        $this->assertSame(1, $summary['invoices']['statuses'][Invoice::STATUS_CANCELLED]['count']);
        $this->assertStringContainsString('4.00', implode(' ', $summary['credit']));
        $this->assertSame([1, 1], [$summary['services']['counts']['active'], $summary['services']['counts']['suspended']]);
        $this->assertSame($this->service->id, $summary['services']['rows'][0]['id'], 'active first');
        $this->assertStringContainsString('15.00', $summary['services']['rows'][0]['amount'], 'price × quantity');
        $this->assertSame(1, $summary['open_tickets']);
        $this->assertSame('Maria Summary', $summary['details']['name']);
    }

    public function test_the_page_shows_the_client_and_offers_only_what_staff_may_do(): void
    {
        EmailLog::query()->create(['subject' => 'Welcome aboard', 'to' => 'maria@example.test', 'body' => 'b', 'status' => EmailLog::STATUS_SENT,
            'client_id' => $this->user->client()->id]);
        $client = $this->user->client();

        $this->actingAs($this->staff(['admin.clients.viewAny', 'admin.services.viewAny', 'admin.email_logs.viewAny', 'admin.email_logs.view']))
            ->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertOk()->assertSee('Maria Summary')->assertSee('summary.example.test')->assertSee('Welcome aboard');

        $this->livewireAs($this->staff(['admin.clients.viewAny']))->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('newInvoice')->assertActionHidden('newOrder')->assertActionHidden('impersonate')->assertActionHidden('edit');
        $this->livewireAs($this->staff(['*']))->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()])
            ->assertActionVisible('newInvoice')->assertActionVisible('newTicket')->assertActionVisible('edit');

        $this->actingAs($this->staff(['admin.services.viewAny']))->get(ClientResource::getUrl('view', ['record' => $client]))->assertForbidden();
    }

    public function test_staff_who_may_only_see_clients_see_no_money_services_mails_or_logins(): void
    {
        $client = $this->user->client();
        $this->invoice(Invoice::STATUS_UNPAID, 123.45);
        EmailLog::query()->create(['subject' => 'Secret subject', 'to' => 'maria@example.test', 'body' => 'b', 'status' => EmailLog::STATUS_SENT, 'client_id' => $client->id]);
        DB::table('client_user')->where('client_id', $client->id)->update(['last_login_at' => now()]);
        UserAuthenticationLog::query()->create(['user_id' => $this->user->id, 'ip_address' => '198.51.100.23']);

        $page = $this->actingAs($this->staff(['admin.clients.viewAny']))->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk()
            ->assertSee('Maria Summary');
        foreach (['123.45', 'summary.example.test', 'Secret subject', '198.51.100.23', __('Credit Balance')] as $hidden) {
            $page->assertDontSee($hidden);
        }
        $this->assertSame(['details', 'invoices', 'credit', 'services', 'domains', 'open_tickets', 'emails', 'other'], array_keys(ClientSummary::for($client, null)));
        $this->assertNull(ClientSummary::for($client, null)['invoices']);

        $this->actingAs($this->staff(['*']))->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertSee('123.45')->assertSee('summary.example.test')->assertSee('Secret subject')->assertSee('198.51.100.23');
    }

    public function test_the_summary_has_no_relation_managers_to_edit_from(): void
    {
        $client = $this->user->client();

        // Properties have an inline-editable value: on the Summary (viewAny only) they would be a write (review B1).
        $this->actingAs($this->staff(['admin.clients.viewAny']))->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertDontSeeLivewire(PropertiesRelationManager::class)->assertDontSeeLivewire(UsersRelationManager::class);
        $this->actingAs($this->staff(['*']))->get(ClientResource::getUrl('edit', ['record' => $client]))
            ->assertSeeLivewire(UsersRelationManager::class);
    }

    public function test_the_last_login_address_is_the_one_used_last(): void
    {
        $client = $this->user->client();
        DB::table('client_user')->where('client_id', $client->id)->update(['last_login_at' => now()]);
        $old = UserAuthenticationLog::query()->create(['user_id' => $this->user->id, 'ip_address' => '198.51.100.1']);
        UserAuthenticationLog::query()->create(['user_id' => $this->user->id, 'ip_address' => '198.51.100.2']);
        DB::table('user_authentication_logs')->where('ip_address', '198.51.100.2')->update(['last_used_at' => now()->subDay()]);
        DB::table('user_authentication_logs')->where('id', $old->id)->update(['last_used_at' => now()]); // back on an older address

        $this->assertSame('198.51.100.1', ClientSummary::for($client, $this->staff(['*']))['other']['last_login_ip']);
    }

    public function test_create_invoice_from_the_summary_comes_with_the_client(): void
    {
        $client = $this->user->client();

        Livewire::withQueryParams(['client_id' => $client->id])->actingAs($this->staff(['*']), 'staff')->test(CreateInvoice::class)
            ->assertSchemaStateSet(['client_id' => $client->id]);
        Livewire::withQueryParams([])->actingAs($this->staff(['*']), 'staff')->test(CreateInvoice::class)->assertSchemaStateSet(['client_id' => null]);
    }

    public function test_cycles_are_named_as_whmcs_names_them(): void
    {
        $plan = fn (string $type, int $period = 1, string $unit = 'month') => new Plan(['type' => $type, 'billing_period' => $period, 'billing_unit' => $unit]);

        $this->assertSame(['Monthly', 'Quarterly', 'Annually', 'Biennially', 'Free', 'One time', '2 week'], [
            ClientSummary::cycle($plan('recurring')), ClientSummary::cycle($plan('recurring', 3)), ClientSummary::cycle($plan('recurring', 1, 'year')),
            ClientSummary::cycle($plan('recurring', 24)), ClientSummary::cycle($plan('free')), ClientSummary::cycle($plan('one-time')),
            ClientSummary::cycle($plan('recurring', 2, 'week')),
        ]);
    }
}
