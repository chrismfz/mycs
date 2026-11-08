<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Mail\SystemMail;
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
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Invoice\RecordPaymentService;
use App\Support\CurrentClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Nixpal\DomainTools\Whois\SocketConnector;
use Tests\TestCase;

/**
 * Who holds a domain name (owner 2026-11-08, docs/domains.md D-25): an active, grace or redemption row, or a pending
 * order once paid (or free, or sent). An expired row or an unpaid order does not: another customer may order the name,
 * whoever pays first holds it, a later payment is refused as a registrar's refusal, and the rows left behind are closed.
 */
class DomainNameHoldTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    /** What the registrar's availability check says of every name. */
    private string $checkSays = 'free';

    /** @var list<string> the names sent to the registrar (register or transfer) */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Cache::flush();
        Http::preventStrayRequests();
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
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'epp_code_required' => true]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->alice = $this->customer('alice');
        $this->bob = $this->customer('bob');
        Http::fake(fn (Request $request) => $this->registrar($request));
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

    /** Openprovider: every name free (checkSays); a registration answered ACT. */
    private function registrar(Request $request)
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $record = fn (string $fqdn) => ['id' => 9001, 'domain' => ['name' => explode('.', $fqdn)[0], 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2027-11-08 10:00:00', 'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']],
            'owner_handle' => 'MP000001-GR', 'admin_handle' => 'MP000001-GR', 'tech_handle' => 'MP000001-GR', 'billing_handle' => 'MP000001-GR'];

        return match (true) {
            str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                'domain' => $d['name'] . '.' . $d['extension'], 'status' => $this->checkSays], $request->data()['domains'])]]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => Http::response(['data' => ['handle' => 'MP000001-GR']]),
            $request->method() === 'POST' && str_ends_with($path, '/domains') => $this->created($request->data()),
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->sent === [] ? [] : [$record($this->sent[0])],
                'total' => $this->sent === [] ? 0 : 1] + ($this->sent === [] ? [] : $record($this->sent[0]))]),
            default => Http::response([], 404),
        };
    }

    private function created(array $data)
    {
        $this->sent[] = $data['domain']['name'] . '.' . $data['domain']['extension'];

        return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => 'ACT']]);
    }

    /** $user orders a registration of $fqdn through the shop and the cart: its pending row, not paid. */
    private function order(User $user, string $fqdn): Domain
    {
        $this->shop($user, 'add', $fqdn);

        return Domain::query()->where('fqdn', $fqdn)->where('client_id', $user->client()->id)->latest('id')->firstOrFail();
    }

    /** $user's own cart: $how ('add' or 'transfer') $fqdn, then checkout. */
    private function shop(User $user, string $how, string $fqdn, array $set = []): void
    {
        Once::flush();
        $this->actingAs($user);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);
        Livewire::test(Search::class)->call($how, $fqdn)->assertRedirect(route('cart'));
        $ulid = (string) \App\Models\Cart::query()->where('client_id', $user->client()->id)->latest('id')->value('ulid');
        $cart = Livewire::withCookie('cart', $ulid)->actingAs($user)->test(Cart::class);
        $item = \App\Models\Cart::query()->where('ulid', $ulid)->first()->items()->sole();
        foreach ($set as $key => $value) {
            $cart->set(str_replace('{item}', (string) $item->id, $key), $value);
        }
        $cart->call('checkout')->assertRedirect();
    }

    private function invoiceOf(Domain $domain): Invoice
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole()->invoice;
    }

    private function pay(Domain $domain): void
    {
        config(['settings.domain_nameserver_1' => 'ns1.nixpal.com', 'settings.domain_nameserver_2' => 'ns2.nixpal.com']);
        $invoice = $this->invoiceOf($domain);
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-' . $domain->id);
    }

    private function runJobs(): void
    {
        foreach (Queue::pushed(FulfilDomainOrderJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function expired(Client $client, string $fqdn): Domain
    {
        return Domain::factory()->create(['client_id' => $client->id, 'sld' => explode('.', $fqdn)[0], 'tld' => 'com', 'fqdn' => $fqdn,
            'status' => DomainStatus::Expired, 'expires_at' => '2026-10-20']);
    }

    /** @return list<string> the subjects of the mails to staff */
    private function staffMails(): array
    {
        return MailFacade::queued(SystemMail::class)->map(fn (SystemMail $mail) => $mail->envelope()->subject)->values()->all();
    }

    public function test_the_statuses_that_hold_a_name(): void
    {
        $holds = array_map(fn (DomainStatus $status) => [$status->value, $status->holdsName(), $status->holdsName(paid: true)], DomainStatus::cases());

        $this->assertSame([
            // Expired holds only paid: its renewal paid and waiting for its registrar (ADV-DNH-1).
            ['active', true, true], ['pending_register', false, true], ['pending_transfer', false, true], ['expired', false, true],
            ['grace', true, true], ['redemption', true, true], ['transferred_away', false, false], ['cancelled', false, false], ['deleted', false, false],
        ], $holds);
    }

    public function test_an_expired_row_or_an_unpaid_order_leaves_the_name_free_to_order(): void
    {
        $offer = app(DomainOffer::class);
        $expired = $this->expired($this->alice->client(), 'mybusiness.com');
        $this->assertNull($expired->fresh()->live_fqdn, 'expired: not held');
        $this->assertSame('mybusiness.com', $offer->check('mybusiness.com', 'register', 1, 'EUR', availability: false)[0]);

        $unpaid = $this->order($this->bob, 'mybusiness.com');
        $this->assertSame([DomainStatus::PendingRegister, null, null], [$unpaid->status, $unpaid->name_held_at, $unpaid->fresh()->live_fqdn]);
        $this->assertSame('mybusiness.com', $offer->check('mybusiness.com', 'register', 1, 'EUR', availability: false)[0], 'an unpaid order: not held');
        $this->assertSame(DomainStatus::Expired, $expired->fresh()->status, 'an order not paid closes nothing');

        $expired->forceFill(['status' => DomainStatus::Active, 'expires_at' => '2027-10-20'])->save(); // renewed after all
        $this->expectException(DisplayException::class);
        $offer->check('mybusiness.com', 'register', 1, 'EUR', availability: false);
    }

    public function test_paid_it_holds_the_name_and_the_rows_left_behind_are_closed(): void
    {
        $expired = $this->expired($this->alice->client(), 'mybusiness.com');
        $alices = $this->order($this->alice, 'mybusiness.com'); // the old owner orders it again, never pays
        $bobs = $this->order($this->bob, 'mybusiness.com');

        $this->pay($bobs);

        $bobs->refresh();
        $this->assertNotNull($bobs->name_held_at, 'paid: it holds the name');
        $this->assertSame('mybusiness.com', $bobs->live_fqdn);
        $expired->refresh();
        $this->assertSame(DomainStatus::Cancelled, $expired->status, 'the expired row closed');
        $this->assertSame(['by' => $bobs->id, 'was' => 'expired'], array_intersect_key($expired->meta['name_taken'], ['by' => 0, 'was' => 0]));
        $this->assertStringContainsString("held by domain #{$bobs->id}", (string) $expired->notes, 'a note for staff');
        $alices->refresh();
        $this->assertSame(DomainStatus::Cancelled, $alices->status, 'the unpaid order closed');
        $this->assertSame(Invoice::STATUS_CANCELLED, $this->invoiceOf($alices)->status, 'its invoice line cancelled, as staff\'s Cancel Order');
        $this->assertContains('Domain name now held: mybusiness.com', $this->staffMails());

        $this->runJobs();
        $this->assertSame(['mybusiness.com'], $this->sent, 'the payer\'s registration sent, once');
        $bobs->refresh();
        $this->assertSame([DomainStatus::Active, null], [$bobs->status, $bobs->name_held_at], 'active: held by its status');
        $this->assertSame(1, Domain::query()->where('live_fqdn', 'mybusiness.com')->count());
    }

    public function test_a_payment_after_another_paid_first_is_refused_as_a_registrar_refusal(): void
    {
        $alices = $this->order($this->alice, 'mybusiness.com');
        $bobs = $this->order($this->bob, 'mybusiness.com');
        // Alice's order busy when Bob pays (being looked at): left open, staff told.
        $lock = Cache::lock("domain-registrar:{$alices->id}", 60);
        $lock->get();
        $this->pay($bobs);
        $lock->release();
        $this->assertSame(DomainStatus::PendingRegister, $alices->fresh()->status, 'busy: not closed');

        $this->pay($alices);

        $alices->refresh();
        $this->assertNull($alices->name_held_at, 'never two rows holding one name');
        $this->assertSame(Invoice::STATUS_PAID, $this->invoiceOf($alices)->status, 'her money stays paid: staff refund or credit it');
        $action = ModuleAction::query()->where('domain_id', $alices->id)->sole();
        $this->assertSame([ModuleAction::DOMAIN_REGISTER, ModuleAction::STATUS_OPEN], [$action->action, $action->status]);
        $this->assertStringContainsString("held by another domain here (#{$bobs->id})", (string) $action->last_error);
        $this->assertContains('Domain registration needs staff: mybusiness.com', $this->staffMails());
        Queue::assertNotPushed(FulfilDomainOrderJob::class, fn (FulfilDomainOrderJob $job) => $job->domainId === $alices->id);

        $this->runJobs(); // Bob's job, and none for hers
        $this->assertSame(['mybusiness.com'], $this->sent);
        $this->assertSame(DomainOrderFulfilment::STAFF, app(DomainOrderFulfilment::class)->fulfil($alices->id), 'staff\'s Retry: refused again, never sent');
        $this->assertSame(['mybusiness.com'], $this->sent);

        // Paid and refused: staff's (its Pending Module Action), never closed by the daily sweep.
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(DomainStatus::PendingRegister, $alices->fresh()->status);
        $this->assertSame(['mybusiness.com'], $this->sent);
    }

    public function test_the_database_keeps_one_holder_whatever_writes(): void
    {
        Domain::factory()->create(['client_id' => $this->alice->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com']);
        $pending = $this->order($this->bob, 'other.com');
        DB::table('domains')->where('id', $pending->id)->update(['sld' => 'mybusiness', 'fqdn' => 'mybusiness.com']);

        $this->assertFalse(DomainNameHold::claim($pending->fresh()), 'held by the active row');
        $this->assertNull($pending->fresh()->name_held_at);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('domains')->where('id', $pending->id)->update(['name_held_at' => now()]);
    }

    public function test_a_row_that_starts_holding_closes_the_others_and_ours_keep_their_hold_while_pending(): void
    {
        $bobs = $this->order($this->bob, 'mybusiness.com');
        $alices = $this->expired($this->alice->client(), 'mybusiness.com');

        // Alice's expired name renewed at the registrar after all (grace, read by the sync): hers again, Bob's order closed.
        $alices->forceFill(['status' => DomainStatus::Grace])->save();
        $this->assertSame(DomainStatus::Cancelled, $bobs->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $this->invoiceOf($bobs)->status);

        // One of ours that becomes pending again (a renewal as a transfer) keeps the name; active again, by its status.
        $alices->forceFill(['status' => DomainStatus::PendingTransfer])->save();
        $this->assertNotNull($alices->fresh()->name_held_at);
        $this->assertSame('mybusiness.com', $alices->fresh()->live_fqdn);
        $alices->forceFill(['status' => DomainStatus::Active])->save();
        $this->assertSame([null, 'mybusiness.com'], [$alices->fresh()->name_held_at, $alices->fresh()->live_fqdn]);
    }

    public function test_the_daily_sweep_closes_what_a_write_without_the_model_left(): void
    {
        $active = Domain::factory()->create(['client_id' => $this->alice->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com']);
        DB::table('domains')->insert(['client_id' => $this->bob->client()->id, 'sld' => 'mybusiness', 'tld' => 'com', 'fqdn' => 'mybusiness.com',
            'status' => DomainStatus::Expired->value, 'currency_code' => 'EUR', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame([DomainStatus::Active->value, DomainStatus::Cancelled->value],
            Domain::query()->where('fqdn', 'mybusiness.com')->orderBy('id')->pluck('status')->map(fn ($s) => $s->value)->all());
        $this->assertSame($active->id, Domain::query()->where('fqdn', 'mybusiness.com')->where('status', 'cancelled')->sole()->meta['name_taken']['by']);
    }

    public function test_a_free_transfer_holds_its_name_once_ordered(): void
    {
        DomainTldPrice::query()->where('operation', 'transfer')->update(['price' => '0.00']);
        $this->expired($this->alice->client(), 'mybusiness.com');
        $this->checkSays = 'active'; // registered (with Alice's old registrar, or ours: still taken)

        $this->shop($this->bob, 'transfer', 'mybusiness.com', ['epp.{item}' => 'Epp#Code-77']);

        $bobs = Domain::query()->where('client_id', $this->bob->client()->id)->sole();
        $this->assertSame([DomainStatus::PendingTransfer, 'mybusiness.com'], [$bobs->status, $bobs->live_fqdn], 'free: held as paid');
        $this->assertSame(DomainStatus::Cancelled, Domain::query()->where('client_id', $this->alice->client()->id)->sole()->status);
    }

    /** A renewal of $domain on its own invoice, paid now (its job queued, not run: the line waits for the registrar). */
    private function paidRenewal(Domain $domain): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR', 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()]);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => "Renewal {$domain->fqdn}", 'period_start' => '2026-10-20', 'period_end' => '2027-10-20']);
        (new RecordPaymentService)->handle($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'tx-renew-' . $domain->id);

        return $line->fresh();
    }

    /** ADV-DNH-1: an expired row whose renewal is paid holds its name (whoever pays first); refunded, it holds it no more. */
    public function test_an_expired_row_holds_its_name_while_its_renewal_is_paid(): void
    {
        $bobs = $this->order($this->bob, 'mybusiness.com');
        $alices = $this->expired($this->alice->client(), 'mybusiness.com');
        $this->assertNull($alices->live_fqdn);

        $line = $this->paidRenewal($alices);

        $this->assertSame('mybusiness.com', $alices->fresh()->live_fqdn, 'her renewal paid first: hers');
        $this->assertSame(DomainStatus::Cancelled, $bobs->fresh()->status, 'Bob\'s unpaid order closed');
        // The cron moving it on keeps the hold; a stage it leaves into expired keeps it too while the renewal waits.
        $alices->forceFill(['status' => DomainStatus::Redemption])->save();
        $alices->forceFill(['status' => DomainStatus::Expired])->save();
        $this->assertSame('mybusiness.com', $alices->fresh()->live_fqdn);

        $payment = $line->invoice->transactions()->sole();
        ExtensionHelper::refundLocally($payment, (float) $payment->amount, notify: false);
        $this->assertSame([null, null], [$alices->fresh()->name_held_at, $alices->fresh()->live_fqdn], 'refunded: the name is free again');
        app(DomainOffer::class)->check('mybusiness.com', 'register', 1, 'EUR', availability: false, clientId: $this->bob->client()->id);
    }

    /**
     * ADV-DNH-1 (the race): Bob paid first while Alice's expired row was busy (left open); her renewal paid after him is
     * never charged at the registrar (her row could not be active again): staff's, and nothing throws.
     */
    public function test_a_renewal_paid_after_another_holds_the_name_is_never_charged(): void
    {
        $alices = $this->expired($this->alice->client(), 'mybusiness.com');
        $bobs = $this->order($this->bob, 'mybusiness.com');
        $lock = Cache::lock("domain-registrar:{$alices->id}", 60);
        $lock->get();
        $this->pay($bobs);
        $lock->release();
        $this->assertSame(DomainStatus::Expired, $alices->fresh()->status, 'busy: left');

        $line = $this->paidRenewal($alices);
        $this->assertNull($alices->fresh()->name_held_at, 'Bob holds it');
        $requests = 0;
        Http::fake(function (Request $request) use (&$requests) {
            $requests++;

            return $this->registrar($request);
        });

        $this->assertSame(DomainRenewalService::WAITING, app(DomainRenewalService::class)->renewLine($line->id));

        $this->assertSame(0, $requests, 'never at the registrar');
        $this->assertNull($line->fresh()->applied_at);
        $this->assertSame(DomainStatus::Expired, $alices->fresh()->status);
        $this->assertStringContainsString("held by domain #{$bobs->id}", (string) ModuleAction::query()->where('domain_id', $alices->id)->sole()->last_error);
        $this->assertSame($bobs->id, DomainNameHold::holder('mybusiness.com')?->id);
    }

    /** ADV-DNH-4: a refund written past the model (no hook) is released by the daily sweep: the name is free again. */
    public function test_the_daily_sweep_releases_a_hold_whose_payment_no_longer_stands(): void
    {
        $sent = $this->order($this->alice, 'other.com');
        $this->pay($sent);
        $this->runJobs(); // other.com sent: it keeps its hold whatever its invoice says
        $bobs = $this->order($this->bob, 'mybusiness.com');
        $this->pay($bobs); // its job queued, not run: not sent
        DB::table('invoices')->whereIn('id', [$this->invoiceOf($bobs)->id, $this->invoiceOf($sent)->id])->update(['status' => Invoice::STATUS_REFUNDED]);
        $this->assertSame([$bobs->id], DomainNameHold::staleHolds());

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame([DomainStatus::PendingRegister, null, null], [$bobs->fresh()->status, $bobs->fresh()->name_held_at, $bobs->fresh()->live_fqdn]);
        $this->assertNotNull($sent->fresh()->live_fqdn);
        $this->assertSame([], DomainNameHold::staleHolds());
    }

    /** ADV-DNH-3: one open order per account and name, in the cart and at checkout; another account may still order it. */
    public function test_an_account_orders_a_name_once_while_its_order_is_open(): void
    {
        $this->order($this->alice, 'mybusiness.com');
        $offer = app(DomainOffer::class);

        try {
            $offer->check('mybusiness.com', 'register', 1, 'EUR', availability: false, clientId: $this->alice->client()->id);
            $this->fail('ordered twice');
        } catch (DisplayException $e) {
            $this->assertSame(__('domains.order.already_ordered', ['domain' => 'mybusiness.com']), $e->getMessage());
        }
        $this->assertSame('mybusiness.com', $offer->check('mybusiness.com', 'register', 1, 'EUR', availability: false, clientId: $this->bob->client()->id)[0]);
    }
}
