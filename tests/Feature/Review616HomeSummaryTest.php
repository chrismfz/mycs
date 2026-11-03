<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Support\CurrentClient;
use App\Support\Portal\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Review616HomeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        $this->owner = User::factory()->create();
        $this->client = $this->owner->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function workOn(User $user): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->client->id]));
    }

    private function tiles(): Collection
    {
        $this->actingAs($this->owner);
        app(CurrentClient::class)->switchTo($this->client->id);

        return collect((new Summary($this->client))->tiles())->keyBy('key');
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + ['client_id' => $this->client->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => Service::STATUS_ACTIVE, 'price' => 10, 'quantity' => 1, 'currency_code' => 'EUR', 'domain' => null]);
    }

    /** R1: an «add funds» invoice is not a debt (Invoice::whereOverdue, OverdueInvoicesAdversarialTest ADV-1): no red «overdue». */
    public function test_r1_an_add_funds_invoice_is_not_overdue_on_the_tile(): void
    {
        $deposit = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-01']);
        $deposit->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50, 'taxed' => false]);
        $this->assertSame(0, $deposit->fresh()->daysOverdue());

        $this->assertNotSame('error', $this->tiles()['invoices']['tone'], 'an add-funds offer is not overdue');
    }

    /** R1b: a payment on its way (SEPA processing) is not overdue either (ADV-2). */
    public function test_r1b_an_invoice_with_a_payment_processing_is_not_overdue_on_the_tile(): void
    {
        $sepa = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-11']);
        $sepa->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20]);
        (new RecordPaymentService)->handle($sepa, null, 20.00, transactionId: 'sepa-r616', status: InvoiceTransactionStatus::Processing);
        $this->assertSame(0, $sepa->fresh()->daysOverdue());

        $this->assertNotSame('error', $this->tiles()['invoices']['tone'], 'its payment is on its way');
    }

    /** R2: a domain in redemption (Attention's most urgent) leaves the domains tile calm, while one that auto renews warns. */
    public function test_r2_a_recoverable_domain_warns_on_the_tile(): void
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);
        Domain::factory()->create(['client_id' => $this->client->id, 'sld' => 'lost', 'tld' => 'gr', 'fqdn' => 'lost.gr', 'domain_tld_id' => $tld->id,
            'status' => DomainStatus::Redemption, 'expires_at' => '2026-09-01', 'auto_renew' => false]);

        $this->assertSame('warning', $this->tiles()['domains']['tone'], 'a domain about to be lost');
    }

    /** R3: the unpaid invoices (with transactions, items, notes) are read once on the home page, not by Attention and Summary each. */
    public function test_r3_the_unpaid_invoices_are_read_once(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-01']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '10', 'description' => 'Hosting']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (preg_match('/from "?invoices"?.*"?status"? = \?/is', $query->sql) && !str_contains(strtolower($query->sql), 'count(')) {
                $queries[] = $query->sql;
            }
        });
        $this->workOn($this->owner)->get(route('dashboard'))->assertOk();

        $this->assertLessThanOrEqual(2, count($queries), implode("\n", $queries)); // Summary+Attention once, the widget once
    }

    /** R4: the identifier costs no query per row. */
    public function test_r4_no_query_per_row(): void
    {
        $count = function (int $services): int {
            Service::query()->delete();
            for ($i = 0; $i < $services; $i++) {
                $service = $this->service(['label' => "Box {$i}"]);
                $service->properties()->create(['key' => 'hostname', 'name' => 'hostname', 'value' => "vm{$i}.example.net"]);
            }
            $this->workOn($this->owner)->get(route('dashboard'))->assertOk(); // warm
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->workOn($this->owner)->get(route('dashboard'))->assertOk()->assertSee('vm0.example.net');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $one = $count(1);
        $eight = $count(8);

        $this->assertSame($one, $eight, "1 service: {$one} queries, 8: {$eight}");
    }

    /** R5: a member with `products` but not `manageproducts` sees no panel username on the home table (as on the service's page). */
    public function test_r5_no_username_without_manageproducts(): void
    {
        $service = $this->service(['label' => 'Radio']);
        $service->properties()->create(['key' => 'centova_username', 'name' => 'u', 'value' => 'secretuser616']);
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'permissions' => ['products'], 'granted_at' => now()]);

        $this->workOn($member)->get(route('dashboard'))->assertOk()->assertSee('Radio')->assertDontSee('secretuser616');
        $this->workOn($this->owner)->get(route('dashboard'))->assertOk()->assertSee('secretuser616');
    }
}
