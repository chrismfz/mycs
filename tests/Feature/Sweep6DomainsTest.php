<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Domains\DomainNameHold;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Nixpal\DomainTools\Whois\SocketConnector;
use RuntimeException;
use Tests\TestCase;

/** Sweep 6, domains: adversarial verification (failing tests for what was confirmed). */
class Sweep6DomainsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([], 404));
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        $this->app->bind(SocketConnector::class, fn () => new class implements SocketConnector
        {
            public function query($host, $query, $timeout = 8, $max = 65536)
            {
                return null;
            }
        });
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.domain_nameserver_1' => 'ns1.example.test', 'settings.domain_nameserver_2' => 'ns2.example.test']);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Test-Pa55'], ['password']);
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'epp_code_required' => true]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->alice = $this->customer('alice');
        $this->bob = $this->customer('bob');
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(string $name): User
    {
        $user = User::factory()->create();
        $user->client()->ensurePrimaryContact()->update(['first_name' => ucfirst($name), 'last_name' => 'P', 'email' => "{$name}@example.test",
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);

        return $user;
    }

    /** $user orders a registration of $fqdn through the shop and the cart: its pending row, not paid. */
    private function order(User $user, string $fqdn): Domain
    {
        Once::flush();
        $this->actingAs($user);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);
        Livewire::test(Search::class)->call('add', $fqdn)->assertRedirect(route('cart'));
        $ulid = (string) \App\Models\Cart::query()->where('client_id', $user->client()->id)->latest('id')->value('ulid');
        Livewire::withCookie('cart', $ulid)->actingAs($user)->test(Cart::class)->call('checkout')->assertRedirect();

        return Domain::query()->where('fqdn', $fqdn)->where('client_id', $user->client()->id)->latest('id')->firstOrFail();
    }

    /** $client's expired row of $fqdn, past its recovery window (expired 2026-09-20; 30 default days, till 2026-10-20). */
    private function expired(Client $client, string $fqdn): Domain
    {
        return Domain::factory()->create(['client_id' => $client->id, 'sld' => explode('.', $fqdn)[0], 'tld' => 'com', 'fqdn' => $fqdn,
            'status' => DomainStatus::Expired, 'expires_at' => '2026-09-20']);
    }

    private function refusedToOrder(string $fqdn, User $user): bool
    {
        try {
            app(DomainOffer::class)->check($fqdn, 'register', 1, 'EUR', availability: false, clientId: $user->client()->id);

            return false;
        } catch (DisplayException) {
            return true;
        }
    }

    /**
     * S6-DOMAINS-1: an expired row past its window whose paid renewal staff closed «not renewed» keeps the name held: the
     * daily step never releases it, and no one (its own customer included) may order the name again.
     */
    public function test_s6_domains_1_a_renewal_closed_unrenewed_keeps_an_expired_rows_name_for_good(): void
    {
        $alices = $this->expired($this->alice->client(), 'mybusiness.com');
        $invoice = Invoice::query()->create(['client_id' => $alices->client_id, 'currency_code' => 'EUR', 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()]);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $alices->id, 'price' => '23.33', 'quantity' => 1,
            'description' => 'Renewal mybusiness.com', 'period_start' => '2026-09-20', 'period_end' => '2027-09-20']);
        (new RecordPaymentService)->handle($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'tx-renew-1');
        $this->assertSame('mybusiness.com', $alices->fresh()->live_fqdn, 'paid renewal: held (ADV-DNH-1)');

        // Its renewal failed at the registrar (the name is gone there) and staff close it «not renewed».
        $action = ModuleAction::domainFailed($alices->fresh(), $line->fresh(), 'openprovider', new RuntimeException('Domain not found'));
        $action->closeUnrenewed(null);
        $this->assertSame(ModuleAction::CLOSED_UNRENEWED, $action->fresh()->resolution);

        $this->assertSame([$alices->id], DomainNameHold::staleHolds(), 'past its window, its renewal closed: nothing entitles the hold');
        // The cron's daily domains_superseded step releases it (the fix: by that step, not at once).
        $this->assertTrue(DomainNameHold::release($alices->id));
        $this->assertNull($alices->fresh()->live_fqdn);
        $this->assertFalse($this->refusedToOrder('mybusiness.com', $this->bob), 'another customer may order the name');
        // Staff's Retry takes the name back while no one else has it.
        $this->assertTrue(DomainNameHold::claim($alices->fresh()));
    }

    /**
     * S6-DOMAINS-2: a register line paid after its domain row was cancelled (an import's «also live as …», or staff)
     * is silently dropped: the payment stands, nothing is sent, and staff get no Pending Module Action.
     */
    public function test_s6_domains_2_an_order_paid_after_its_row_was_cancelled_is_left_unseen(): void
    {
        // Alice's active row holds the name (WHMCS «Active»).
        Domain::factory()->create(['client_id' => $this->alice->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
            'status' => DomainStatus::Active, 'expires_at' => '2027-06-01']);
        // Bob's unpaid registration order of the name, as the cart makes it (a pending row, its register line open).
        $bobs = Domain::factory()->create(['client_id' => $this->bob->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
            'domain_tld_id' => DomainTld::query()->where('tld', 'com')->value('id'), 'status' => DomainStatus::PendingRegister, 'expires_at' => null]);
        $open = Invoice::query()->create(['client_id' => $bobs->client_id, 'currency_code' => 'EUR', 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()]);
        $line = $open->items()->create(['reference_type' => Domain::class, 'reference_id' => $bobs->id, 'kind' => InvoiceItem::KIND_REGISTER,
            'price' => '23.33', 'quantity' => 1, 'description' => 'Registration mybusiness.com']);
        $this->assertSame(InvoiceItem::KIND_REGISTER, $line->fresh()->kind);
        // Imported cancelled («also live as #… in WHMCS»), written by the importer past the model; its invoice still open.
        DB::table('domains')->where('id', $bobs->id)->update(['status' => DomainStatus::Cancelled->value, 'name_held_at' => null]);

        $invoice = $line->invoice->fresh();
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-late-1');
        foreach (Queue::pushed(FulfilDomainOrderJob::class) as $job) {
            app()->call([$job, 'handle']);
        }

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNull($line->fresh()->applied_at, 'nothing done for the money');
        $this->assertTrue(ModuleAction::query()->where('domain_id', $bobs->id)->exists(),
            'a paid order of a cancelled row should reach staff (to refund or credit), not be dropped silently');
        $task = ModuleAction::query()->where('domain_id', $bobs->id)->sole();
        $this->assertSame([ModuleAction::DOMAIN_REGISTER, ModuleAction::STATUS_OPEN, $line->id], [$task->action, $task->status, $task->invoice_item_id]);
        $this->assertStringContainsString('mybusiness.com', (string) $task->last_error);
        Queue::assertNotPushed(FulfilDomainOrderJob::class);
        $this->assertSame(DomainStatus::Cancelled, $bobs->fresh()->status, 'never opened again on its own');
        // One open task per line: applied again (the cron's replay), no second task.
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());
        $this->assertSame(1, ModuleAction::query()->where('domain_id', $bobs->id)->count());
    }
}
