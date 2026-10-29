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
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Nixpal\DomainTools\Whois\SocketConnector;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #537 (a paid multi-year renewal at CNIC transferred to Openprovider, the rest bought at
 * the target later): the round-1 fixes (the registry's cap, the later line held, the deferred «renewed») tried to break.
 * The harness is RenewAsTransferTest's.
 */
class RenewAsTransferMultiYearRound2Test extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> CNIC commands */
    private array $cnic = [];

    /** @var list<array{0: string, 1: array}> Openprovider POSTs */
    private array $op = [];

    private ?string $auth = 'Epp-Code-123';

    /** The registry's expiry (RDAP), as it shows the transfer: null, not readable. */
    private ?string $rdapExpiry = '2026-11-01T00:00:00Z';

    /** null: takes it; 'refuse': 4xx; 'down': 5xx */
    private ?string $target = null;

    private DomainRegistrarConnection $from;

    private DomainRegistrarConnection $to;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Cache::flush();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        $this->app->bind(SocketConnector::class, fn () => new class implements SocketConnector
        {
            public function query($host, $query, $timeout = 8, $max = 65536)
            {
                return null;
            }
        });
        Http::fake(fn (Request $request) => str_contains($request->url(), 'rrpproxy') ? $this->cnicAnswer($request) : $this->opAnswer($request));
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->to = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->to->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->from = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production',
            'renew_transfer_to_id' => $this->to->id]);
        $this->from->storeCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], ['password']);
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cnicAnswer(Request $request)
    {
        parse_str($request->body(), $form);
        $command = (string) ($form['s_command'] ?? '');
        $this->cnic[] = $command;
        if (str_starts_with($command, 'COMMAND=RenewDomain') || str_starts_with($command, 'COMMAND=ModifyDomain')) {
            return Http::response("[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n");
        }
        $auth = $this->auth !== null ? "property[AUTH][0] = {$this->auth}\n" : '';

        return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\nproperty[PAIDUNTIL DATE][0] = 2026-11-01 00:00:00.0\n"
            . "property[STATUS][0] = ACTIVE\nproperty[TRANSFER LOCK][0] = 1\nproperty[NAMESERVER][0] = ns1.example.net\nproperty[NAMESERVER][1] = ns2.example.net\n"
            . "property[OWNER CONTACT][0] = P-CNIC1\n{$auth}EOF\n");
    }

    private function opAnswer(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_contains($path, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => Http::response(['data' => ['handle' => 'MP000001-GR']]),
            $request->method() === 'POST' && str_ends_with($path, '/domains/transfer') => $this->opTransfer($path, $request->data()),
            $request->method() === 'POST' && str_ends_with($path, '/renew') => $this->opRenew($request->data()),
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->op === [] ? [] : [$this->record()], 'total' => $this->op === [] ? 0 : 1] + ($this->op === [] ? [] : $this->record())]),
            str_contains($request->url(), 'data.iana.org') => Http::response(['services' => [[['com', 'eu', 'uk'], ['https://rdap.example/']]]]),
            str_contains($request->url(), 'rdap.example/domain/') => $this->rdapExpiry === null ? Http::response([], 503)
                : Http::response(['objectClassName' => 'domain', 'ldhName' => 'example.com', 'events' => [['eventAction' => 'expiration', 'eventDate' => $this->rdapExpiry]]]),
            default => Http::response([], 404),
        };
    }

    private string $opStatus = 'REQ';

    private string $opExpiry = '2027-11-01 00:00:00';

    private function record(): array
    {
        return ['id' => 9001, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => $this->opStatus,
            'expiration_date' => $this->opExpiry, 'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']],
            'owner_handle' => 'MP000001-GR', 'admin_handle' => 'MP000001-GR', 'tech_handle' => 'MP000001-GR', 'billing_handle' => 'MP000001-GR'];
    }

    /** @return list<string> */
    private function mails(string $template): array
    {
        return Mail::queued(\App\Mail\Mail::class)->filter(fn ($mail) => $mail->templateKey === $template)->map(fn ($mail) => $mail->templateKey)->values()->all();
    }

    private int $opRenewals = 0;

    /** @var list<int> the years each renewal at the target asked for */
    private array $opRenewedYears = [];

    /** The target refuses its renewals. */
    private bool $opRenewRefuse = false;

    private function opRenew(array $data = [])
    {
        if ($this->opRenewRefuse) {
            return Http::response(['code' => 399, 'desc' => 'Renewal refused'], 400);
        }
        $this->opRenewals++;
        $years = max(1, (int) ($data['period'] ?? 1));
        $this->opRenewedYears[] = $years;
        $this->opExpiry = Carbon::parse($this->opExpiry)->addYears($years)->format('Y-m-d H:i:s');

        return Http::response(['code' => 0, 'data' => ['status' => 'ACT']]);
    }

    private function opTransfer(string $path, array $data)
    {
        if ($this->target === 'refuse') {
            return Http::response(['code' => 399, 'desc' => 'Invalid auth code'], 400);
        }
        if ($this->target === 'down') {
            return Http::response([], 503);
        }
        $this->op[] = [$path, $data];

        return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => 'REQ']]);
    }

    /** @return array{0: Domain, 1: InvoiceItem} */
    private function paidRenewal(string $tld = 'com', int $years = 1): array
    {
        $domainTld = DomainTld::query()->create(['tld' => $tld, 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);
        DomainTldPrice::query()->create(['domain_tld_id' => $domainTld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR', 'price' => '23.33']);
        $user = User::factory()->create();
        $contact = $user->client()->ensurePrimaryContact();
        $contact->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test', 'phone' => '+30.2100000000',
            'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'example', 'tld' => $tld, 'fqdn' => "example.{$tld}",
            'domain_tld_id' => $domainTld->id, 'registrar_connection_id' => $this->from->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'auto_renew' => true, 'years' => $years, 'registrant_contact_id' => $contact->id, 'registrant_handle' => 'P-CNIC1',
            'meta' => ['contact_handles' => [$contact->id => 'P-CNIC1']]]);
        $domain->nameservers()->create(['host' => 'ns1.example.net', 'sort' => 1]);
        $domain->nameservers()->create(['host' => 'ns2.example.net', 'sort' => 2]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return [$domain, $line->fresh()];
    }

    private function sent(string $command): int
    {
        return collect($this->cnic)->filter(fn ($c) => str_starts_with($c, "COMMAND={$command}"))->count();
    }

    /** Transferred (3 years paid, one added by the transfer), done at the target short, and its line opened again. */
    private function reopened(?string $rdap = '2027-11-01T00:00:00Z', string $opExpiry = '2027-11-01 00:00:00'): array
    {
        [$domain, $line] = $this->paidRenewal(years: 3); // 2026-11-01 .. 2029-11-01
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->opStatus = 'ACT';
        $this->opExpiry = $opExpiry;
        $this->rdapExpiry = $rdap;
        $sync = app(DomainSyncService::class);
        $sync->sync($domain->refresh());
        Carbon::setTestNow('2026-10-22 11:00:00');
        $sync->sync($domain->refresh());
        $this->assertNull($line->fresh()->applied_at, 'opened again');
        $this->assertNotEmpty($domain->fresh()->meta['renew_transfer']['reopened_at'] ?? null);

        return [$domain->fresh(), $line->fresh()];
    }

    /** A later renewal of the domain, paid. */
    private function paidLine(Domain $domain, string $start, string $end): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-22']);
        $next = $invoice->items()->create(['description' => 'Renewal', 'price' => '23.33', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => $start, 'period_end' => $end]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, (float) $invoice->fresh()->total, transactionId: 'tx-next-' . $next->id);

        return $next->fresh();
    }

    /**
     * ADV-537-R2-1: the line opened again after its transfer is refunded (the customer stops after the transfer's year;
     * or its invoice deleted). Nothing settles meta.renew_transfer any more: renewAfterTransfer skips a record opened
     * again, settleTransferRecord runs only when that line is applied, and it no longer waits. The R537-2 hold (done_at,
     * another line) then holds every later paid renewal of the domain for good: the customer pays a year from 2027-11-01,
     * nothing is renewed, nothing is open for staff (told_at unset), staff's Retry does nothing, and even renewed by hand
     * at the target it is never adopted. «Renew now» is refused for good too (transfer_unsettled). The domain lapses paid.
     */
    public function test_adv_537_r2_1_a_refunded_reopened_line_never_holds_a_later_paid_renewal_for_good(): void
    {
        [$domain, $line] = $this->reopened();
        DB::table('invoices')->where('id', $line->invoice_id)->update(['status' => Invoice::STATUS_REFUNDED]); // as its refund left it
        $this->assertSame(DomainRenewalService::NOTHING, app(DomainRenewalService::class)->renewLine($line->id), 'refunded: not renewed');

        $next = $this->paidLine($domain, '2027-11-01', '2028-11-01'); // the customer renews again, one year from the target's expiry
        $outcome = app(DomainRenewalService::class)->renewLine($next->id);
        $open = ModuleAction::query()->open()->where('invoice_item_id', $next->id)->exists();
        $this->assertTrue($outcome === DomainRenewalService::APPLIED || $open,
            "a paid year neither renewed nor with staff: {$outcome}, bought " . json_encode($this->opRenewedYears));

        // Renewed by hand at the target, and staff's Retry: adopted at least then.
        $this->opExpiry = '2028-11-01 00:00:00';
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainRenewalService::class)->renewLine($next->id, retry: true);
        $this->assertNotNull($next->fresh()->applied_at, 'covered at the target: adopted');
    }

    /**
     * ADV-537-R2-2: the registry (RDAP) not readable when the line is opened again: staff's (staff_only, told_at), and no
     * registry_expiry stored. The target lags its own transfer (still 2026-11-01) while the registry has the transfer's
     * year (2027-11-01). Staff follow the row («check its registrar and the registry, then renew it there by hand (or
     * Retry)»): Retry has no cap, buys what the lagging target lacks (3) and the domain gets 4 years for 3 paid: the
     * R537-1 double purchase, back through staff's Retry. The registry could be read again at the Retry.
     */
    public function test_adv_537_r2_2_staffs_retry_without_a_registry_date_never_buys_the_transfer_year_again(): void
    {
        [$domain, $line] = $this->reopened(rdap: null, opExpiry: '2026-11-01 00:00:00');
        $this->assertTrue((bool) ($domain->meta['renew_transfer']['staff_only'] ?? false));
        $this->assertArrayNotHasKey('registry_expiry', $domain->meta['renew_transfer']);

        $this->rdapExpiry = '2027-11-01T00:00:00Z'; // readable again: the transfer's year is there
        app(DomainRenewalService::class)->renewLine($line->id, retry: true);
        $this->assertLessThanOrEqual(2, array_sum($this->opRenewedYears),
            'three paid, one added by the transfer: at most two bought, got ' . json_encode($this->opRenewedYears));
    }

    /**
     * ADV-537-R2-3: the line opened again is refused at the target (staff have its row). A later paid renewal of the
     * domain is held by the R537-2 hold, silently: told_at is set only by renewAfterTransfer, so the hold's «with staff»
     * branch is never taken and that paid period has nothing open for it (the rule of code review 1 of #305: never held
     * back with nothing open). Staff resolving the first row see nothing of the second.
     */
    public function test_adv_537_r2_3_a_later_line_held_behind_a_refused_reopened_line_is_said_to_staff(): void
    {
        [$domain, $line] = $this->reopened();
        $this->opRenewRefuse = true;
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertTrue(ModuleAction::query()->open()->where('invoice_item_id', $line->id)->exists(), 'the refused one is staff\'s');

        $next = $this->paidLine($domain, '2029-11-01', '2030-11-01');
        $this->assertSame(DomainRenewalService::WAITING, app(DomainRenewalService::class)->renewLine($next->id));
        $this->assertTrue(ModuleAction::query()->open()->where('invoice_item_id', $next->id)->exists(),
            'a paid period held behind a line staff have: said on this one too');
    }

    /** Guard (passes): three years, «renewed» never at acceptance, once when the reopened line is applied; never again. */
    public function test_guard_a_multi_year_renewed_is_told_once_after_its_years(): void
    {
        [$domain, $line] = $this->reopened();
        $this->assertSame([], $this->mails('domain_renewed'), 'not at acceptance');
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([2], $this->opRenewedYears);
        app(DomainSyncService::class)->sync($domain->refresh());
        Carbon::setTestNow('2026-10-24 11:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertCount(1, $this->mails('domain_renewed'));
        $this->assertArrayNotHasKey('renew_transfer', $domain->fresh()->meta);
    }

    /** Guard (passes): one year, told at acceptance, and never again when its transfer is done. */
    public function test_guard_one_year_is_told_at_acceptance(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertCount(1, $this->mails('domain_renewed'));
        $this->assertArrayNotHasKey('tell_later', $domain->fresh()->meta['renew_transfer']);
        $this->opStatus = 'ACT';
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertCount(1, $this->mails('domain_renewed'));
    }
}
