<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\Ordering\DomainOrderLine;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Review 2 of #696 (D1 follow-up): a transferred domain renews for a period its TLD renews for, the shortest offered in
 * its currency. A .gr renews in steps of two: created with one year, the cron never billed it («steps of 2, not 1»).
 * The transfer's own line stays as ordered.
 */
class TransferRenewalPeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['domains.registrars.fakegrperiod' => FakeGrPeriodRegistrar::class, 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
        $this->user->client()->ensurePrimaryContact()->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, array<int, string>>  $prices */
    private function tld(string $name, array $prices, ?int $connection = null): DomainTld
    {
        $tld = DomainTld::query()->create(['tld' => $name, 'is_active' => true, 'registrar_connection_id' => $connection]);
        foreach ($prices as $operation => $byYears) {
            foreach ($byYears as $years => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
            }
        }

        return $tld->load('prices');
    }

    /** Ordered as a transfer, then (as its registrar would) active until a year from now: its renewal billed by the cron's step. */
    private function transferAndBill(string $fqdn, DomainTld $tld): array
    {
        $client = $this->user->client();
        app(PlaceOrderService::class)->place($client, 'EUR', [], domains: [new DomainOrderLine($fqdn, $tld, DomainOrderLine::TRANSFER, 1, $client->ensurePrimaryContact()->fresh(), 'Epp-Code-1')]);
        $domain = Domain::query()->where('fqdn', $fqdn)->sole();
        $transfer = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->where('kind', InvoiceItem::KIND_TRANSFER)->sole();
        $domain->forceFill(['status' => DomainStatus::Active, 'expires_at' => '2026-11-01'])->save();
        $billing = app(RenewalBilling::class);
        DB::transaction(fn () => $billing->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));
        $renewal = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->first();

        return [$domain->fresh(), $transfer, $renewal, $billing->notices];
    }

    public function test_a_gr_transfer_renews_for_two_years_and_is_billed(): void
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'fakegrperiod', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $tld = $this->tld('gr', ['register' => [2 => '24.00'], 'renew' => [2 => '24.00', 4 => '48.00'], 'transfer' => [1 => '5.00']], $gr->id);

        [$domain, $transfer, $renewal, $notices] = $this->transferAndBill('mybusiness.gr', $tld);

        $this->assertSame(2, $domain->years, 'its shortest renewal period, not the transfer\'s year');
        $this->assertSame('5.00', $transfer->price, 'the transfer billed as ordered');
        $this->assertSame([], $notices, 'never «steps of 2, not 1»');
        $this->assertNotNull($renewal, 'its renewal billed');
        $this->assertSame(['2026-11-01', '2028-11-01', '24.00'], [$renewal->period_start->toDateString(), $renewal->period_end->toDateString(), $renewal->price]);
    }

    public function test_a_com_transfer_keeps_one_year_and_is_billed(): void
    {
        $tld = $this->tld('com', ['register' => [1 => '10.00'], 'renew' => [1 => '11.00', 2 => '22.00'], 'transfer' => [1 => '9.00']]);

        [$domain, , $renewal, $notices] = $this->transferAndBill('mybusiness.com', $tld);

        $this->assertSame(1, $domain->years, 'one year: its TLD renews for one');
        $this->assertSame([], $notices);
        $this->assertSame(['2026-11-01', '2027-11-01', '11.00'], [$renewal?->period_start->toDateString(), $renewal?->period_end->toDateString(), $renewal?->price]);
    }
}

/** The .gr registry's adapter (renewals in steps of two, transfers), asked nothing: only its capabilities are read. */
class FakeGrPeriodRegistrar extends GrEppRegistrar
{
    public function key(): string
    {
        return 'fakegrperiod';
    }
}
