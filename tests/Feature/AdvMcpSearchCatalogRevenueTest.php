<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\License;
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
 * Adversarial review of #857 (MCP `search`, `catalog`, `revenue`, wider `client`). Each test fails on the PR as it stands.
 */
class AdvMcpSearchCatalogRevenueTest extends TestCase
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

    private function licensed(): Service
    {
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => Product::factory()->create(['name' => 'ZC Agent'])->id, 'status' => Service::STATUS_ACTIVE,
            'domain' => 'ZCAgent-0123456789abcdef']);
        License::query()->create(['service_id' => $service->id, 'license_key' => 'ZCAgent-0123456789abcdef', 'status' => License::STATUS_ACTIVE]);

        return $service;
    }

    /**
     * ADV-857-1. A license's key is its service's domain. The admin lets staff who see keys masked (no
     * admin.services.update, SA-11) find such a service by the WHOLE key only (LicenseKeyMask::whereDomainLike, review
     * of #368): a partial match says «the key holds X», and the hidden middle comes back a character at a time.
     * `search` matches services.domain by any 3-character substring, in `services` and in `clients`.
     */
    public function test_search_services_does_not_answer_a_partial_license_key_to_staff_who_see_keys_masked(): void
    {
        $service = $this->licensed();
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.clients.viewAny', 'admin.services.viewAny']));

        $this->assertSame([$service->id], array_column($this->ask($key, 'search', ['query' => 'ZCAgent-0123456789abcdef', 'in' => 'services'])['services'], 'id'), 'the whole key still finds it');
        $this->assertSame([], $this->ask($key, 'search', ['query' => 'ZCAgent-01234', 'in' => 'services'])['services'], 'the shown prefix plus a guessed digit');
        $this->assertSame([], $this->ask($key, 'search', ['query' => '6789a', 'in' => 'services'])['services'], 'a guessed middle');
    }

    /** ADV-857-1 too: `clients` answers the same oracle through the account, with only admin.clients.viewAny. */
    public function test_search_clients_does_not_answer_a_partial_license_key_either(): void
    {
        $this->licensed();
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.clients.viewAny']));

        $this->assertSame([], $this->ask($key, 'search', ['query' => 'ZCAgent-01234', 'in' => 'clients'])['clients']);
        $this->assertSame([], $this->ask($key, 'search', ['query' => '6789a', 'in' => 'clients'])['clients']);
    }

    /**
     * ADV-857-2. `matched_text` is cut to 300 characters BEFORE it is scrubbed: a secret across the cut is no longer the
     * whole value Scrub knows, and its first part goes out as is (scrub first, then cut).
     */
    public function test_a_secret_across_the_300_character_cut_of_matched_text_is_not_shown_in_part(): void
    {
        config(['settings.mail_password' => 'Zq7MailPassw0rdLong!']);
        $ticket = Ticket::factory()->create(['client_id' => User::factory()->create()->client()->id, 'subject' => 'Settings']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'is_internal' => false,
            'message' => 'needle ' . str_repeat('x', 300 - 7 - 10) . 'Zq7MailPassw0rdLong! is what you gave me']);

        $found = $this->ask($this->key($this->staff(['*'])), 'search', ['query' => 'needle', 'in' => 'tickets'])['tickets'];

        $this->assertSame([$ticket->id], array_column($found, 'id'));
        $this->assertStringNotContainsString('Zq7MailPas', (string) $found[0]['matched_text']);
    }

    /**
     * ADV-857-3. The admin's search finds a service by any of its IPs (ServiceResource: dedicated_ip AND assigned_ips:
     * «who has IP x», abuse). `search` looks at dedicated_ip only, so an extra IP finds nothing.
     */
    public function test_search_finds_a_service_by_an_assigned_ip_as_the_admin_does(): void
    {
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => Product::factory()->create()->id, 'status' => Service::STATUS_ACTIVE, 'domain' => 'vps.example.test',
            'dedicated_ip' => '198.51.100.10']);
        DB::table('services')->where('id', $service->id)->update(['assigned_ips' => "198.51.100.11\n198.51.100.12"]);

        $found = $this->ask($this->key($this->staff(['*'])), 'search', ['query' => '198.51.100.12']);

        $this->assertSame([$service->id], array_column($found['services'], 'id'));
        $this->assertSame([$service->client_id], array_column($found['clients'], 'id'));
    }

    /**
     * ADV-857-5. Leap year: on 2028-02-28 the end of «the same days a year earlier» is 2028-02-29 (tomorrow) less a year,
     * which subYearNoOverflow makes 2027-02-28 00:00: the day itself a year earlier (2027-02-28) is left out of both
     * running_month and year_to_date. (Today's start less a year, plus a day, would be 2027-03-01.)
     */
    public function test_the_same_days_a_year_earlier_include_that_day_on_the_28th_of_february_of_a_leap_year(): void
    {
        Carbon::setTestNow('2028-02-28 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'x', 'quantity' => 1, 'price' => '100.00', 'tax_rate' => '24']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2027-02-28 09:00:00']);

        $data = $this->ask($this->key($this->staff([McpAuth::PERMISSION, 'admin.reports.view'])), 'revenue');

        $this->assertSame('100.00', $data['running_month']['same_days_last_year']);
        $this->assertSame('100.00', $data['year_to_date']['same_days_last_year']);
        Carbon::setTestNow();
    }

    /**
     * ADV-857-4 (performance). `search` services eager-loads `product` and `license` but ServiceIdentifier reads
     * `product.server` and `properties` too (ServiceIdentifier::RELATIONS): one or two queries more per row.
     */
    public function test_search_services_does_not_query_once_per_row(): void
    {
        $product = Product::factory()->create(['name' => 'Hosting']);
        $client = User::factory()->create()->client();
        $count = function () {
            $key = $this->key($this->staff(['*']));
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ask($key, 'search', ['query' => 'needle', 'in' => 'services']);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        Service::factory()->create(['client_id' => $client->id, 'product_id' => Product::factory()->create()->id, 'domain' => null, 'label' => 'needle 0']);
        $one = $count();
        foreach (range(1, 10) as $i) {
            Service::factory()->create(['client_id' => $client->id, 'product_id' => Product::factory()->create()->id, 'domain' => null, 'label' => "needle {$i}"]);
        }
        $eleven = $count();

        $this->assertLessThanOrEqual($one + 3, $eleven, "{$one} queries for one row, {$eleven} for eleven");
    }
}
