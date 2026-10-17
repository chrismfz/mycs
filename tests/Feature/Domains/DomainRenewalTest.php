<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Jobs\Domains\RenewDomainJob;
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
use App\Services\Domains\DomainRenewalService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\RetryModuleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Domains D5b-2 (docs/domains.md D-1, D-2, D-13, D-15, D-17): a paid renewal done at its registrar, off the payment.
 * The registrar first (a covered period is adopted, never charged twice), once per invoice (an unknown outcome is not
 * asked again blindly), restore in quarantine, Pending Module Actions for whatever needs staff, «Renew now».
 */
class DomainRenewalTest extends TestCase
{
    use RefreshDatabase;

    private const PROD = 'https://api.openprovider.eu';

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> the registrar's record of the domain */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> the charges asked: [path, body] */
    private array $charges = [];

    private ?string $failWith = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Openprovider: GET returns the record; renew and restore change it, as the live registrar does. */
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
                $this->charges[] = [$path, $request->data()];
                if ($this->failWith === 'timeout') {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }
                if ($this->failWith !== null) {
                    return Http::response(['code' => 399, 'desc' => $this->failWith], 400);
                }
                $years = str_ends_with($path, '/restore') ? 1 : (int) $request->data()['period'];
                $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');
                $this->record['status'] = 'ACT';
                unset($this->record['soft_quarantine_expiry_date'], $this->record['hard_quarantine_expiry_date']);

                return Http::response(['data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
        });
    }

    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([1 => '23.33', 2 => '46.66'] as $years => $price) {
            DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** Its renewal billed by the cron and paid; returns the line. */
    private function billAndPay(Domain $domain): InvoiceItem
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return $line->fresh();
    }

    private function renew(InvoiceItem $line): string
    {
        return app(DomainRenewalService::class)->renewLine($line->id);
    }

    public function test_a_payment_hands_the_renewal_to_a_job_which_renews_it_once(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        Queue::assertPushed(RenewDomainJob::class, fn (RenewDomainJob $job) => $job->lineId === $line->id);

        $this->assertSame(DomainRenewalService::APPLIED, $this->renew($line));
        $this->assertSame(DomainRenewalService::NOTHING, $this->renew($line), 'applied: never a second charge');

        $this->assertCount(1, $this->charges);
        $this->assertSame(1, $this->charges[0][1]['period']);
        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertSame('2027-11-01', $domain->fresh()->expires_at->toDateString());
        $log = DomainRegistrarLog::query()->where('action', 'renew')->sole();
        $this->assertSame([DomainRegistrarLog::STATUS_OK, $line->invoice_id], [$log->status, $log->invoice_id]);
    }

    public function test_a_period_the_registrar_already_covers_is_adopted_never_charged(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->record['expiration_date'] = '2027-11-01 10:00:00'; // renewed elsewhere

        $this->assertSame(DomainRenewalService::ADOPTED, $this->renew($line));

        $this->assertSame([], $this->charges);
        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertSame(DomainRegistrarLog::STATUS_ADOPTED, DomainRegistrarLog::query()->sole()->status);
    }

    public function test_an_unknown_outcome_is_never_asked_again_blindly(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->failWith = 'timeout';

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->failWith = null;
        $this->assertSame(DomainRenewalService::WAITING, $this->renew($line), 'pending: the next run looks again, no charge');
        $this->assertCount(1, $this->charges);
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status);

        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->assertCount(1, $this->charges, 'still never charged again: staff check the registrar');
        $this->assertSame(1, ModuleAction::query()->open()->where('domain_id', $domain->id)->count());
    }

    public function test_a_refusal_is_a_pending_action_staff_retry(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->failWith = 'Insufficient funds';

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();
        $this->assertSame([ModuleAction::DOMAIN_RENEW, $line->id], [$action->action, $action->invoice_item_id]);
        $this->assertStringContainsString('Insufficient funds', $action->last_error);
        $this->assertNull($line->fresh()->applied_at);
        $this->assertSame(Invoice::STATUS_PAID, $line->invoice->fresh()->status, 'the payment never fails (D-15)');

        $this->failWith = null;
        $this->assertTrue(RetryModuleAction::run($action));
        $this->assertSame(ModuleAction::STATUS_COMPLETED, $action->fresh()->status);
        $this->assertNotNull($line->fresh()->applied_at);
    }

    public function test_in_quarantine_it_is_restored_and_the_other_paid_years_renewed(): void
    {
        $domain = $this->domain(['years' => 2]);
        $line = $this->billAndPay($domain);
        $this->record = array_merge($this->record, ['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-12-11 10:00:00']);

        $this->assertSame(DomainRenewalService::APPLIED, $this->renew($line));

        $this->assertSame(['/v1beta/domains/30391502/restore', '/v1beta/domains/30391502/renew'], array_column($this->charges, 0));
        $this->assertSame(1, $this->charges[1][1]['period']);
        $this->assertSame([DomainStatus::Active, '2028-11-01'], [$domain->fresh()->status, $domain->fresh()->expires_at->toDateString()]);
    }

    public function test_a_manual_registrar_waits_for_staff_who_renew_it_by_hand(): void
    {
        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain(['registrar_connection_id' => $manual->id]);
        $line = $this->billAndPay($domain);

        $this->assertSame(DomainRenewalService::WAITING, $this->renew($line));
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
        $action->markResolved($staff);

        $this->assertSame([], $this->charges);
        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertSame('2027-11-01', $domain->fresh()->expires_at->toDateString());
    }

    public function test_the_cron_renews_a_paid_renewal_whose_job_was_lost(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain); // the job is faked: never run

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertCount(1, $this->charges);
    }

    public function test_renew_now_bills_renews_and_the_later_payment_adopts_it(): void
    {
        $domain = $this->domain(['expires_at' => '2026-12-20']);
        $this->record['expiration_date'] = '2026-12-20 10:00:00';
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);

        $line = app(DomainRenewalService::class)->renewNow($domain, $staff);

        $this->assertCount(1, $this->charges);
        $this->assertSame(['2026-12-20', '2027-12-20', Invoice::STATUS_UNPAID], [$line->period_start->toDateString(), $line->period_end->toDateString(), $line->invoice->status]);
        $this->assertSame('2027-12-20', $domain->fresh()->expires_at->toDateString());

        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-later');
        $this->assertSame(DomainRenewalService::ADOPTED, $this->renew($line));
        $this->assertCount(1, $this->charges, 'the payment renewed nothing again (D-1)');
    }
}
