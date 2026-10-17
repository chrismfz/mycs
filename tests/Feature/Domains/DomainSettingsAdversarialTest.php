<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
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
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainRenewalYears;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\OrderPricing;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Adversarial review of PR #182 (Settings → Domains, DomainRenewalYears). Tests named test_advN_* are findings and fail
 * until fixed; the others are hypotheses that held (regressions).
 */
class DomainSettingsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $com;

    private DomainRegistrarConnection $op;

    /** @var array<string, mixed> the registrar's record */
    private array $record = [];

    /** @var list<array<string, mixed>> renew/restore bodies asked */
    private array $charges = [];

    /** The registrar charges but shows the old expiry for a while (a lagging registry). */
    private bool $lag = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.credits_auto_use' => false]);
        session(['currency' => 'EUR']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $this->op->id, 'grace_days' => 40, 'redemption_days' => 30]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['renew', 2, '45.00'], ['renew', 3, '66.00'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->user = User::factory()->create();
        $this->user->client()->ensurePrimaryContact()->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
        $this->record = ['id' => 30391502, 'domain' => ['name' => 'mine', 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2026-11-01 10:00:00', 'name_servers' => [['name' => 'ns1.example.test'], ['name' => 'ns2.example.test']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if (str_contains($path, '/domains/check')) {
                return Http::response(['data' => ['results' => array_map(fn ($d) => ['domain' => $d['name'] . '.' . $d['extension'],
                    'status' => $d['name'] === 'nixpal' ? 'active' : 'free'], $request->data()['domains'])]]);
            }
            if ($request->method() === 'POST' && (str_ends_with($path, '/renew') || str_ends_with($path, '/restore'))) {
                $this->charges[] = $request->data();
                if (!$this->lag) {
                    $years = str_ends_with($path, '/restore') ? 1 : (int) $request->data()['period'];
                    $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');
                }

                return Http::response(['data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
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
            'fqdn' => 'mine.com', 'domain_tld_id' => $this->com->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502',
            'status' => DomainStatus::Active, 'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function openRenewal(Domain $domain, string $start = '2026-11-01', int $years = 1, string $price = '23.33', string $status = Invoice::STATUS_UNPAID): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'status' => $status, 'currency_code' => 'EUR', 'due_at' => $start]);

        return $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => $start, 'period_end' => Carbon::parse($start)->addYears($years)->toDateString()]);
    }

    private function pay(InvoiceItem $line): void
    {
        (new RecordPaymentService)->handle($line->invoice->fresh(), null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);
    }

    private function change(Domain $domain, int $years, bool $byStaff = false): string
    {
        return app(DomainRenewalYears::class)->change($domain, $years, byStaff: $byStaff);
    }

    // ---------------------------------------------------------------- findings (fail until fixed)

    /**
     * ADV-1 (high, money). «Renew now» charged the registrar for the line's 3 years; the registry lags (the expiry it
     * shows has not moved), so the unpaid line is still «the open renewal». The customer picks 1 year: the line becomes
     * 1 year at 23.33, they pay that, and the payment adopts the 3 years the registrar shows (DomainRenewalService
     * covers()). The company paid the registrar 3 years for 23.33. A line with a registrar charge for it (done or
     * unconfirmed) must keep its period.
     */
    public function test_adv1_a_line_already_charged_at_the_registrar_keeps_its_period(): void
    {
        $domain = $this->domain(['years' => 3]);
        $this->lag = true;
        $staff = Staff::factory()->withPermissions(['*'])->create();
        $line = app(DomainRenewalService::class)->renewNow($domain, $staff);
        $this->assertCount(1, $this->charges);
        $this->assertSame([3, '2029-11-01', '66.00'], [(int) $this->charges[0]['period'], $line->period_end->toDateString(), (string) $line->price]);
        $this->assertSame('2026-11-01', $domain->fresh()->expires_at->toDateString(), 'the registry lags');

        $this->change($domain->fresh(), 1);

        $line->refresh();
        $this->assertSame(['2029-11-01', '66.00'], [$line->period_end->toDateString(), (string) $line->price],
            'the registrar was charged 3 years for this line: repriced to 1 year the customer pays 23.33 and keeps 3 years');
    }

    /** ADV-1b: the same with a charge whose outcome is unknown (a timeout): it may have renewed the 3 years. */
    public function test_adv1b_a_line_with_an_unconfirmed_charge_keeps_its_period(): void
    {
        $domain = $this->domain(['years' => 3]);
        $line = $this->openRenewal($domain, years: 3, price: '66.00');
        DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $this->op->id, 'invoice_id' => $line->invoice_id,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_PENDING, 'request' => ['years' => 3, 'target_expiry' => '2029-11-01', 'line' => $line->id]]);

        $this->change($domain, 1);

        $this->assertSame(['2029-11-01', '66.00'], [$line->fresh()->period_end->toDateString(), (string) $line->fresh()->price]);
    }

    /**
     * ADV-2 (medium, money). A price set by hand (recurring_amount) is for its period; staff change the period in the
     * Edit form (which cannot change the amount): the open line becomes 3 years at the 1-year hand price (10.00). Paid
     * before staff notice, the registrar renews 3 years for 10.00. The open line should keep its period (NEXT), or the
     * change should be refused until the amount is set for the new period.
     */
    public function test_adv2_a_hand_set_price_is_not_put_on_a_longer_period(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $domain = $this->domain(['recurring_amount' => '10.00']);
        $line = $this->openRenewal($domain, price: '10.00');
        $staff = Staff::factory()->withPermissions(['admin.domains.viewAny', 'admin.domains.view', 'admin.domains.update'])->create();

        $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->id])
            ->callAction('edit', data: ['auto_renew' => true, 'notes' => '', 'years' => 3]);

        $line->refresh();
        $this->assertFalse($line->period_end->toDateString() === '2029-11-01' && bccomp((string) $line->price, '10.00', 2) === 0,
            'the open invoice now renews 3 years for the 1-year hand price');
    }

    /**
     * ADV-3 (low). Expired (past its expiry, before any grace) is billed for its years like Active (RenewalBilling: one
     * year only in grace or redemption, D-13), but its open, unpaid renewal does not follow the change: the customer is
     * told «from its next renewal» (the open one «is paid, or has money on it»), which is not so; the period they chose
     * is not what they pay now.
     */
    public function test_adv3_an_expired_domains_open_renewal_follows_the_period(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Expired, 'expires_at' => '2026-10-15']);
        $line = $this->openRenewal($domain, start: '2026-10-15');

        $this->assertSame(DomainRenewalYears::INVOICE, $this->change($domain, 2));
        $this->assertSame(['2028-10-15', '45.00'], [$line->fresh()->period_end->toDateString(), (string) $line->fresh()->price]);
    }

    // ---------------------------------------------------------------- hypotheses that held (regressions)

    /** Changed, then paid: the registrar renews the line's years (3), and the expiry is the period's end. */
    public function test_changed_then_paid_renews_the_new_years_at_the_registrar(): void
    {
        $domain = $this->domain();
        $line = $this->openRenewal($domain);
        $this->assertSame(DomainRenewalYears::INVOICE, $this->change($domain, 3));
        $this->assertSame(0, bccomp('66.00', (string) $line->invoice->fresh()->total, 2));

        $this->pay($line->fresh());
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));

        $this->assertSame([3], array_map(fn ($c) => (int) $c['period'], $this->charges));
        $this->assertSame('2029-11-01', $domain->fresh()->expires_at->toDateString());
    }

    /** Paid but not renewed yet: the line keeps its year; the job renews that year, not the new period. */
    public function test_paid_before_the_job_ran_renews_what_was_paid(): void
    {
        $domain = $this->domain();
        $line = $this->openRenewal($domain);
        $this->pay($line);

        $this->assertSame(DomainRenewalYears::NEXT, $this->change($domain, 3));
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));

        $this->assertSame([1], array_map(fn ($c) => (int) $c['period'], $this->charges));
        $this->assertSame(['2027-11-01', 3], [$domain->fresh()->expires_at->toDateString(), $domain->fresh()->years]);
    }

    /** Prices incl. VAT: the line is net of the client's VAT, the total the catalog price. */
    public function test_prices_including_vat_stay_net_on_the_line(): void
    {
        foreach (['tax_enabled' => [true, 'boolean'], 'tax_type' => ['inclusive', 'string'], 'tax_rate' => [24, 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        $domain = $this->domain();
        $line = $this->openRenewal($domain, price: OrderPricing::toNet('23.33', $this->user->client()));

        $this->change($domain, 2);

        $this->assertSame(OrderPricing::toNet('45.00', $this->user->client()), (string) $line->fresh()->price);
        $this->assertSame(0, bccomp('45.00', (string) $line->invoice->fresh()->total, 2));
    }

    /** Another line on the same invoice is left as it is; the total is both. */
    public function test_other_lines_on_the_invoice_are_untouched(): void
    {
        $domain = $this->domain();
        $line = $this->openRenewal($domain);
        $other = $line->invoice->items()->create(['description' => 'Something else', 'price' => '5.00', 'quantity' => 1]);

        $this->change($domain, 2);

        $this->assertSame('5.00', (string) $other->fresh()->price);
        $this->assertSame(0, bccomp('50.00', (string) $line->invoice->fresh()->total, 2));
    }

    public function test_a_draft_follows_and_changing_twice_comes_back(): void
    {
        $domain = $this->domain();
        $line = $this->openRenewal($domain, status: Invoice::STATUS_DRAFT);

        $this->assertSame(DomainRenewalYears::INVOICE, $this->change($domain, 3));
        $this->assertSame(DomainRenewalYears::INVOICE, $this->change($domain->fresh(), 1));

        $this->assertSame(['2027-11-01', '23.33', 1], [$line->fresh()->period_end->toDateString(), (string) $line->fresh()->price, $domain->fresh()->years]);
    }

    /** Nothing billed yet: the cron bills the new period at its price. */
    public function test_the_cron_bills_the_new_period(): void
    {
        $domain = $this->domain();
        $this->assertSame(DomainRenewalYears::NEXT, $this->change($domain, 2));

        $this->artisan('app:cron-job')->assertExitCode(0);

        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->sole();
        $this->assertSame(['2026-11-01', '2028-11-01', '45.00'], [$line->period_start->toDateString(), $line->period_end->toDateString(), (string) $line->price]);
    }

    /** Livewire tampering: a period not priced, negative, zero or 11 changes nothing; a numeric string is the number. */
    public function test_tampered_periods_change_nothing(): void
    {
        $domain = $this->domain();
        $line = $this->openRenewal($domain);
        foreach ([11, -1, 0, 4] as $years) {
            Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])->call('setRenewalYears', $years);
        }
        $this->assertSame([1, '23.33'], [$domain->fresh()->years, (string) $line->fresh()->price]);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])->call('setRenewalYears', '2');
        $this->assertSame(2, $domain->fresh()->years, 'the select sends a string');

        // Not a number: refused without changing anything (whatever Livewire answers).
        try {
            Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])->call('setRenewalYears', 'abc');
        } catch (\TypeError) {
            // a 500 on a tampered request: harmless
        }
        $this->assertSame(2, $domain->fresh()->years);
    }

    /** A member without managedomains may not; staff in «Login as client» without admin.domains.update may not. */
    public function test_who_may_not(): void
    {
        $domain = $this->domain();
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => ['domains', 'invoices']]);
        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Livewire::actingAs($member)->test(Show::class, ['domain' => $domain])->call('setRenewalYears', 2)->assertStatus(404);
        $this->assertSame(1, $domain->fresh()->years);
        // With managedomains the same member may (so the refusal above is the permission's).
        ClientUser::query()->where('user_id', $member->id)->update(['permissions' => json_encode(['domains', 'managedomains'])]);
        Livewire::actingAs($member)->test(Show::class, ['domain' => $domain->fresh()])->call('setRenewalYears', 2);
        $this->assertSame(2, $domain->fresh()->years);
        DB::table('domains')->where('id', $domain->id)->update(['years' => 1]);
        $domain->refresh();

        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(['admin.users.impersonate'])->create());
        Auth::guard('web')->setUser($this->user);
        $component = app(Show::class);
        $component->domain = $domain;
        $component->mount();
        try {
            $component->setRenewalYears(2);
            $this->fail('changed without admin.domains.update');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(1, $domain->fresh()->years);
    }

    /** Registrations turned off with one already in the cart: checkout refuses it. */
    public function test_a_registration_in_the_cart_is_refused_once_turned_off(): void
    {
        Livewire::test(Search::class)->call('add', 'mybusiness.com')->assertRedirect(route('cart'));
        config(['settings.domain_allow_register' => false]);
        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Livewire::withCookie('cart', (string) \App\Models\Cart::query()->latest('id')->value('ulid'))->actingAs($this->user)
            ->test(Cart::class)->call('checkout');

        $this->assertSame(0, Domain::query()->count());
    }

    /** The migration twice, and over fields already set: what was set is kept, nothing doubled. */
    public function test_the_migration_is_rerunnable_and_keeps_fields_set(): void
    {
        DB::table('settings')->where('key', 'like', 'domain_nameserver_%')->delete();
        DB::table('settings')->insert(['key' => 'domain_nameserver_1', 'value' => 'ns1.kept.test', 'type' => 'string', 'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('settings')->insert(['key' => 'domain_default_nameservers', 'value' => 'ns1.old.test, ns2.old.test', 'type' => 'string', 'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_11_29_000000_domain_nameserver_settings.php');

        $migration->up();
        $migration->up();

        $this->assertSame(['domain_nameserver_1' => 'ns1.kept.test', 'domain_nameserver_2' => 'ns2.old.test'],
            DB::table('settings')->where('key', 'like', 'domain_nameserver_%')->orderBy('key')->pluck('value', 'key')->all());
    }
}
