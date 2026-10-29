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
 * Adversarial review of #537 (R7 of #535): a paid multi-year renewal at CNIC transferred to Openprovider, the rest renewed
 * at the target afterwards (DomainSyncService::renewAfterTransfer). The threat: years bought twice, years paid and never
 * bought, or the customer told «renewed» with years missing. The fixture is RenewAsTransferTest's.
 */
class RenewAsTransferMultiYearAdversarialTest extends TestCase
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

    private function opRenew(array $data = [])
    {
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

    /** The transfer accepted and done at the target: Openprovider shows $opExpiry, the registry $rdap. */
    private function transferredAndDone(int $years, string $opExpiry, ?string $rdap): array
    {
        [$domain, $line] = $this->paidRenewal(years: $years);
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id), 'precondition: transferred');
        $this->assertCount(1, $this->op);
        $this->opStatus = 'ACT';
        $this->opExpiry = $opExpiry;
        $this->rdapExpiry = $rdap;
        app(DomainSyncService::class)->sync($domain->refresh());

        return [$domain, $line];
    }

    /**
     * ADV-537-1 (high). Three years paid; the transfer added its year at the registry (RDAP 2027-11-01), but Openprovider
     * lags and still shows the old expiry (2026-11-01) past the 24-hour wait: the very lag the one-year flow guards
     * against (test_a_registrar_lagging_past_the_wait_is_never_charged_when_the_registry_has_the_year). The registry lacks
     * two years, so the line is opened again; the renewal then counts from the target's lagging date and buys three
     * (min(lacking at the target = 3, paid − charged = 3): the transfer's year is never counted). The registry ends at
     * 2030-11-01, four years for three paid: the transfer's year bought twice.
     */
    public function test_adv_537_1_a_lagging_target_never_buys_the_year_the_transfer_added(): void
    {
        [$domain, $line] = $this->transferredAndDone(3, '2026-11-01 00:00:00', '2027-11-01T00:00:00Z');
        Carbon::setTestNow('2026-10-22 11:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertLessThanOrEqual(2, array_sum($this->opRenewedYears),
            'the registry has the transfer\'s year: two more are missing, never three (bought: [' . implode(',', $this->opRenewedYears) . '])');
    }

    /**
     * ADV-537-2 (high). The registry showing some but not all of the years. Three paid; the registry has two of them
     * (2028-11-01: e.g. CNIC's registry expiry ahead of its paid-until, plus the transfer's year), Openprovider shows
     * one (2027-11-01). renewAfterTransfer only asks «does the registry have them all?» (a yes/no made for one year): no,
     * so the line is opened again, and the renewal buys what the target lacks (2), not what the registry lacks (1). The
     * registry ends at 2030-11-01: a year bought twice.
     */
    public function test_adv_537_2_a_registry_with_some_of_the_years_is_charged_only_the_ones_it_lacks(): void
    {
        [$domain, $line] = $this->transferredAndDone(3, '2027-11-01 00:00:00', '2028-11-01T00:00:00Z');
        Carbon::setTestNow('2026-10-22 11:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertSame([1], $this->opRenewedYears, 'the registry lacks one year: one bought, never the two the lagging target lacks');
    }

    /**
     * ADV-537-3 (low). The customer told «renewed» with years missing. Three years paid; the customer is mailed «renewed
     * until 01/11/2029» the moment the transfer is accepted (DomainRenewalService: tellRenewed(period_end), «the year its
     * transfer adds»), while the transfer adds one year. The registry not readable at the wait's end: staff told, the cron
     * buys nothing. The customer holds a «renewed until 2029» mail for a name that expires 2027-11-01, and none follows if
     * staff close it. For one year the mail matched what the transfer adds; for more it does not.
     */
    public function test_adv_537_3_the_customer_is_never_told_years_that_were_not_bought(): void
    {
        [$domain, $line] = $this->transferredAndDone(3, '2027-11-01 00:00:00', null);
        Carbon::setTestNow('2026-10-22 11:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainRenewalService::class)->renewLine($line->id);
        $this->assertSame([], $this->opRenewedYears, 'precondition: staff\'s, nothing bought');
        $this->assertSame('2027-11-01', $domain->fresh()->expires_at->toDateString());

        $told = Mail::queued(\App\Mail\Mail::class)->filter(fn ($mail) => $mail->templateKey === 'domain_renewed')
            ->map(fn ($mail) => $mail->renderedSubject . ' ' . $mail->renderedHtml)->implode(' ');
        $this->assertStringNotContainsString('01/11/2029', $told, 'told «renewed until 2029» while two of the years were never bought');
    }
}
