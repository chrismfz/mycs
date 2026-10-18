<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Invoice\RecordPaymentService;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * G4a of grepp: renewals for a registrar like the .gr registry: in its 15 days after expiry a name is renewed (no
 * restore, no grace fee), in even years (its shortest period, two, is what a renewal in grace bills); a renewal of
 * another number of years is never asked of it. The adapter's status decides, never one registrar's raw words.
 */
class GreppRenewalsTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        Cache::flush();
        Queue::fake();
        config(['domains.registrars.fakegr' => FakeGrRegistrar::class, 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        FakeGrRegistrar::$record = null;
        FakeGrRegistrar::$charges = [];
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'fakegr', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A .gr domain: its TLD renews in 2, 4, … years (15 days of grace, no redemption). */
    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([2 => '30.00', 4 => '60.00'] as $years => $price) {
            DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Grace, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true]);
    }

    /** Its renewal billed (as the cron) and paid. */
    private function billAndPay(Domain $domain): InvoiceItem
    {
        DB::transaction(fn () => app(RenewalBilling::class)->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->firstOrFail();
        Invoice::query()->whereKey($line->invoice_id)->update(['finish_pending_at' => null]);
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return $line->fresh();
    }

    public function test_a_renewal_in_grace_bills_the_tlds_shortest_period(): void
    {
        $domain = $this->domain();
        $this->assertSame(2, $domain->stageRenewalYears());

        DB::transaction(fn () => app(RenewalBilling::class)->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));

        $line = InvoiceItem::query()->where('reference_id', $domain->id)->sole();
        $this->assertSame(['2026-11-01', '2028-11-01', '30.00'], [$line->period_start->toDateString(), $line->period_end->toDateString(), number_format((float) $line->price, 2, '.', '')]);

        // A TLD with a one-year price keeps one year in grace (Openprovider's, D-13).
        DomainTldPrice::query()->create(['domain_tld_id' => $domain->domain_tld_id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '15.00']);
        $this->assertSame(1, $domain->fresh()->stageRenewalYears());
    }

    public function test_in_its_grace_it_is_renewed_not_restored(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        FakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2026-11-01', status: DomainStatus::Grace, rawStatus: 'pendingDelete', graceEndsOn: '2026-11-16');

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));

        $this->assertSame([['renew', 2]], FakeGrRegistrar::$charges, 'a renewal for its two years, never a restore');
        $this->assertSame([DomainStatus::Active, '2028-11-01'], [$domain->fresh()->status, $domain->fresh()->expires_at?->toDateString()]);
    }

    public function test_never_an_odd_number_of_years(): void
    {
        // The registry shows one year more than here: one year is lacking, which it does not renew.
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        FakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2027-11-01', status: DomainStatus::Active, rawStatus: 'ok');

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));

        $this->assertSame([], FakeGrRegistrar::$charges);
        $this->assertStringContainsString('steps of 2 years', (string) ModuleAction::query()->sole()->last_error);
    }

    public function test_the_gr_registry_declares_it(): void
    {
        $capabilities = (new GrEppRegistrar)->capabilities();
        $this->assertSame([true, 2], [$capabilities->renewsInGrace, $capabilities->renewYearStep]);
    }
}

/** A registrar like the .gr registry: renews in grace, in steps of two years; reads and renews a record in memory. */
class FakeGrRegistrar extends GrEppRegistrar
{
    public static ?DomainSyncResult $record = null;

    /** @var list<array{0: string, 1: int}> */
    public static array $charges = [];

    public function key(): string
    {
        return 'fakegr';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(renewsInGrace: true, renewYearStep: 2);
    }

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return self::$record ?? new DomainSyncResult(expiresAt: $domain->expires_at?->toDateString(), status: DomainStatus::Active, rawStatus: 'ok');
    }

    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$charges[] = ['renew', $years];
        $expires = Carbon::parse((string) self::$record?->expiresAt)->addYears($years)->toDateString();

        return self::$record = new DomainSyncResult(expiresAt: $expires, status: DomainStatus::Active, rawStatus: 'ok');
    }

    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$charges[] = ['restore', 1];

        throw new RegistrarRefused('no restore here');
    }
}
