<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainAdopter;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainRenewalYears;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Invoice\RecordPaymentService;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of #189 (G4a of grepp): Openprovider's restore path unchanged by the move to the adapter's status;
 * .gr renewals in grace billed and charged for even years only, never twice, from the old expiry.
 */
class GreppRenewalsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> Openprovider's record */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $opCharges = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['domains.registrars.advfakegr' => AdvFakeGrRegistrar::class, 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        AdvFakeGrRegistrar::$record = null;
        AdvFakeGrRegistrar::$charges = [];
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'advfakegr', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function grTld(): DomainTld
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([2 => '30.00', 4 => '60.00'] as $years => $price) {
            DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }

        return $tld;
    }

    private function grDomain(array $attributes = []): Domain
    {
        $tld = $this->grTld();

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Grace, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function bill(Domain $domain): InvoiceItem
    {
        DB::transaction(fn () => app(RenewalBilling::class)->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));

        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->firstOrFail();
    }

    private function pay(InvoiceItem $line): InvoiceItem
    {
        Invoice::query()->whereKey($line->invoice_id)->update(['finish_pending_at' => null]);
        (new RecordPaymentService)->handle($line->invoice->fresh(), null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return $line->fresh();
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function amount(InvoiceItem $line): string
    {
        return number_format((float) $line->price, 2, '.', '');
    }

    /** Openprovider as DomainRenewalTest fakes it. */
    private function fakeOp(array $record): void
    {
        $this->record = $record + ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'],
            'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && (str_ends_with($path, '/renew') || str_ends_with($path, '/restore'))) {
                $this->opCharges[] = [$path, $request->data()];
                $years = str_ends_with($path, '/restore') ? 1 : (int) $request->data()['period'];
                $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');
                $this->record['status'] = 'ACT';
                unset($this->record['soft_quarantine_expiry_date'], $this->record['hard_quarantine_expiry_date']);

                return Http::response(['data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
        });
    }

    private function opDomain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([1 => '23.33', 2 => '46.66', 3 => '69.99'] as $years => $price) {
            DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-20', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    // ── Openprovider: regressions ─────────────────────────────────────────

    public function test_op_del_in_grace_with_an_unpaid_grace_fee_still_goes_to_staff(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-20 10:00:00']);
        $domain = $this->opDomain(['years' => 2]);
        DomainTldPrice::query()->create(['domain_tld_id' => $domain->domain_tld_id, 'operation' => 'grace', 'years' => 1, 'currency_code' => 'EUR', 'price' => '10.00']);
        $line = $this->pay($this->bill($domain));
        $this->record = array_merge($this->record, ['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-12-30 10:00:00']);

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([], $this->opCharges);
        $this->assertStringContainsString('grace fee is not paid', (string) ModuleAction::query()->open()->sole()->last_error);
    }

    public function test_op_del_in_grace_without_a_fee_is_restored_then_renewed_for_the_rest(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-20 10:00:00']);
        $domain = $this->opDomain(['years' => 2]);
        $line = $this->pay($this->bill($domain));
        $this->record = array_merge($this->record, ['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-12-30 10:00:00']);

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(['/v1beta/domains/30391502/restore', '/v1beta/domains/30391502/renew'], array_column($this->opCharges, 0));
        $this->assertSame(1, $this->opCharges[1][1]['period']);
        $this->assertSame('2028-11-20', $domain->fresh()->expires_at->toDateString());
    }

    public function test_op_pending_req_goes_to_staff_and_charges_nothing(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-20 10:00:00']);
        $domain = $this->opDomain();
        $line = $this->pay($this->bill($domain));
        $this->record['status'] = 'REQ';

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([], $this->opCharges);
        $this->assertStringContainsString('status REQ', (string) ModuleAction::query()->open()->sole()->last_error);
    }

    public function test_op_a_renewal_of_three_years_is_charged_three(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-20 10:00:00']);
        $domain = $this->opDomain(['years' => 3]);
        $line = $this->bill($domain);
        $this->assertSame(['2029-11-20', '69.99'], [$line->period_end->toDateString(), $this->amount($line)]);
        $line = $this->pay($line);

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertCount(1, $this->opCharges);
        $this->assertSame(3, $this->opCharges[0][1]['period']);
    }

    public function test_op_renew_now_in_its_grace_is_still_refused(): void
    {
        $this->fakeOp(['status' => 'DEL', 'expiration_date' => '2026-11-01 10:00:00', 'soft_quarantine_expiry_date' => '2026-12-11 10:00:00']);
        $domain = $this->opDomain(['expires_at' => '2026-11-01', 'status' => DomainStatus::Grace]);

        try {
            app(DomainRenewalService::class)->renewNow($domain, $this->staff());
            $refused = false;
        } catch (RuntimeException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'a restore is never done by «Renew now»');
        $this->assertSame([], $this->opCharges);
    }

    // ── .gr: billing ──────────────────────────────────────────────────────

    public function test_gr_grace_bills_two_years_whatever_its_own_period(): void
    {
        $line = $this->bill($this->grDomain(['years' => 4]));

        $this->assertSame(['2026-11-01', '2028-11-01', '30.00'], [$line->period_start->toDateString(), $line->period_end->toDateString(), $this->amount($line)]);
    }

    public function test_gr_grace_keeps_a_price_staff_set_for_its_two_years(): void
    {
        $line = $this->bill($this->grDomain(['recurring_amount' => '25.00']));

        $this->assertSame(['2028-11-01', '25.00'], [$line->period_end->toDateString(), $this->amount($line)]);
    }

    public function test_gr_grace_in_a_currency_priced_only_for_four_years_bills_four_at_that_price(): void
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'suffix' => 'USD', 'format' => '1,000.00']);
        DomainTldPrice::query()->create(['domain_tld_id' => $this->grTld()->id, 'operation' => 'renew', 'years' => 4, 'currency_code' => 'USD', 'price' => '70.00']);
        $domain = $this->grDomain(['currency_code' => 'USD']);

        $this->assertSame(4, $domain->stageRenewalYears());
        $line = $this->bill($domain);
        $this->assertSame(['2030-11-01', '70.00'], [$line->period_end->toDateString(), $this->amount($line)]);
    }

    public function test_gr_without_a_grace_fee_none_is_added(): void
    {
        $domain = $this->grDomain();
        $line = $this->bill($domain);
        DB::transaction(fn () => app(RenewalBilling::class)->addStageFee($domain->id));

        $this->assertSame(1, $line->invoice->items()->count());
    }

    public function test_a_domain_without_its_tld_stages_one_year(): void
    {
        $this->assertSame(1, (new Domain(['currency_code' => 'EUR']))->stageRenewalYears());
    }

    // ── .gr: charging ─────────────────────────────────────────────────────

    public function test_gr_period_changed_after_its_grace_billing_renews_what_was_billed(): void
    {
        $domain = $this->grDomain();
        $line = $this->bill($domain);
        $this->assertSame(DomainRenewalYears::NEXT, app(DomainRenewalYears::class)->change($domain, 4, byStaff: true));
        $this->assertSame('2028-11-01', $line->fresh()->period_end->toDateString(), 'the open grace line keeps its two years');

        $line = $this->pay($line);
        AdvFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2026-11-01', status: DomainStatus::Grace, rawStatus: 'pendingDelete', graceEndsOn: '2026-11-16');
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([['renew', 2]], AdvFakeGrRegistrar::$charges);
        $this->assertSame('2028-11-01', $domain->fresh()->expires_at->toDateString());
    }

    public function test_gr_four_year_line_with_two_already_charged_is_charged_two_more_only(): void
    {
        $domain = $this->grDomain(['status' => DomainStatus::Active, 'expires_at' => '2026-11-20', 'years' => 4]);
        $line = $this->bill($domain);
        $this->assertSame(['2030-11-20', '60.00'], [$line->period_end->toDateString(), $this->amount($line)]);
        $line = $this->pay($line);
        DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $this->gr->id, 'invoice_id' => $line->invoice_id,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK, 'request' => ['years' => 2, 'target_expiry' => '2030-11-20', 'line' => $line->id]]);
        AdvFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2026-11-20', status: DomainStatus::Active, rawStatus: 'ok');

        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertSame([['renew', 2]], AdvFakeGrRegistrar::$charges, 'two more, never four');
        $this->assertNotNull($line->fresh()->applied_at);
    }

    public function test_gr_paid_line_after_the_domain_left_its_grace_is_adopted_not_charged(): void
    {
        $domain = $this->grDomain();
        $line = $this->pay($this->bill($domain));
        // Renewed meanwhile (staff at the registry): active, covered.
        AdvFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2028-11-01', status: DomainStatus::Active, rawStatus: 'ok');

        $this->assertSame(DomainRenewalService::ADOPTED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([], AdvFakeGrRegistrar::$charges);
    }

    public function test_gr_paid_line_after_its_grace_ended_goes_to_staff(): void
    {
        $domain = $this->grDomain();
        $line = $this->pay($this->bill($domain));
        AdvFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2026-11-01', status: DomainStatus::Expired, rawStatus: 'ok');

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([], AdvFakeGrRegistrar::$charges);
    }

    public function test_gr_renew_now_in_grace_renews_two_years_from_the_old_expiry_and_the_payment_adopts_it(): void
    {
        $domain = $this->grDomain(['auto_renew' => false]);
        AdvFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2026-11-01', status: DomainStatus::Grace, rawStatus: 'pendingDelete', graceEndsOn: '2026-11-16');

        $line = app(DomainRenewalService::class)->renewNow($domain, $this->staff());

        $this->assertSame(['2026-11-01', '2028-11-01', '30.00'], [$line->period_start->toDateString(), $line->period_end->toDateString(), $this->amount($line)]);
        $this->assertSame([['renew', 2]], AdvFakeGrRegistrar::$charges);
        $this->assertSame([DomainStatus::Active, '2028-11-01'], [$domain->fresh()->status, $domain->fresh()->expires_at->toDateString()]);

        $line = $this->pay($line);
        $this->assertSame(DomainRenewalService::ADOPTED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([['renew', 2]], AdvFakeGrRegistrar::$charges, 'never a second charge');
    }

    public function test_gr_renew_now_with_an_odd_lack_charges_nothing(): void
    {
        $domain = $this->grDomain(['status' => DomainStatus::Active, 'expires_at' => '2026-11-20']);
        $this->bill($domain); // 2026-11-20 → 2028-11-20, unpaid
        // The registry shows a year more than here: one year lacking for the two billed.
        AdvFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2027-11-20', status: DomainStatus::Active, rawStatus: 'ok');

        try {
            app(DomainRenewalService::class)->renewNow($domain, $this->staff());
            $failed = false;
        } catch (RuntimeException $e) {
            $failed = true;
            $this->assertStringContainsString('steps of 2', $e->getMessage());
        }
        $this->assertTrue($failed, 'one year is never asked of the .gr registry');
        $this->assertSame([], AdvFakeGrRegistrar::$charges);
    }

    // ── adopter ───────────────────────────────────────────────────────────

    public function test_gr_adopter_takes_an_active_name_and_refuses_one_in_its_grace(): void
    {
        $this->grTld();
        AdvFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2026-11-01', status: DomainStatus::Grace, rawStatus: 'pendingDelete', graceEndsOn: '2026-11-16');
        try {
            app(DomainAdopter::class)->adopt('example.gr', $this->user->client(), $this->gr, 'EUR');
            $this->fail('a name in its grace is not adopted');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('pendingDelete', $e->getMessage());
        }

        AdvFakeGrRegistrar::$record = new DomainSyncResult(expiresAt: '2028-11-01', status: DomainStatus::Active, rawStatus: 'ok');
        $domain = app(DomainAdopter::class)->adopt('example.gr', $this->user->client(), $this->gr, 'EUR');
        $this->assertSame([DomainStatus::Active, '2028-11-01'], [$domain->status, $domain->expires_at->toDateString()]);
    }
}

/** The .gr registry in memory: renews in grace, in steps of two years; never restores. */
class AdvFakeGrRegistrar extends GrEppRegistrar
{
    public static ?DomainSyncResult $record = null;

    /** @var list<array{0: string, 1: int}> */
    public static array $charges = [];

    public function key(): string
    {
        return 'advfakegr';
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
        if ($years % 2 !== 0) {
            throw new RegistrarRefused("{$years} years: only even years");
        }
        self::$charges[] = ['renew', $years];
        $expires = Carbon::parse((string) (self::$record?->expiresAt ?? $domain->expires_at?->toDateString()))->addYears($years)->toDateString();

        return self::$record = new DomainSyncResult(expiresAt: $expires, status: DomainStatus::Active, rawStatus: 'ok');
    }

    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$charges[] = ['restore', 1];

        throw new RegistrarRefused('no restore here');
    }
}
