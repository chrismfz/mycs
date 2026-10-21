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
use Throwable;

/**
 * Domain sweep (2026-10-22), the paid-renewal path: DomainRenewalService (renewLine, renewNow, apply), RenewAsTransfer
 * and the ModuleAction close. Every test here FAILS on e3b94471 and shows one defect; see the report for fixes.
 */
class DomainSweepRenewalTest extends TestCase
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
     * F1 (money). «Renew now» charges the registrar for every year its expiry lacks to reach the end of the billed
     * period, never bounded by the years billed (renewLocked bounds it by paid − done; renewNow does not). Ours
     * 2026-11-10, the registrar's 2026-11-01 (a sync never moves it back on Openprovider): the line bills 1 year
     * (2026-11-10 → 2027-11-10), the registrar is charged 2 years, and the later payment adopts it.
     */
    public function test_f1_renew_now_charges_more_years_than_it_bills(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain(['expires_at' => '2026-11-10']);

        $line = app(DomainRenewalService::class)->renewNow($domain, $this->staff());

        $this->assertSame(1, (int) round($line->period_start->diffInYears($line->period_end)), 'one year billed');
        $this->assertSame(1, $this->chargedYears(), 'the registrar is charged for the years billed, never more');
    }

    /**
     * F2 (money). A paid line applied «short» (charged, the registrar's expiry lagging: staff told «Renewed, but the
     * registrar shows …») is still found by «Renew now» for the same period (billedLine: paid invoices count), and
     * charged again: renewNow never looks at chargedYears() nor at applied lines. Staff pressing «Renew now» after that
     * row is the natural reaction.
     */
    public function test_f2_renew_now_after_a_lagging_paid_renewal_charges_the_same_period_again(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->lag = true;
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertCount(1, $this->charges);

        try {
            app(DomainRenewalService::class)->renewNow($domain->refresh(), $this->staff());
        } catch (Throwable) {
            // refusing is fine
        }

        $this->assertCount(1, $this->charges, 'the period paid and charged already is never charged again by «Renew now»');
    }

    /**
     * F3 (expiry). Two consecutive paid lines, A (2026-11-01 → 2027) and B (2027 → 2028), the later one run first (its
     * payment's job ran first, or the cron's order): B lacks 2 years, is charged min(2, 1) = 1 and applied «short»; A
     * then finds 2027 covered and is adopted. Two years paid, one charged: the name expires 2027-11-01. The class doc
     * says «two paid periods, in any order, get exactly the years paid».
     */
    public function test_f3_two_paid_periods_run_later_first_charge_only_one_year(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $a = $this->billAndPay($domain);
        $b = $this->lineFor($domain, '2027-11-01', '2028-11-01');
        $this->pay($b->invoice);

        app(DomainRenewalService::class)->renewLine($b->id);
        app(DomainRenewalService::class)->renewLine($a->id);

        $this->assertSame(2, $this->chargedYears(), 'two years paid, two charged');
        $this->assertSame('2028-11-01', substr((string) $this->record['expiration_date'], 0, 10));
    }

    /**
     * F6 (money). «Renew now» guards «a second year on credit» only for an UNPAID invoice (ADV-6). A renewal line on a
     * DRAFT invoice (an open status: Invoice::OPEN_STATUSES) is used by billedOrBill and charged; a second «Renew now»
     * passes the guard, bills the next period and charges again: two years at the registrar, nothing paid.
     */
    public function test_f6_renew_now_twice_over_a_draft_invoice_charges_two_years_on_credit(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $this->lineFor($domain, '2026-11-01', '2027-11-01', Invoice::STATUS_DRAFT);
        $staff = $this->staff();

        app(DomainRenewalService::class)->renewNow($domain, $staff);
        try {
            app(DomainRenewalService::class)->renewNow($domain->refresh(), $staff);
        } catch (Throwable) {
            // refused: right
        }

        $this->assertCount(1, $this->charges, 'renewed now once, its invoice not paid: not a second year on credit');
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
