<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
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
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Domain sweep (2026-10-22), the paid-renewal path: DomainRenewalService (renewLine, renewNow, apply), RenewAsTransfer
 * and the ModuleAction close. Every test here FAILS on e3b94471 and shows one defect; see the report for fixes.
 */
class DomainSweepSettleTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> Openprovider's record of the domain */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> Openprovider charges asked: [path, body] */
    private array $charges = [];

    private ?string $failWith = null;

    /** The registrar takes the charge but its expiry lags (shows the old one for a while). */
    private bool $lag = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- Openprovider-only fixtures (as DomainRenewalTest) ----

    private function fakeOp(array $record): void
    {
        $this->record = $record + ['id' => 30391502, 'domain' => ['name' => 'example', 'extension' => 'com'],
            'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']]];
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
                if (!$this->lag) {
                    $years = str_ends_with($path, '/restore') ? 1 : (int) $request->data()['period'];
                    $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');
                }
                $this->record['status'] = 'ACT';

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
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

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function billAndPay(Domain $domain): InvoiceItem
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->firstOrFail();
        $this->pay($line->invoice);

        return $line->fresh();
    }

    private function pay(Invoice $invoice): void
    {
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'tx-' . $invoice->id);
    }

    /** A renewal line for [$from, $to) on an invoice of its own, unpaid. */
    private function lineFor(Domain $domain, string $from, string $to, string $status = Invoice::STATUS_UNPAID): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => $status, 'due_at' => today(), 'currency_code' => 'EUR']);

        return $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => "example.com {$from} - {$to}", 'period_start' => $from, 'period_end' => $to]);
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function chargedYears(): int
    {
        return (int) collect($this->charges)->sum(fn ($c) => str_ends_with($c[0], '/restore') ? 1 : (int) ($c[1]['period'] ?? 0));
    }

    /**
     * F4 (money/staff). apply() settles EVERY pending renew log of the domain as OK («shown by the registrar»), not only
     * those the registrar's expiry now covers. B (2027 → 2028) charged, no answer, not done (pending); a second paid
     * line for A's period (the case DomainsD5b2 holds: «two paid lines for the same period») is adopted; B's pending log
     * becomes OK, chargedYears(B) = 1, and B is applied without any year at the registrar.
     */
    public function test_f4_adopting_one_line_settles_another_lines_unconfirmed_charge_as_done(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $a = $this->billAndPay($domain);
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($a->id)); // 2027-11-01
        $b = $this->lineFor($domain, '2027-11-01', '2028-11-01');
        $this->pay($b->invoice);
        $this->failWith = 'timeout';
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($b->id)); // pending, not done
        $this->failWith = null;
        // A second paid line for A's period (paid twice): adopted, the registrar shows 2027.
        $dup = $this->lineFor($domain, '2026-11-01', '2027-11-01');
        $this->pay($dup->invoice);
        app(DomainRenewalService::class)->renewLine($dup->id);
        $settled = DomainRegistrarLog::query()->where('action', 'renew')->where('request->line', $b->id)->sole()->status;
        app(DomainRenewalService::class)->renewLine($b->id);

        $this->assertFalse($settled === DomainRegistrarLog::STATUS_OK && $b->fresh()->applied_at !== null
            && substr((string) $this->record['expiration_date'], 0, 10) === '2027-11-01',
            "B's unknown charge settled «{$settled}» by another line's adoption, B applied, the registrar still at 2027-11-01: B's year never bought");
    }

    /**
     * F5 (staff/money). «Close (not renewed)» on a refused renewal: «Closes it without renewing … a later Retry may
     * charge». The line is still paid and unapplied and refusedFor() needs an OPEN row, so the cron's next run charges
     * it on its own, minutes later, with no Retry.
     */
    public function test_f5_close_not_renewed_is_renewed_by_the_next_cron_run_anyway(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->failWith = 'Domain is not renewable: clientRenewProhibited';
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        ModuleAction::query()->open()->where('domain_id', $domain->id)->sole()->closeUnrenewed($this->staff());
        $this->failWith = null;

        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertCount(1, $this->charges, 'closed «not renewed» by staff: never charged by the cron on its own');
        $this->assertNull($line->fresh()->applied_at);
    }

    // ---- RenewAsTransfer: CNIC → Openprovider (as RenewAsTransferTest) ----

    /** @var list<string> CNIC commands */
    private array $cnic = [];

    private bool $cnicLocked = true;

    private string $opStatus = 'REQ';

    private int $opTransfers = 0;

    private function cnicAnswer(Request $request)
    {
        parse_str($request->body(), $form);
        $command = (string) ($form['s_command'] ?? '');
        $this->cnic[] = $command;
        if (str_starts_with($command, 'COMMAND=ModifyDomain')) {
            if (str_contains($command, 'TRANSFERLOCK=0')) {
                $this->cnicLocked = false;
            } elseif (str_contains($command, 'TRANSFERLOCK=1')) {
                $this->cnicLocked = true;
            }

            return Http::response("[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n");
        }
        if (str_starts_with($command, 'COMMAND=RenewDomain')) {
            return Http::response("[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n");
        }
        $lock = $this->cnicLocked ? 1 : 0;

        return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\nproperty[PAIDUNTIL DATE][0] = 2026-11-01 00:00:00.0\n"
            . "property[STATUS][0] = ACTIVE\nproperty[TRANSFER LOCK][0] = {$lock}\nproperty[NAMESERVER][0] = ns1.example.net\nproperty[NAMESERVER][1] = ns2.example.net\n"
            . "property[OWNER CONTACT][0] = P-CNIC1\nproperty[AUTH][0] = Epp-Code-123\nEOF\n");
    }

    private function opAnswer(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $record = ['id' => 9001, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => $this->opStatus,
            'expiration_date' => '2027-11-01 00:00:00', 'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']],
            'owner_handle' => 'MP000001-GR', 'admin_handle' => 'MP000001-GR', 'tech_handle' => 'MP000001-GR', 'billing_handle' => 'MP000001-GR'];

        return match (true) {
            str_contains($path, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => Http::response(['data' => ['handle' => 'MP000001-GR']]),
            $request->method() === 'POST' && str_ends_with($path, '/domains/transfer') => (function () {
                $this->opTransfers++;

                return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => 'REQ']]);
            })(),
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->opTransfers === 0 ? [] : [$record],
                'total' => $this->opTransfers === 0 ? 0 : 1] + ($this->opTransfers === 0 ? [] : $record)]),
            default => Http::response([], 404),
        };
    }
}
