<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\Coupon;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The MCP's wider reads (owner 2026-11-14): `search` across accounts, services, domains and tickets; `client` with its
 * domains and tickets; `catalog`; `revenue` month by month as the admin's Income report. Each part only with its
 * permission, as in the admin.
 */
class McpSearchCatalogRevenueTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function key(Staff $staff): string
    {
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $plain;
    }

    /** @return array<string, mixed> */
    private function ask(string $key, string $tool, array $arguments = []): array
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])->assertOk();
        $this->assertNotTrue($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return (array) json_decode((string) $response->json('result.content.0.text'), true);
    }

    private function error(string $key, string $tool, array $arguments = []): string
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])->assertOk();
        $this->assertTrue($response->json('result.isError'));

        return (string) $response->json('result.content.0.text');
    }

    /** @return list<string> */
    private function tools(string $key): array
    {
        return collect($this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->json('result.tools'))->pluck('name')->all();
    }

    public function test_each_new_tool_is_offered_only_with_its_permission(): void
    {
        $new = ['search', 'catalog', 'revenue'];

        $this->assertSame([], array_values(array_intersect($new, $this->tools($this->key($this->staff([McpAuth::PERMISSION]))))));
        $this->assertSame($new, array_values(array_intersect($new, $this->tools($this->key($this->staff(['*']))))));
        $this->assertSame(['revenue'], array_values(array_intersect($new, $this->tools($this->key($this->staff([McpAuth::PERMISSION, 'admin.reports.view']))))));
    }

    public function test_search_finds_an_account_by_company_vat_domain_and_ip_and_each_kind_needs_its_permission(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $client->primaryContact->update(['company_name' => 'Ψάρια Θεσσαλονίκης ΙΚΕ', 'vat_number' => 'EL099887766']);
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'psaria-thess', 'tld' => 'gr', 'fqdn' => 'psaria-thess.gr']);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => Product::factory()->create(['name' => 'Hosting Plus'])->id,
            'status' => Service::STATUS_ACTIVE, 'domain' => 'shop.psaria-thess.gr', 'dedicated_ip' => '203.0.113.77']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'Mail bounces']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Our newsletter to 50% of the list bounced', 'is_internal' => false]);
        User::factory()->create(); // another account, not found

        $all = $this->key($this->staff(['*']));
        foreach (['Θεσσαλονίκης', '099887766', 'psaria-thess', '203.0.113.77'] as $text) {
            $this->assertSame([$client->id], array_column($this->ask($all, 'search', ['query' => $text])['clients'], 'id'), $text);
        }
        $found = $this->ask($all, 'search', ['query' => 'psaria-thess']);
        $this->assertSame([$service->id], array_column($found['services'], 'id'));
        $this->assertStringContainsString('Hosting Plus', $found['services'][0]['service']);
        $this->assertSame(['psaria-thess.gr'], array_column($found['domains'], 'domain'));
        $this->assertSame([$ticket->id], array_column($this->ask($all, 'search', ['query' => '50% of', 'in' => 'tickets'])['tickets'], 'id'), 'by a message, % literally');
        $this->assertSame([], $this->ask($all, 'search', ['query' => '5_% o', 'in' => 'tickets'])['tickets'], '_ is no wildcard');
        $this->assertSame([$ticket->id], array_column($this->ask($all, 'search', ['query' => 'MAIL BOUNCES', 'in' => 'tickets'])['tickets'], 'id'), 'case aside');

        $clientsKey = $this->key($this->staff([McpAuth::PERMISSION, 'admin.clients.viewAny']));
        $this->assertSame([], $this->ask($clientsKey, 'search', ['query' => 'psaria-thess'])['clients'], 'not by the domains or services the role may not see (SEC-1)');
        $clientsOnly = $this->ask($clientsKey, 'search', ['query' => 'Θεσσαλονίκης']);
        $this->assertSame([$client->id], array_column($clientsOnly['clients'], 'id'));
        $this->assertArrayNotHasKey('services', $clientsOnly);
        $this->assertArrayNotHasKey('domains', $clientsOnly);
        $this->assertArrayNotHasKey('tickets', $clientsOnly);

        $this->assertStringContainsString('at least 3', $this->error($all, 'search', ['query' => 'ab']));
    }

    public function test_client_gives_its_domains_tickets_and_contact_to_staff_who_may_see_them(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $client->primaryContact->update(['vat_number' => 'EL011223344', 'phone' => '+30.2310000000']);
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'late', 'tld' => 'gr', 'fqdn' => 'late.gr', 'status' => DomainStatus::Redemption]);
        Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'Site down', 'status' => Ticket::STATUS_OPEN]);
        Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'Old one', 'status' => Ticket::STATUS_CLOSED]);

        $data = $this->ask($this->key($this->staff(['*'])), 'client', ['client' => (string) $client->id]);

        $this->assertSame([['late.gr', 'redemption']], array_map(fn ($d) => [$d['domain'], $d['status']], $data['domains']));
        $this->assertSame(1, $data['tickets']['open']);
        $this->assertSame(['Old one', 'Site down'], collect($data['tickets']['latest'])->pluck('subject')->sort()->values()->all());
        $this->assertSame('EL011223344', $data['contact']['vat_number']);

        $narrow = $this->ask($this->key($this->staff([McpAuth::PERMISSION, 'admin.clients.viewAny'])), 'client', ['client' => (string) $client->id]);
        $this->assertNull($narrow['domains']);
        $this->assertNull($narrow['tickets']);
    }

    public function test_catalog_lists_products_with_their_prices_and_coupons_only_with_their_permission(): void
    {
        $product = Product::factory()->create(['name' => 'VPS Small']);
        $plan = $product->plans()->create(['name' => 'Annually', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
        $plan->prices()->create(['price' => '120.00', 'setup_fee' => '10.00', 'currency_code' => 'EUR']);
        Product::factory()->create(['name' => 'Old VPS', 'retired' => true]);
        Service::factory()->create(['product_id' => $product->id, 'client_id' => User::factory()->create()->client()->id, 'status' => Service::STATUS_ACTIVE]);
        Coupon::create(['code' => 'SPRING25', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 25, 'recurring' => null]);

        $all = $this->key($this->staff(['*']));
        $products = $this->ask($all, 'catalog', ['query' => 'vps'])['products'];
        $this->assertSame(['VPS Small'], array_column($products, 'name'), 'retired left out');
        $this->assertSame(1, $products[0]['active_services']);
        $this->assertSame([['currency' => 'EUR', 'price' => '120.00', 'setup_fee' => '10.00']], $products[0]['plans'][0]['prices']);
        $this->assertSame('1 year', $products[0]['plans'][0]['cycle']);
        $this->assertCount(2, $this->ask($all, 'catalog', ['query' => 'vps', 'retired' => true])['products']);
        $this->assertSame('VPS Small', $this->ask($all, 'catalog', ['product' => (string) $product->id])['product']['name']);
        $this->assertSame(['SPRING25'], array_column($this->ask($all, 'catalog', ['part' => 'coupons'])['coupons'], 'code'));

        $productsOnly = $this->key($this->staff([McpAuth::PERMISSION, 'admin.products.viewAny']));
        $this->assertCount(1, $this->ask($productsOnly, 'catalog')['products']);
        $this->assertStringContainsString('may not see the coupons', $this->error($productsOnly, 'catalog', ['part' => 'coupons']));
    }

    public function test_revenue_month_by_month_against_the_month_before_and_a_year_earlier(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $client = User::factory()->create()->client();
        $paid = function (string $when, string $net) use ($client) {
            $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
            $invoice->items()->create(['description' => 'x', 'quantity' => 1, 'price' => $net, 'tax_rate' => '24']);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => $when]);
        };
        $paid('2026-10-05 10:00:00', '200.00');
        $paid('2026-11-02 10:00:00', '150.00');
        $paid('2025-11-10 10:00:00', '100.00'); // within the same days a year earlier
        $paid('2025-11-20 10:00:00', '300.00'); // after them

        $data = $this->ask($this->key($this->staff([McpAuth::PERMISSION, 'admin.reports.view'])), 'revenue');

        $this->assertSame('EUR', $data['currency']);
        $this->assertSame('2026-11', end($data['months'])['month'], 'no month to come');
        $november = end($data['months']);
        $this->assertSame(['150.00', '-50.00', '-25.0'], [$november['net'], $november['vs_previous_month']['amount'], $november['vs_previous_month']['percent']]);
        $this->assertSame(['-250.00', '-62.5'], [$november['vs_same_month_last_year']['amount'], $november['vs_same_month_last_year']['percent']]);
        $this->assertSame(['150.00', '100.00', '50.00', '50.0'], [$data['running_month']['net'], $data['running_month']['same_days_last_year'],
            $data['running_month']['change']['amount'], $data['running_month']['change']['percent']]);
        $this->assertSame(['350.00', '100.00'], [$data['year_to_date']['net'], $data['year_to_date']['same_days_last_year']]);
        $this->assertNull($data['months'][0]['vs_previous_month']['percent'], 'nothing the month before: no %');
        $this->assertArrayNotHasKey('by_product', $data);
        $this->assertArrayHasKey('by_product', $this->ask($this->key($this->staff(['*'])), 'revenue', ['by_product' => true]));

        Carbon::setTestNow();
    }
}
