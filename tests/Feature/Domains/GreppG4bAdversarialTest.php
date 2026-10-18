<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Classes\Cart as ClassesCart;
use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Livewire\Cart;
use App\Livewire\Domains\Show;
use App\Livewire\DomainShop\Search;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainRegistrarRegistry;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\CurrentClient;
use App\Support\Domains\DomainRegistrarCapabilities;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Nixpal\DomainTools\Whois\SocketConnector;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Adversarial review of #191 (G4b of grepp): the period shown vs the period billed, «restricted» names, DACoR, and
 * transfers that keep their nameservers. Failing tests mark real defects; the rest are regressions for what held.
 */
class GreppG4bAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    private DomainRegistrarConnection $op;

    private DomainTld $tld;

    private User $user;

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
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
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true]);
        session(['currency' => 'EUR']);
        // The .gr registry's driver as from G5 (registers and transfers): before G5, .gr is not sold at all (review 1 of #191).
        config(['domains.registrars.grepp' => AdvSellingGrEppRegistrar::class]);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        // As the PR's own test prices .gr: even years only.
        foreach (['register' => [2 => '24.00', 4 => '46.00'], 'renew' => [2 => '24.00'], 'transfer' => [2 => '0.00']] as $operation => $prices) {
            foreach ($prices as $years => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
            }
        }
        $this->user = User::factory()->create();
        $this->user->client()->ensurePrimaryContact()->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        LaravelTransport::$resolve = null;
        parent::tearDown();
    }

    /** hello, login, then the registry's answer to one command. */
    private function answers(string $fixture): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $body = (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/{$fixture}"));
        $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example1', 'tld' => 'gr',
            'fqdn' => 'example1.gr', 'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active,
            'expires_at' => '2028-08-16', 'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true]);
    }

    private function price(DomainTld $tld, string $operation, int $years, string $price, string $currency = 'EUR'): void
    {
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => $currency, 'price' => $price]);
    }

    private function cached(string $fqdn, string $status): void
    {
        Cache::put('domain-available:' . sha1($fqdn), $status, 300);
    }

    private function member(array $permissions): User
    {
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => $permissions]);

        return $member;
    }

    /** «Login as client» as the browser has it (the pattern of DomainPortalAdversarialTest F1). */
    private function asStaffInLoginAsClient(Staff $staff, Domain $domain): Show
    {
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Auth::guard('staff')->setUser($staff);
        Auth::guard('web')->setUser($this->user);
        $component = app(Show::class);
        $component->domain = $domain;
        $component->mount();

        return $component;
    }

    // ---- the period shown vs the period billed -------------------------------------------------------------------

    /** A1 (held): search shows 24,00 / 2 years; the cart, the order, the domain and the invoice line are the same. */
    public function test_a1_gr_shown_for_two_years_is_carted_ordered_and_billed_for_two_years(): void
    {
        $this->answers('live/domain-check-response.xml');
        Livewire::test(Search::class)->set('query', 'free-example.gr')->call('search')
            ->assertSee(trans_choice('domains.shop.per_years', 2, ['price' => '24,00EUR', 'years' => 2]))
            ->call('add', 'free-example.gr')->assertRedirect(route('cart'));
        $item = \App\Models\Cart::query()->sole()->items()->sole();
        $this->assertSame(['free-example.gr', 'register', 2], [$item->domain['fqdn'], $item->domain['operation'], $item->domain['years']]);

        // Checkout: the status is the cached «free» (no second registry call).
        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Livewire::withCookie('cart', (string) $item->cart->ulid)->actingAs($this->user)->test(Cart::class)->call('checkout')->assertRedirect();

        $domain = Domain::query()->where('fqdn', 'free-example.gr')->sole();
        $this->assertSame([DomainStatus::PendingRegister, 2], [$domain->status, (int) $domain->years]);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole();
        $this->assertSame([InvoiceItem::KIND_REGISTER, '24.00'], [$line->kind, $line->price]);
    }

    /** A2 (held): the shortest period is per currency: USD priced for one year is shown and carted for one. */
    public function test_a2_a_currency_with_another_shortest_period(): void
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->price($this->tld, 'register', 1, '15.00', 'USD');
        $this->price($this->tld, 'register', 2, '28.00', 'USD');
        $this->cached('free-example.gr', DomainSearch::FREE);

        $usd = collect(app(DomainSearch::class)->search('free-example.gr', 'USD'))->firstWhere('fqdn', 'free-example.gr');
        $eur = collect(app(DomainSearch::class)->search('free-example.gr', 'EUR'))->firstWhere('fqdn', 'free-example.gr');
        $this->assertSame([1, '15.00'], [$usd['years'], $usd['price']]);
        $this->assertSame([2, '24.00'], [$eur['years'], $eur['price']]);

        session(['currency' => 'USD']);
        ClassesCart::addDomain('free-example.gr', 'register');
        $this->assertSame(1, \App\Models\Cart::query()->sole()->items()->sole()->domain['years']);
    }

    /** A3 (held): a TLD priced only for four years is shown and carted for four, at that price. */
    public function test_a3_a_tld_with_only_a_four_year_price(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'com.gr', 'is_active' => true, 'sort' => 2, 'registrar_connection_id' => $this->gr->id]);
        $this->price($tld, 'register', 4, '50.00');
        $this->price($tld, 'renew', 4, '60.00');
        $this->cached('shop.com.gr', DomainSearch::FREE);

        $row = collect(app(DomainSearch::class)->search('shop.com.gr', 'EUR'))->firstWhere('fqdn', 'shop.com.gr');
        $this->assertSame([4, '50.00', '60.00'], [$row['years'], $row['price'], $row['renew']]);
        ClassesCart::addDomain('shop.com.gr', 'register');
        $this->assertSame(4, \App\Models\Cart::query()->sole()->items()->sole()->domain['years']);
    }

    /**
     * A4 (low, display): the search shows «€24,00 / 2 years» for .gr, yet the note under the results still says «Prices
     * per year, as listed».
     */
    public function test_a4_the_note_under_a_two_year_result_does_not_say_prices_per_year(): void
    {
        $this->cached('free-example.gr', DomainSearch::FREE);
        Livewire::test(Search::class)->set('query', 'free-example.gr')->call('search')
            ->assertSee(trans_choice('domains.shop.per_years', 2, ['price' => '24,00EUR', 'years' => 2]))
            ->assertDontSee('Prices per year')->assertSee(__('domains.shop.prices_note')); // reworded: «for the period shown»
    }

    // ---- restricted names ----------------------------------------------------------------------------------------

    /** A5 (held): a restricted name (from the registry, or cached) never reaches the cart, to register or to transfer. */
    public function test_a5_a_restricted_name_never_reaches_the_cart(): void
    {
        $this->answers('live/domain-check-response.xml');
        $statuses = app(DomainSearch::class)->statuses(['athina.gr' => $this->tld]);
        $this->assertSame(DomainSearch::RESTRICTED, $statuses['athina.gr']);
        // Now cached: refused from the cache, nothing asked again.
        $sent = count($this->transport->sent);
        foreach (['register', 'transfer'] as $operation) {
            try {
                ClassesCart::addDomain('athina.gr', $operation);
                $refused = false;
            } catch (DisplayException) {
                $refused = true;
            }
            $this->assertTrue($refused, "a restricted name is not added to {$operation}");
        }
        $this->assertSame($sent, count($this->transport->sent));
        $this->assertSame(0, \App\Models\Cart::query()->count());

        // Never with an «Add» or «Transfer» button either.
        $this->cached('athina.gr', DomainSearch::RESTRICTED);
        Livewire::test(Search::class)->set('query', 'athina.gr')->call('search')
            ->assertSee(__('domains.shop.restricted'))->assertDontSee("add('athina.gr')", false)->assertDontSee("transfer('athina.gr')", false);
    }

    /** A6 (held): a restricted name already in the cart (it was free when added) is refused at checkout. */
    public function test_a6_a_name_restricted_since_it_was_carted_is_refused_at_checkout(): void
    {
        $this->cached('athina.gr', DomainSearch::FREE);
        ClassesCart::addDomain('athina.gr', 'register');
        $this->cached('athina.gr', DomainSearch::RESTRICTED);

        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Livewire::withCookie('cart', (string) \App\Models\Cart::query()->sole()->ulid)->actingAs($this->user)->test(Cart::class)
            ->call('checkout')->assertNoRedirect();
        $this->assertSame(0, Domain::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    /**
     * A7 (low): a restricted name is refused with «athina.gr is taken» — the very wording the PR says a restricted name
     * must never get («never as taken»). It should say it needs the regulator's approval (domains.shop.restricted).
     */
    public function test_a7_a_restricted_name_is_refused_as_restricted_not_as_taken(): void
    {
        $this->cached('athina.gr', DomainSearch::RESTRICTED);
        $message = null;
        try {
            ClassesCart::addDomain('athina.gr', 'register');
        } catch (DisplayException $e) {
            $message = $e->getMessage();
        }
        $this->assertNotSame(__('domains.order.taken', ['domain' => 'athina.gr']), $message);
        $this->assertSame(__('domains.shop.restricted'), $message);
    }

    // ---- transfers -----------------------------------------------------------------------------------------------

    /**
     * A8 (medium): a taken .gr name gets «Transfer it to us» in the search, but with .gr priced in its even periods (as
     * the PR's own test prices it: transfer for 2 years) the cart refuses it (DomainOffer::years keeps a transfer's
     * 1-year price only). G4b sells .gr for its shortest period but not its transfer: the button always fails.
     */
    public function test_a8_a_taken_gr_name_offered_for_transfer_can_be_carted(): void
    {
        // A transfer is sold for one year only (it adds no period): priced for 2 years only, no «Transfer it to us» and
        // the cart refuses; priced «1 year, 0.00» (as .gr should be), offered and carted.
        $this->cached('example1.gr', DomainSearch::TAKEN);
        Livewire::test(Search::class)->set('query', 'example1.gr')->call('search')->assertDontSee("transfer('example1.gr')", false);

        DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => 'transfer', 'years' => 1, 'currency_code' => 'EUR', 'price' => '0.00']);
        Livewire::test(Search::class)->set('query', 'example1.gr')->call('search')->assertSee("transfer('example1.gr')", false);
        $error = null;
        try {
            ClassesCart::addDomain('example1.gr', 'transfer');
        } catch (DisplayException $e) {
            $error = $e->getMessage();
        }
        $this->assertNull($error, 'the transfer the search offers is refused: ' . $error);
    }

    /** A9 (held): an Openprovider transfer still needs nameservers (unreadable: staff), even next to a .gr connection. */
    public function test_a9_an_openprovider_transfer_still_needs_its_nameservers(): void
    {
        Http::fake(fn (Request $request) => Http::response([], 404));
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 3, 'registrar_connection_id' => $this->op->id]);
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        $domain = $this->domain(['sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com', 'domain_tld_id' => $com->id,
            'registrar_connection_id' => $this->op->id, 'status' => DomainStatus::PendingTransfer, 'expires_at' => null,
            'order_id' => $order->id, 'epp_code' => 'Code-1', 'years' => 1, 'meta' => ['free_order' => true]]);

        $this->assertSame(DomainOrderFulfilment::STAFF, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertStringContainsString(__('domains.order.ns_unknown', ['domain' => 'nixpal.com']), (string) ModuleAction::query()->sole()->last_error);
        $this->assertSame(0, DomainRegistrarLog::query()->count(), 'nothing sent');
    }

    /** A10 (held): the registrar lookup throwing is «needs nameservers» (then staff), never a crash nor a send. */
    public function test_a10_a_transfer_whose_registrar_lookup_throws_goes_to_staff(): void
    {
        Http::fake(fn (Request $request) => Http::response([], 404));
        $this->app->instance(DomainRegistrarFactory::class, new class(app(DomainRegistrarRegistry::class)) extends DomainRegistrarFactory
        {
            public function for(DomainRegistrarConnection $connection): DomainRegistrar
            {
                throw new RuntimeException('no such adapter');
            }
        });
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        $domain = $this->domain(['status' => DomainStatus::PendingTransfer, 'expires_at' => null, 'order_id' => $order->id,
            'epp_code' => 'Code-1', 'meta' => ['free_order' => true]]);

        $this->assertSame(DomainOrderFulfilment::STAFF, app(DomainOrderFulfilment::class)->fulfil($domain->id));
        $this->assertSame(1, ModuleAction::query()->count());
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, DomainRegistrarLog::query()->count());
    }

    // ---- DACoR ---------------------------------------------------------------------------------------------------

    /** A11 (held): a login of the account without managedomains cannot have the registry email the owner. */
    public function test_a11_a_member_without_managedomains_cannot_email_the_code(): void
    {
        $domain = $this->domain();
        $this->answers('v4.3/dacor-issue-token-response.xml');
        foreach ([['domains'], ['domains', 'invoices']] as $permissions) {
            Livewire::actingAs($this->member($permissions))->test(Show::class, ['domain' => $domain])
                ->assertDontSee(__('domains.portal.auth_code_email'))->call('emailAuthCode')->assertStatus(404);
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, DomainRegistrarLog::query()->count());

        // A member with managedomains may (and is logged as themself).
        $manager = $this->member(['domains', 'managedomains']);
        Livewire::actingAs($manager)->test(Show::class, ['domain' => $domain])->call('emailAuthCode');
        $this->assertSame($manager->id, (int) DomainRegistrarLog::query()->sole()->user_id);
    }

    /** A12 (held): staff in «Login as client» need admin.domains.epp; with it, logged as the staff member. */
    public function test_a12_staff_in_login_as_client_need_the_epp_permission(): void
    {
        $domain = $this->domain();
        $this->answers('v4.3/dacor-issue-token-response.xml');
        $status = null;
        try {
            $this->asStaffInLoginAsClient(Staff::factory()->withPermissions(['admin.users.impersonate'])->create(), $domain)->emailAuthCode();
        } catch (HttpException $e) {
            $status = $e->getStatusCode();
        }
        $this->assertSame(404, $status);
        $this->assertSame([], $this->transport->sent);

        $staff = Staff::factory()->withPermissions(['admin.users.impersonate', 'admin.domains.epp'])->create();
        $this->asStaffInLoginAsClient($staff, $domain)->emailAuthCode();
        $log = DomainRegistrarLog::query()->sole();
        $this->assertSame([$staff->id, null, DomainRegistrarLog::STATUS_OK], [(int) $log->staff_id, $log->user_id, $log->status]);
    }

    /** A13 (held): in the admin, without admin.domains.epp, neither action is there. */
    public function test_a13_admin_without_the_epp_permission_sees_no_dacor(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $domain = $this->domain();
        $staff = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view', 'admin.domains.manage'])->create();
        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->id])
            ->assertActionHidden('emailAuthCode')->assertActionHidden('eppCode');
        $this->assertSame([], $this->transport->sent);
    }

    /** A14 (held): an Openprovider domain is never offered DACoR; its «Show» still gives the code. */
    public function test_a14_dacor_is_not_offered_for_an_openprovider_domain_and_its_code_still_works(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                str_contains($url, '/authcode') => Http::response(['data' => ['auth_code' => 'Epp-Code-77']]),
                default => Http::response(['data' => ['id' => 30391502, 'status' => 'ACT', 'expiration_date' => '2027-11-17 00:00:00']]),
            };
        });
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 3, 'registrar_connection_id' => $this->op->id]);
        $domain = $this->domain(['sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com', 'domain_tld_id' => $com->id,
            'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'years' => 1]);

        $this->assertFalse(app(DomainManagementService::class)->emailsAuthCode($domain));
        $component = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->assertDontSee(__('domains.portal.auth_code_email'))->assertSee('revealEppCode');
        $this->assertSame('Epp-Code-77', $component->call('revealEppCode')->effects['returns'][0] ?? null);

        // Called anyway: refused before anything is asked of Openprovider, and the registry is never called.
        $component->call('emailAuthCode');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'dacor'));
        $this->assertSame([], $this->transport->sent);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view', 'admin.domains.epp', 'admin.domains.manage'])->create();
        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->id])
            ->assertActionVisible('eppCode')->assertActionHidden('emailAuthCode');
    }

    /** A15 (held): the log of a DACoR request has the masked address only: no password, no session, no code. */
    public function test_a15_the_dacor_log_holds_no_secret(): void
    {
        $domain = $this->domain(['epp_code' => 'Stored-Code-9']);
        $this->answers('v4.3/dacor-issue-token-response.xml');
        app(DomainManagementService::class)->emailAuthCode($domain, $this->user);

        $log = (string) json_encode(DomainRegistrarLog::query()->sole()->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['Secret-pw1', 'JSESSIONID', 'Stored-Code-9', 'myip1'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertStringContainsString('a***@b***.tld', $log);
    }

    /**
     * A16 (low, UX): one request, two toasts: atRegistrar() says «emailed» and then emailAuthCode() says the registry's
     * words as well. One success notification is expected.
     */
    public function test_a16_one_dacor_request_is_one_notification(): void
    {
        $domain = $this->domain();
        $this->answers('v4.3/dacor-issue-token-response.xml');
        $component = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('emailAuthCode');

        $notifications = collect(data_get($component->effects, 'dispatches'))->where('name', 'notify')->count();
        $this->assertSame(1, $notifications);
    }

    /**
     * A17 (low, abuse): each request makes the registry email the registrant (who may be a person other than the login:
     * a member with managedomains, or the company's contact). The dedicated limit is 10 a minute per login — the same as
     * the generic one — so 600 emails an hour per login, more with several logins. Expected: a few an hour per domain.
     */
    public function test_a17_dacor_emails_are_limited_per_domain_over_a_longer_window(): void
    {
        $domain = $this->domain();
        for ($i = 0; $i < 5; $i++) {
            $this->answers('v4.3/dacor-issue-token-response.xml'); // a new session each time (a new request)
            $this->transport->fixture('08-logout-response.xml');
        }
        foreach (range(1, 4) as $n) {
            Livewire::actingAs($n % 2 ? $this->user : $this->member(['domains', 'managedomains']))
                ->test(Show::class, ['domain' => $domain])->call('emailAuthCode');
            $this->travel(2)->minutes();
        }
        $sent = DomainRegistrarLog::query()->where('action', 'auth_code_email')->where('status', DomainRegistrarLog::STATUS_OK)->count();
        $this->assertLessThanOrEqual(3, $sent, "{$sent} registry emails to the registrant within ten minutes");
    }
}

/** The .gr registry's driver as it will be from G5: it registers and transfers. */
class AdvSellingGrEppRegistrar extends GrEppRegistrar
{
    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(supportsTransfer: true, renewsInGrace: true, renewYearStep: 2,
            authCodeByEmail: true, transferKeepsNameservers: true, supportsRegistration: true);
    }
}
