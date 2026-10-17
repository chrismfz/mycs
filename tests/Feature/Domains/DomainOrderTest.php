<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\RenewDomainJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D8b-1: ordering domains. From the search («Add», «Transfer it to us») to the cart (years per TLD and operation, a
 * transfer's EPP code) and checkout: a pending domain of the order owned by the contact chosen, and its line (register or
 * transfer, never a renewal) at the catalog price net of VAT. Checked again at checkout; an unpaid order is cancelled
 * after its days, the name free again.
 */
class DomainOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $com;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.cronjob_order_cancel' => 7, 'settings.domain_billing_enabled' => true]);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'epp_code_required' => true]);
        foreach ([['register', 1, '23.33'], ['register', 2, '46.66'], ['renew', 1, '23.33'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->user = User::factory()->create();
        $this->user->client()->ensurePrimaryContact()->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
        // Openprovider: nixpal.com is taken, every other name free.
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                    'domain' => $d['name'] . '.' . $d['extension'],
                    'status' => $d['name'] . '.' . $d['extension'] === 'nixpal.com' ? 'active' : 'free',
                ], $request->data()['domains'])]]),
                default => Http::response([], 404),
            };
        });
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkout(array $set = []): Testable
    {
        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        $cart = Livewire::withCookie('cart', (string) \App\Models\Cart::query()->latest('id')->value('ulid'))->actingAs($this->user)->test(Cart::class);
        foreach ($set as $key => $value) {
            $cart->set($key, $value);
        }

        return $cart->call('checkout');
    }

    public function test_a_free_name_goes_from_the_search_to_an_order_with_its_registration_line(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com')->assertRedirect(route('cart'));
        $item = \App\Models\Cart::query()->sole()->items()->sole();
        $this->assertSame(['mybusiness.com', 'register', 1], [$item->domain['fqdn'], $item->domain['operation'], $item->domain['years']]);

        $this->checkout()->assertRedirect();

        $domain = Domain::query()->where('fqdn', 'mybusiness.com')->sole();
        $this->assertSame([DomainStatus::PendingRegister, 1, (int) $this->user->client()->primaryContact->id],
            [$domain->status, $domain->years, (int) $domain->registrant_contact_id]);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole();
        $this->assertSame([InvoiceItem::KIND_REGISTER, '23.33', null], [$line->kind, $line->price, $line->period_start]);
        $this->assertSame($domain->order_id, Order::query()->sole()->id);
        $this->assertSame(Order::STATUS_PENDING, Order::query()->sole()->status);
    }

    public function test_a_transfer_keeps_its_epp_code_for_staff_encrypted_at_rest(): void
    {
        Livewire::test(Search::class)->call('transfer', 'nixpal.com')->assertRedirect(route('cart'));
        $item = \App\Models\Cart::query()->sole()->items()->sole();

        // Required for .com: refused without it.
        $this->checkout()->assertNoRedirect();
        $this->assertSame(0, Domain::query()->count());

        $this->checkout(['epp.' . $item->id => ' Epp#Code-77 '])->assertRedirect();

        $domain = Domain::query()->where('fqdn', 'nixpal.com')->sole();
        $this->assertSame([DomainStatus::PendingTransfer, 'Epp#Code-77'], [$domain->status, $domain->epp_code]);
        $this->assertStringNotContainsString('Epp#Code-77', (string) DB::table('domains')->where('id', $domain->id)->value('epp_code'));
        $this->assertStringNotContainsString('Epp#Code-77', json_encode($domain) . json_encode(DB::table('audits')->get()));
        $this->assertSame(InvoiceItem::KIND_TRANSFER, InvoiceItem::query()->where('reference_id', $domain->id)->value('kind'));
    }

    public function test_what_cannot_be_ordered_is_refused(): void
    {
        Livewire::test(Search::class)->call('add', 'nixpal.com')->assertNoRedirect(); // taken
        Livewire::test(Search::class)->call('transfer', 'brandnew.com')->assertNoRedirect(); // not registered
        Livewire::test(Search::class)->call('add', 'nixpal.xyz')->assertNoRedirect(); // not sold

        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $item = \App\Models\Cart::query()->sole()->items()->sole();
        $cart = fn () => Livewire::withCookie('cart', (string) \App\Models\Cart::query()->sole()->ulid)->test(Cart::class);
        $cart()->call('updateDomainYears', $item->id, 5); // not priced for 5 years
        $this->assertSame(1, $item->fresh()->domain['years']);
        $cart()->call('updateDomainYears', $item->id, 2);
        $this->assertSame(2, $item->fresh()->domain['years']);
    }

    public function test_the_registrant_must_be_complete_and_the_accounts_own(): void
    {
        $incomplete = Contact::factory()->create(['client_id' => $this->user->client()->id, 'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.test', 'country' => null]);
        $other = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'country' => 'GR']);
        Livewire::test(Search::class)->call('add', 'mybusiness.com');

        $this->checkout(['registrant' => $incomplete->id])->assertNoRedirect();
        $this->checkout(['registrant' => $other->id])->assertNoRedirect();
        $this->assertSame(0, Domain::query()->count());
    }

    public function test_paid_it_is_never_taken_for_a_renewal(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $this->checkout();
        $invoice = Invoice::query()->sole();

        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-1');

        Queue::assertNotPushed(RenewDomainJob::class);
        $this->assertSame(0, DomainRenewalService::waitingLines()->count());
        $domain = Domain::query()->sole();
        $this->assertNull(DomainRenewalService::billedLine($domain, Carbon::parse('2027-10-20')));
    }

    public function test_an_unpaid_order_is_cancelled_after_its_days_and_the_name_is_free_again(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $this->checkout();

        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $domain = Domain::query()->sole();
        $this->assertSame([DomainStatus::Cancelled, Invoice::STATUS_CANCELLED, Order::STATUS_CANCELLED],
            [$domain->status, Invoice::query()->sole()->status, Order::query()->sole()->status]);
        Livewire::test(Search::class)->call('add', 'mybusiness.com')->assertRedirect(route('cart')); // free to order again
    }

    public function test_staff_cancelling_the_order_cancel_its_domain_and_its_line(): void
    {
        // Code review H2: Cancel, Cancel & Refund and Fraud end the order's pending domains too.
        Livewire::test(Search::class)->call('add', 'mybusiness.com');
        $this->checkout();
        $order = Order::query()->sole();

        $left = CancelOrder::handle($order, fraud: true);

        $this->assertSame([], $left);
        $this->assertSame([DomainStatus::Cancelled, Invoice::STATUS_CANCELLED, Order::STATUS_FRAUD],
            [Domain::query()->sole()->status, Invoice::query()->sole()->status, $order->fresh()->status]);
        $this->expectException(DisplayException::class);
        $order->fresh()->delete(); // an order with domains is cancelled, not deleted (review L1)
    }
}
