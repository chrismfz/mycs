<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * PR #211 adversarial review (Openprovider /v1, an error code in a 200): a paid renewal and the sync, against
 * Openprovider answering 2xx with an error code, or 2xx without its envelope at all (a proxy's maintenance page).
 * Tests named `guard_` pass on the PR (kept as guards); the others fail on it.
 */
class Pr211AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $charges = [];

    /** How the renew POST is answered: null = renewed; 'code' = 200 + code 399; 'html' = 200 without the envelope. */
    private ?string $renewAnswer = null;

    /** How the by-name search is answered: null = the record; 'code' = 200 + code ≠ 0; 'html' = 200 without the envelope. */
    private ?string $searchAnswer = null;

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
        $this->record = ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2026-11-01 10:00:00', 'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = (string) parse_url($request->url(), PHP_URL_QUERY);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['code' => 0, 'data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/renew')) {
                $this->charges[] = [$path, $request->data()];
                if ($this->renewAnswer === 'code') {
                    return Http::response(['code' => 399, 'desc' => 'Domain cannot be renewed', 'data' => []], 200);
                }
                if ($this->renewAnswer === 'html') {
                    return Http::response('<html><body>Scheduled maintenance</body></html>', 200, ['Content-Type' => 'text/html']);
                }
                $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears((int) $request->data()['period'])->format('Y-m-d H:i:s');

                return Http::response(['code' => 0, 'data' => ['status' => 'ACT']]);
            }
            if ($request->method() === 'GET' && str_contains($query, 'full_name=')) {
                if ($this->searchAnswer === 'code') {
                    return Http::response(['code' => 10001, 'desc' => 'Internal error', 'data' => []], 200);
                }
                if ($this->searchAnswer === 'html') {
                    return Http::response('<html><body>Scheduled maintenance</body></html>', 200, ['Content-Type' => 'text/html']);
                }

                return Http::response(['code' => 0, 'data' => ['results' => [$this->record], 'total' => 1]]);
            }

            return Http::response(['code' => 0, 'data' => $this->record]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR'], ['price' => '23.33']);

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

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

    /** GUARD (passes): a renew answered 200 + code ≠ 0 is «maybe done»: pending, staff told, never asked again blindly. */
    public function test_guard_a_renew_answered_200_with_an_error_code_is_pending_and_never_charged_again(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->renewAnswer = 'code';

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->assertNull($line->fresh()->applied_at, 'an error answer is not a renewal');
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status);
        $this->assertSame(1, ModuleAction::query()->open()->where('domain_id', $domain->id)->count());

        $this->renewAnswer = null;
        $this->assertSame(DomainRenewalService::WAITING, $this->renew($line));
        $this->assertCount(1, $this->charges, 'never a second charge over an unknown outcome');
    }

    /**
     * ADV-1 (fails): a renew answered 200 WITHOUT Openprovider's envelope (a proxy's or CDN's maintenance page, an empty
     * body) is taken as done: logged OK, its years counted as charged, the paid line applied and never asked again,
     * though the registrar renewed nothing. The PR's rule («code ≠ 0 is an error whatever the status») leaves out the
     * answer with no code at all; packages/myopenprovider (same PR) already treats it as unknown, «maybe done».
     */
    public function test_a_renew_answered_200_without_the_envelope_is_not_taken_as_done(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->renewAnswer = 'html';

        $this->renew($line);

        $this->assertNotSame(DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status,
            'a 200 that is not Openprovider\'s answer is no proof of a renewal: not «charged»');
        $this->assertNull($line->fresh()->applied_at, 'the paid renewal is not closed on a maintenance page');
    }

    /** GUARD (passes): the sync's by-name read answered 200 + code ≠ 0 is a sync error, never «not in the account». */
    public function test_guard_a_sync_read_answered_200_with_an_error_code_is_a_sync_error_not_not_ours(): void
    {
        $domain = $this->domain(['registrar_domain_id' => null]);
        $this->searchAnswer = 'code';

        try {
            app(DomainSyncService::class)->sync($domain);
            $this->fail('an error answer was read as the registrar\'s record');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(DomainNotFoundAtRegistrar::class, $e);
        }
        $domain->refresh();
        $this->assertStringContainsString('Internal error', (string) $domain->sync_error);
        $this->assertArrayNotHasKey('not_ours', $domain->meta ?? []);
    }

    /** GUARD (passes; already so since ADV-4 of #203): the same read answered 200 without the envelope is «could not read», never «not ours». */
    public function test_guard_a_sync_read_answered_200_without_the_envelope_is_not_not_ours(): void
    {
        $domain = $this->domain(['registrar_domain_id' => null]);
        $this->searchAnswer = 'html';

        try {
            app(DomainSyncService::class)->sync($domain);
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(DomainNotFoundAtRegistrar::class, $e, 'a maintenance page is not «not in the account»');
        }
        $this->assertArrayNotHasKey('not_ours', $domain->fresh()->meta ?? []);
    }
}
