<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\Portal\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The home page's counts and services table (docs/ROADMAP.md, home PR 2; owner 2026-11-03: as WHMCS's, compact):
 * services, domains, tickets and unpaid invoices, each a link, and the services that run with what tells each apart.
 * Each only to who may see its area.
 */
class HomeSummaryTest extends TestCase
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

    private function service(string $status = Service::STATUS_ACTIVE, array $attributes = [], ?Client $client = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + ['client_id' => ($client ?? $this->client)->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => $status, 'price' => 10, 'quantity' => 1, 'currency_code' => 'EUR', 'domain' => null]);
    }

    private function domain(string $fqdn, string $expires, DomainStatus $status = DomainStatus::Active): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $this->client->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id,
            'status' => $status, 'expires_at' => $expires, 'auto_renew' => true]);
    }

    private function invoice(string $due, string $price = '10'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => $due]);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => $price, 'description' => 'Hosting']);

        return $invoice;
    }

    private function ticket(string $status): Ticket
    {
        return Ticket::factory()->create(['client_id' => $this->client->id, 'status' => $status, 'subject' => 'T ' . $status]);
    }

    public function test_the_tiles_count_the_account_and_say_what_waits(): void
    {
        $this->service();
        $this->service();
        $this->service(Service::STATUS_SUSPENDED);
        $this->service(Service::STATUS_CANCELLED);
        $this->domain('kept.gr', '2027-06-01');
        $this->domain('soon.gr', '2026-11-05');
        $this->domain('gone.gr', '2026-01-01', DomainStatus::Cancelled);
        $this->ticket(Ticket::STATUS_OPEN);
        $this->ticket(Ticket::STATUS_ANSWERED);
        $this->ticket(Ticket::STATUS_CLOSED);
        $this->invoice('2026-10-01', '25');
        $this->invoice('2026-11-12', '114');

        $this->actingAs($this->owner);
        app(CurrentClient::class)->switchTo($this->client->id);
        $tiles = collect((new Summary($this->client))->tiles())->keyBy('key');

        $this->assertSame(['services', 'domains', 'tickets', 'invoices'], $tiles->keys()->all());
        $this->assertSame([2, 2, 2, 2], $tiles->pluck('count')->all());
        $this->assertSame(trans_choice('dashboard.summary.services_suspended', 1, ['count' => 1]), $tiles['services']['detail']);
        $this->assertSame(trans_choice('dashboard.summary.domains_ending', 1, ['count' => 1, 'days' => 30]), $tiles['domains']['detail']);
        $this->assertSame(trans_choice('dashboard.summary.tickets_yours', 1, ['count' => 1]), $tiles['tickets']['detail']);
        $this->assertStringContainsString('139,00', $tiles['invoices']['detail']);
        $this->assertSame('error', $tiles['invoices']['tone'], 'one is overdue');
        $this->assertSame(route('invoices', ['status' => 'unpaid']), $tiles['invoices']['url']);

        $this->workOn($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee(__('dashboard.summary.title'))
            ->assertSeeInOrder([trans_choice('dashboard.summary.services', 2), trans_choice('dashboard.summary.domains', 2),
                trans_choice('dashboard.summary.tickets', 2), trans_choice('dashboard.summary.invoices', 2)]);
    }

    public function test_a_member_sees_only_the_tiles_and_the_services_of_their_grant(): void
    {
        $service = $this->service(Service::STATUS_ACTIVE, ['domain' => 'shop.example.gr']);
        $this->invoice('2026-10-01');
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'permissions' => ['domains'], 'granted_at' => now()]);

        $this->workOn($member)->get(route('dashboard'))->assertOk()
            ->assertSee(trans_choice('dashboard.summary.domains', 0))
            ->assertDontSee(trans_choice('dashboard.summary.services', 1))->assertDontSee(trans_choice('dashboard.summary.invoices', 1))
            ->assertDontSee('shop.example.gr')->assertDontSee($service->label);
    }

    public function test_no_tickets_tile_when_tickets_are_off(): void
    {
        config(['settings.tickets_disabled' => true]);
        $this->storeSettings(['tickets_disabled' => true]);
        $this->actingAs($this->owner);

        $this->assertNotContains('tickets', array_column((new Summary($this->client))->tiles(), 'key'));
    }

    public function test_the_services_table_says_what_tells_each_apart_active_first(): void
    {
        $suspended = $this->service(Service::STATUS_SUSPENDED, ['domain' => 'radio.example.gr']);
        $hosting = $this->service(Service::STATUS_ACTIVE, ['domain' => 'shop.example.gr']);
        $vps = $this->service(Service::STATUS_ACTIVE, ['domain' => 'vps1.example.gr', 'dedicated_ip' => '203.0.113.10']);
        $cancelled = $this->service(Service::STATUS_CANCELLED, ['domain' => 'old.example.gr']);
        $pending = $this->service(Service::STATUS_PENDING, ['domain' => 'new.example.gr']);
        $theirs = $this->service(Service::STATUS_ACTIVE, ['domain' => 'theirs.example.gr'], Client::factory()->ownedBy()->create());

        $this->workOn($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder([$vps->label, 'vps1.example.gr · 203.0.113.10', $hosting->label, 'shop.example.gr', $suspended->label, 'radio.example.gr'])
            ->assertSee(__('dashboard.summary.all_services', ['count' => 3]))
            ->assertSee(route('services.show', $hosting), false)
            ->assertDontSee('old.example.gr')->assertDontSee('new.example.gr')->assertDontSee('theirs.example.gr');
        $this->assertNotNull($cancelled);
        $this->assertNotNull($pending);
        $this->assertNotNull($theirs);
    }

    public function test_the_table_shows_a_few_and_says_how_many_more(): void
    {
        for ($i = 0; $i < Summary::SERVICES + 2; $i++) {
            $this->service(Service::STATUS_ACTIVE, ['domain' => "s{$i}.example.gr"]);
        }

        $this->workOn($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee(trans_choice('dashboard.summary.more_services', 2, ['count' => 2]))
            ->assertSee('s9.example.gr')->assertDontSee('s0.example.gr');
    }

    public function test_greek_words(): void
    {
        $this->service();
        app()->setLocale('el');
        $this->actingAs($this->owner);

        $tiles = (new Summary($this->client))->tiles();
        $this->assertSame('Ενεργή υπηρεσία', $tiles[0]['label']);
        $this->assertSame('Ανοιχτά αιτήματα', collect($tiles)->firstWhere('key', 'tickets')['label']);
    }
}
