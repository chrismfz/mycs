<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Livewire\Domains\Show;
use App\Livewire\DomainShop\Search;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainRegistrarCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * G4b of grepp: the shop sells .gr for its shortest period (two years: even periods) and shows a name «available under
 * restrictions» as such, never as taken; the EPP code of a .gr name is emailed to its registrant by the registry (DACoR),
 * from the client area and the admin; an inbound .gr transfer needs no nameservers (the registry keeps the name's).
 */
class GreppG4bTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    private DomainTld $tld;

    private User $user;

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        session(['currency' => 'EUR']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        foreach (['register' => [2 => '24.00', 4 => '46.00'], 'renew' => [2 => '24.00'], 'transfer' => [2 => '0.00']] as $operation => $prices) {
            foreach ($prices as $years => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
            }
        }
        $this->user = User::factory()->create();
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    /** hello, login, then the registry's answer to one command (its clTRID echoed). */
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

    public function test_the_shop_sells_gr_for_two_years_and_shows_restricted_names_as_such(): void
    {
        // The live answer: example1.gr taken, free-example.gr free, athina.gr «Διαθέσιμο υπό περιορισμούς».
        $this->answers('live/domain-check-response.xml');
        $statuses = app(DomainSearch::class)->statuses(['example1.gr' => $this->tld, 'free-example.gr' => $this->tld, 'athina.gr' => $this->tld]);
        $this->assertSame([DomainSearch::TAKEN, DomainSearch::FREE, DomainSearch::RESTRICTED],
            [$statuses['example1.gr'], $statuses['free-example.gr'], $statuses['athina.gr']]);

        // From G5a the driver registers: sold for its shortest period, two years.
        $this->assertSame(2, $this->tld->shortestYears('register', 'EUR'));
        $this->assertContains('gr', app(DomainSearch::class)->sold('EUR')->pluck('tld')->all(), 'sold for two years');

        // A restricted name never reaches the cart (the offer's check), with its own words.
        GrEppRegistrar::reset();
        Cache::flush();
        $this->transport = new FakeTransport;
        $this->answers('live/domain-check-response.xml');
        $refused = null;
        try {
            app(DomainOffer::class)->check('athina.gr', 'register', 2, 'EUR');
        } catch (DisplayException $e) {
            $refused = $e->getMessage();
        }
        $this->assertSame(__('domains.shop.restricted'), $refused);

        GrEppRegistrar::reset();
        Cache::flush();
        $this->transport = new FakeTransport;
        $this->answers('live/domain-check-response.xml');
        Livewire::test(Search::class)->set('query', 'free-example.gr')->call('search')
            ->assertSee(trans_choice('domains.shop.per_years', 2, ['price' => '24,00EUR', 'years' => 2]));
    }

    public function test_the_owner_gets_the_code_by_email_from_the_client_area(): void
    {
        $domain = $this->domain();
        $this->answers('v4.3/dacor-issue-token-response.xml');

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->assertSee(__('domains.portal.auth_code_email'))->assertDontSee('revealEppCode')
            ->call('emailAuthCode');

        $this->assertSame('update', $this->transport->kinds()[2], 'the registry was asked (domain:update with dacor:issueToken)');
        $this->assertStringContainsString('<dacor:issueToken', $this->transport->sent[2]['body']);
        $log = DomainRegistrarLog::query()->where('domain_id', $domain->id)->sole();
        $this->assertSame(['auth_code_email', DomainRegistrarLog::STATUS_OK, $this->user->id], [$log->action, $log->status, (int) $log->user_id]);
        $this->assertStringContainsString('a***@b***.tld', (string) json_encode($log->response, JSON_UNESCAPED_UNICODE));
    }

    public function test_staff_email_it_from_the_admin_and_never_see_get_epp_code(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $domain = $this->domain();
        $this->answers('v4.3/dacor-issue-token-response.xml');
        $staff = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view', 'admin.domains.epp', 'admin.domains.manage'])->create();

        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->id])
            ->assertActionHidden('eppCode')->assertActionVisible('emailAuthCode')
            ->callAction('emailAuthCode');

        $this->assertSame(1, DomainRegistrarLog::query()->where('action', 'auth_code_email')->where('staff_id', $staff->id)->count());
        $this->assertTrue(app(DomainManagementService::class)->emailsAuthCode($domain));
    }

    public function test_a_gr_transfer_needs_no_nameservers(): void
    {
        // The registry keeps the name's nameservers: no RDAP read (.gr has none), never «ns_unknown» for staff. The
        // transfer itself still refuses before anything is sent until G5.
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        $domain = $this->domain(['status' => DomainStatus::PendingTransfer, 'expires_at' => null, 'order_id' => $order->id,
            'epp_code' => 'Code-1', 'meta' => ['free_order' => true]]);

        $this->assertSame(DomainOrderFulfilment::STAFF, app(DomainOrderFulfilment::class)->fulfil($domain->id));

        $error = (string) ModuleAction::query()->sole()->last_error;
        $this->assertStringNotContainsString(__('domains.order.ns_unknown', ['domain' => 'example1.gr']), $error);
        $this->assertStringContainsString('G5', $error);
        $this->assertSame([], $this->transport->sent, 'nothing asked of the registry');
        $this->assertSame(0, $domain->nameservers()->count());
    }

    public function test_capabilities(): void
    {
        $capabilities = (new GrEppRegistrar)->capabilities();
        $this->assertSame([true, true], [$capabilities->authCodeByEmail, $capabilities->transferKeepsNameservers]);
        $this->assertInstanceOf(AvailabilityResult::class, new AvailabilityResult('a.gr', false, 'restricted: x'));
        $this->assertNotNull(new DomainRegistrarCredentials);
    }
}
