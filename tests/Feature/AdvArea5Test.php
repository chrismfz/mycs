<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainRenewalYears;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial area review 5 (domains). Each test fails on the reviewed head and proves a finding. Fake data only; the
 * registrar is Http::fake (no network).
 *
 * The premise is the one ADV-655-1 established and the code now states (WhmcsDomainImport::followsCatalog): WHMCS keeps
 * 0.00 in a TLD's year column it never sells, CatalogImport brings it as a price («0.00 = free and offered»), and a
 * renewal at a catalog price of zero is «never a free renewal». ADV-655-1 guarded only the importer's decision; every
 * other path that picks a catalog year still takes the 0.00 one.
 */
class AdvArea5Test extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.credits_auto_use' => false, 'settings.domain_client_years' => true]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
        $this->record = ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2026-11-01 10:00:00', 'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && (str_ends_with($path, '/renew') || str_ends_with($path, '/restore'))) {
                $this->charges[] = [$path, $request->data()];
                $years = str_ends_with($path, '/restore') ? 1 : (int) $request->data()['period'];
                $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');
                $this->record['status'] = 'ACT';
                unset($this->record['soft_quarantine_expiry_date'], $this->record['hard_quarantine_expiry_date']);

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** .com following its catalog (no own amount), renew prices per year as given. */
    private function domain(array $renew, array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ($renew as $years => $price) {
            DomainTldPrice::query()->updateOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function renewalLine(Domain $domain): ?InvoiceItem
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->first();
    }

    /**
     * ADV-A5-1. The customer picks the renewal period in the portal (DomainRenewalYears, «Clients may change the renewal
     * period», on by default) among every year with a renew price — the 0.00 column WHMCS never sold included. A .com at
     * 10.00 a year switched to «2 years» renews free: the cron bills 0.00, the zero invoice is marked paid on its own, and
     * the paid line buys two years at Openprovider on the company's account.
     */
    public function test_adv_a5_1_a_customer_never_switches_to_a_zero_priced_period_for_a_free_renewal(): void
    {
        $domain = $this->domain([1 => '10.00', 2 => '0.00']);

        try {
            app(DomainRenewalYears::class)->change($domain, 2, byStaff: false);
        } catch (\Throwable) {
            // refused: the fix
        }
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = $this->renewalLine($domain->fresh());
        if ($line !== null && $line->invoice->fresh()->status === 'paid') {
            app(DomainRenewalService::class)->renewLine($line->id);
        }

        $this->assertSame([], $this->charges, 'never two years bought at the registrar for a renewal nobody paid');
        $this->assertNotContains(2, DomainRenewalYears::options($domain->fresh()), 'a year priced 0.00 is not a period to renew for');
        $this->assertTrue($line === null || bccomp((string) $line->price, '0', 2) > 0, 'never billed 0.00 for a renewal the registrar charges');
    }

    /**
     * ADV-A5-2. In grace a renewal is its TLD's shortest priced period (Domain::stageRenewalYears, D-13), the 0.00 one
     * included. A two-year .com at its catalog's 19.00 (the 1-year column left at 0.00) lapses into Openprovider's soft
     * quarantine: the cron bills the restore at 0.00, the zero invoice pays itself, and the restore is bought for free.
     */
    public function test_adv_a5_2_a_domain_in_grace_is_never_restored_at_a_zero_catalog_price(): void
    {
        $domain = $this->domain([1 => '0.00', 2 => '19.00'], ['years' => 2, 'expires_at' => '2026-10-10', 'status' => DomainStatus::Grace]);
        $this->record = array_merge($this->record, ['status' => 'DEL', 'expiration_date' => '2026-10-10 10:00:00',
            'soft_quarantine_expiry_date' => '2026-11-19 10:00:00']);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = $this->renewalLine($domain->fresh());
        if ($line !== null && $line->invoice->fresh()->status === 'paid') {
            app(DomainRenewalService::class)->renewLine($line->id);
        }

        $this->assertSame([], $this->charges, 'never a restore bought on the company\'s account for a renewal nobody paid');
        $this->assertTrue($line === null || bccomp((string) $line->price, '0', 2) > 0, 'its restore is never billed at 0.00');
    }
}
