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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Nixpal\DomainTools\Whois\SocketConnector;
use Tests\TestCase;

/**
 * Round-3 (final combined) review of #537 (a paid multi-year renewal at CNIC transferred to Openprovider, the rest bought at
 * the target later): the round-2 fixes (the hold narrowed, Retry past it, RDAP read at the Retry) tried to break.
 * The harness is RenewAsTransferTest's.
 */
class RenewAsTransferMultiYearRound3Test extends TestCase
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
     * ADV-537-R3-1: staff's Retry goes past the R537-2 hold «regardless» (R2-1 of #537), also while the transfer still
     * needs its years. Three years paid (2026-11-01 .. 2029-11-01), the transfer done at the target with its one year
     * (2027-11-01), inside the 24 hours before its line is opened again. A later paid year (2029-11-01 .. 2030-11-01) has a
     * row of its own (it was paid while the transfer was pending: «staff decide»), and staff press Retry on it: it buys its
     * one year from the short expiry (2028-11-01), the earlier line is then opened with the registry at 2028-11-01 and
     * buys only one. Four years paid, three there: the R537-2 loss, back through staff's Retry. The narrowed hold already
     * lets a refunded, deleted or done line go; Retry need not bypass a live one.
     */
    public function test_adv_537_r3_1_staffs_retry_of_a_later_line_never_takes_the_transfers_years(): void
    {
        [$domain, $line] = $this->paidRenewal(years: 3); // 2026-11-01 .. 2029-11-01
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id), 'transfer sent');

        // A later year paid while the transfer is pending: staff's (pending transfer at the target).
        $next = $this->paidLine($domain->refresh(), '2029-11-01', '2030-11-01');
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($next->id));
        $this->assertTrue(ModuleAction::query()->open()->where('invoice_item_id', $next->id)->exists(), 'its row, with Retry');

        // The transfer done at the target, its one year there (the registry too): its line waits 24 hours.
        $this->opStatus = 'ACT';
        $this->opExpiry = '2027-11-01 00:00:00';
        $this->rdapExpiry = '2027-11-01T00:00:00Z';
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertNotEmpty($domain->fresh()->meta['renew_transfer']['done_at'] ?? null);
        $this->assertSame(DomainRenewalService::WAITING, app(DomainRenewalService::class)->renewLine($next->id), 'the cron: held');

        // Staff press Retry on the later line's row.
        app(DomainRenewalService::class)->renewLine($next->id, retry: true);

        // The registry follows the target; the earlier line is opened again and renewed, as the cron does.
        $this->rdapExpiry = Carbon::parse($this->opExpiry)->toIso8601ZuluString();
        Carbon::setTestNow('2026-10-22 11:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainRenewalService::class)->renewLine($line->id);
        app(DomainRenewalService::class)->renewLine($next->id);
        app(DomainSyncService::class)->sync($domain->refresh());

        $this->assertSame(3, array_sum($this->opRenewedYears),
            'four years paid, one added by the transfer: three bought at the target, got ' . json_encode($this->opRenewedYears)
            . ', the target at ' . $this->opExpiry . ' for a period paid to 2030-11-01');
    }

    /**
     * ADV-537-R3-2: the hold applies to every transfer that replaced a renewal, one year too (new in #537). The target
     * lags its own transfer (it shows 2026-11-01) while the registry has the year (2027-11-01): renewAfterTransfer waits
     * for the target («the next sync lands it»), never opening the line nor telling staff. A later paid year is then held
     * by the hold with nothing open for it, for as long as the target lags: here past the expiry the target shows. Before
     * #537 it was renewed (one year, from the target). The rule of R2-3 / code review 1 of #305: never held with nothing
     * open.
     */
    public function test_adv_537_r3_2_a_later_line_is_never_held_with_nothing_open_while_the_target_lags(): void
    {
        [$domain, $line] = $this->paidRenewal(); // one year, 2026-11-01 .. 2027-11-01
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id), 'transfer sent');
        $this->opStatus = 'ACT';
        $this->opExpiry = '2026-11-01 00:00:00'; // the target lags its transfer
        $this->rdapExpiry = '2027-11-01T00:00:00Z'; // the registry has the year
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertNotEmpty($domain->fresh()->meta['renew_transfer']['done_at'] ?? null);

        $next = $this->paidLine($domain->refresh(), '2027-11-01', '2028-11-01');
        foreach (['2026-10-23', '2026-10-26', '2026-10-30', '2026-11-05', '2026-11-15'] as $day) {
            Carbon::setTestNow("{$day} 11:00:00");
            app(DomainSyncService::class)->sync($domain->refresh());
            app(DomainRenewalService::class)->renewLine($next->id);
        }

        // A one-year transfer holds nothing (the fix: it brings its year itself), so the later year renews and the record
        // settles; what matters is that the paid year is never held with nothing open.
        $this->assertTrue($next->fresh()->applied_at !== null || ModuleAction::query()->open()->where('invoice_item_id', $next->id)->exists(),
            'a paid year held for three weeks, past the expiry the target shows, with nothing open for staff');
    }
}
