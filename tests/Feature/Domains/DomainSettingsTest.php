<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Classes\Settings;
use App\Console\Commands\Domains\SyncDomains;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Livewire\Domains\Show;
use App\Livewire\DomainShop\Search;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRenewalYears;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Domains settings tab (owner 2026-10-17, as WHMCS's): what the shop takes, new domains' auto renew, the default
 * nameservers one per field, how long a transfer's EPP code is kept and when a pending transfer is flagged; and the
 * renewal period, changed by the customer or staff among the years priced, from the next renewal (its open, unpaid
 * renewal invoice with it).
 */
class DomainSettingsTest extends TestCase
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
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true]);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'grace_days' => 40, 'redemption_days' => 30]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['renew', 2, '45.00'], ['renew', 3, '66.00'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->user = User::factory()->create();
        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            str_contains($request->url(), '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                'domain' => $d['name'] . '.' . $d['extension'], 'status' => $d['name'] === 'nixpal' ? 'active' : 'free',
            ], $request->data()['domains'])]]),
            default => Http::response([], 404),
        });
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'mine', 'tld' => 'com',
            'fqdn' => 'mine.com', 'domain_tld_id' => $this->com->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true]);
    }

    /** Its next period billed as the cron bills it: one year from its expiry, unpaid. */
    private function openRenewal(Domain $domain): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-01']);

        return $invoice->items()->create(['description' => 'Renewal', 'price' => '23.33', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => '2026-11-01', 'period_end' => '2027-11-01']);
    }

    public function test_the_domains_tab_holds_the_domain_settings(): void
    {
        $names = collect(Settings::settings()['domains'])->pluck('name')->all();

        foreach (['domain_allow_register', 'domain_allow_transfer', 'domain_default_auto_renew', 'domain_nameserver_1', 'domain_nameserver_5',
            'domain_billing_enabled', 'domain_invoice_days', 'domain_reminder_days', 'domain_client_years', 'domain_epp_code_days', 'domain_stale_transfer_days'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertNotContains('domain_billing_enabled', collect(Settings::settings()['cronjob'])->pluck('name')->all(), 'moved, not copied');
    }

    public function test_the_old_nameserver_textarea_moves_to_the_fields(): void
    {
        DB::table('settings')->insert(['key' => 'domain_default_nameservers', 'value' => "NS1.myip.gr\nns2.myip.gr\n\nns3.myip.gr", 'type' => 'string',
            'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_11_29_000000_domain_nameserver_settings.php'))->up();

        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr', 'ns3.myip.gr'],
            DB::table('settings')->where('key', 'like', 'domain_nameserver_%')->orderBy('key')->pluck('value')->filter()->values()->all());
        $this->assertFalse(DB::table('settings')->where('key', 'domain_default_nameservers')->exists());
        config(['settings.domain_nameserver_1' => 'ns1.myip.gr', 'settings.domain_nameserver_2' => 'NS2.myip.gr.', 'settings.domain_nameserver_4' => 'ns1.myip.gr']);
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr'], DomainOrderFulfilment::defaultNameservers(), 'lower case, no trailing dot, once each');
    }

    public function test_the_shop_takes_only_what_is_allowed(): void
    {
        config(['settings.domain_allow_register' => false]);
        Livewire::test(Search::class)->set('query', 'mybusiness')->call('search')->assertSee('mybusiness.com')->assertDontSee(__('domains.order.add'));
        Livewire::test(Search::class)->set('query', 'nixpal')->call('search')->assertSee(__('domains.order.transfer_it'));
        Livewire::test(Search::class)->call('add', 'mybusiness.com')->assertNoRedirect();

        config(['settings.domain_allow_register' => true, 'settings.domain_allow_transfer' => false]);
        Livewire::test(Search::class)->set('query', 'mybusiness')->call('search')->assertSee(__('domains.order.add'));
        Livewire::test(Search::class)->set('query', 'nixpal')->call('search')->assertSee('nixpal.com')->assertDontSee(__('domains.order.transfer_it'));
        Livewire::test(Search::class)->call('transfer', 'nixpal.com')->assertNoRedirect();
        $this->assertSame(0, Cart::query()->count() > 0 ? Cart::query()->sole()->items()->count() : 0);
    }

    public function test_the_customer_changes_the_period_and_the_open_renewal_invoice_follows(): void
    {
        $domain = $this->domain();
        $line = $this->openRenewal($domain);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->assertSee(__('domains.portal.renewal_period'))
            ->call('setRenewalYears', 2)->assertHasNoErrors();

        $this->assertSame(2, $domain->fresh()->years);
        $line->refresh();
        $this->assertSame(['2028-11-01', '45.00'], [$line->period_end->toDateString(), (string) $line->price]);
        $this->assertSame(0, bccomp('45.00', (string) $line->invoice->fresh()->total, 2));

        // Not offered: refused, nothing changes.
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])->call('setRenewalYears', 5);
        $this->assertSame(2, $domain->fresh()->years);
    }

    public function test_an_invoice_with_money_on_it_keeps_its_period_the_next_one_changes(): void
    {
        $domain = $this->domain();
        $line = $this->openRenewal($domain);
        (new RecordPaymentService)->handle($line->invoice, null, '10.00', transactionId: 'part-1');

        $this->assertSame(DomainRenewalYears::NEXT, app(DomainRenewalYears::class)->change($domain, 3, byStaff: false));

        $this->assertSame([3, '2027-11-01', '23.33'], [$domain->fresh()->years, $line->fresh()->period_end->toDateString(), (string) $line->fresh()->price]);
    }

    public function test_who_may_change_it(): void
    {
        // The setting off: staff only.
        config(['settings.domain_client_years' => false]);
        $domain = $this->domain();
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->assertDontSee('setRenewalYears');
        $this->assertFalse(DomainRenewalYears::clientMay($domain));
        $this->assertSame(DomainRenewalYears::NEXT, app(DomainRenewalYears::class)->change($domain, 2, byStaff: true));

        // A price set by hand is for its period: staff change both.
        config(['settings.domain_client_years' => true]);
        $custom = $this->domain(['sld' => 'custom', 'fqdn' => 'custom.com', 'recurring_amount' => '10.00']);
        $this->assertFalse(DomainRenewalYears::clientMay($custom));

        // Pending: its order registers the years paid for.
        $pending = $this->domain(['sld' => 'pending', 'fqdn' => 'pending.com', 'status' => DomainStatus::PendingRegister, 'expires_at' => null]);
        $this->expectException(DisplayException::class);
        app(DomainRenewalYears::class)->change($pending, 2, byStaff: true);
    }

    public function test_in_grace_the_open_renewal_stays_one_year(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-10']);
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-20']);
        $line = $invoice->items()->create(['description' => 'Renewal', 'price' => '23.33', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => '2026-10-10', 'period_end' => '2027-10-10']);

        $this->assertSame(DomainRenewalYears::NEXT, app(DomainRenewalYears::class)->change($domain, 2, byStaff: false));
        $this->assertSame('2027-10-10', $line->fresh()->period_end->toDateString());
    }

    public function test_staff_change_it_from_the_domain_edit_form(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $domain = $this->domain();
        $line = $this->openRenewal($domain);
        $staff = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view', 'admin.domains.update'])->create();

        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->id])
            ->callAction('edit', data: ['auto_renew' => true, 'notes' => '', 'years' => 3])->assertHasNoActionErrors();

        $this->assertSame([3, '2029-11-01', '66.00'], [$domain->fresh()->years, $line->fresh()->period_end->toDateString(), (string) $line->fresh()->price]);
    }

    public function test_epp_code_days_and_stale_transfer_days_are_settings(): void
    {
        config(['settings.domain_epp_code_days' => 5, 'settings.domain_stale_transfer_days' => 3]);
        $done = $this->domain(['epp_code' => 'Code-1']);
        DB::table('domains')->where('id', $done->id)->update(['created_at' => now()->subDays(6)]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertNull($done->fresh()->epp_code);
        $this->assertSame(3, SyncDomains::staleTransferDays());
    }
}
