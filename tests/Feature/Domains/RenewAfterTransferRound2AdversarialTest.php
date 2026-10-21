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
use App\Services\Domains\Lookup\GrWhois;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Nixpal\DomainTools\Whois\RdapRecord;
use Nixpal\DomainTools\Whois\SocketConnector;
use Tests\TestCase;

/**
 * Adversarial review of #304, round 2 (the fix commit 522470f6): the registry's own expiry as the guard against a second
 * paid year, the staff hand-off (told_at), and the .gr registry's cache.
 */
class RenewAfterTransferRound2AdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> CNIC commands */
    private array $cnic = [];

    /** @var list<array{0: string, 1: array}> Openprovider POSTs */
    private array $op = [];

    private ?string $auth = 'Epp-Code-123';

    /** The registry's expiry (RDAP), as it shows the transfer: null, not readable. */
    private ?string $rdapExpiry = '2026-11-01T00:00:00Z';

    /** The registry's RDAP has no expiration event and links to the registrar's RDAP (a thin answer). */
    private bool $registryWithoutExpiry = false;

    /** The registrar's own RDAP answer: the same lagging date as its API. */
    private string $registrarRdapExpiry = '2026-11-01T00:00:00Z';

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
            $request->method() === 'POST' && str_ends_with($path, '/renew') => $this->opRenew(),
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->op === [] ? [] : [$this->record()], 'total' => $this->op === [] ? 0 : 1] + ($this->op === [] ? [] : $this->record())]),
            str_contains($request->url(), 'data.iana.org') => Http::response(['services' => [[['com', 'eu', 'uk'], ['https://rdap.example/']]]]),
            str_contains($request->url(), 'rdap.registrar.example/') => Http::response(['objectClassName' => 'domain', 'ldhName' => 'example.com',
                'events' => [['eventAction' => 'expiration', 'eventDate' => $this->registrarRdapExpiry]]]),
            str_contains($request->url(), 'grwhois') => Http::response([['name' => 'example.gr', 'expireDate' => '01-11-2027', 'status' => ['ok']]]),
            str_contains($request->url(), 'rdap.example/domain/') && $this->registryWithoutExpiry => Http::response(['objectClassName' => 'domain', 'ldhName' => 'example.com',
                'events' => [['eventAction' => 'registration', 'eventDate' => '2010-11-01T00:00:00Z']],
                'links' => [['rel' => 'related', 'type' => 'application/rdap+json', 'href' => 'https://rdap.registrar.example/domain/example.com']]]),
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

    private function opRenew()
    {
        $this->opRenewals++;
        $this->opExpiry = Carbon::parse($this->opExpiry)->addYear()->format('Y-m-d H:i:s');

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

    /** Transferred, done at the target, its registrar lagging: the old expiry. */
    private function transferredAndLagging(): array
    {
        [$domain, $line] = $this->paidRenewal();
        app(DomainRenewalService::class)->renewLine($line->id);
        $this->opStatus = 'ACT';
        $this->opExpiry = '2026-11-01 00:00:00';

        return [$domain, $line];
    }

    /**
     * R2-1: the registry's RDAP gives no expiration (thin) and links to the registrar's: RdapClient fills the date from the
     * registrar's own RDAP, the same lagging data as its API. «The registry shows the year missing» is then the registrar
     * again, and a year the transfer did add is charged a second time.
     */
    public function test_the_registrars_rdap_merged_into_a_thin_registry_answer_is_never_the_registrys_word(): void
    {
        [$domain, $line] = $this->transferredAndLagging();
        $this->registryWithoutExpiry = true;
        $sync = app(DomainSyncService::class);
        $sync->sync($domain->refresh());
        foreach (['2026-10-23 11:00:00', '2026-10-25 11:00:00'] as $day) {
            Carbon::setTestNow($day);
            $sync->sync($domain->refresh());
            app(DomainRenewalService::class)->renewLine($line->id);
        }

        $this->assertSame(0, $this->opRenewals, 'never a second year on the registrar\'s own word');
        // Fix of R2-2: not readable as the registry's word → staff's: opened for their Retry only (staff_only), never the cron's.
        $record = $domain->fresh()->meta['renew_transfer'] ?? [];
        $this->assertTrue(empty($record['reopened_at']) || ($record['staff_only'] ?? false) === true, 'never opened for the cron to charge');
    }

    /**
     * R2-2: told once (the registry not readable for a moment): the message says «renew it there by hand (or Retry)», but
     * the line is applied, so Retry is «Nothing to do», even once the registry confirms the year is missing.
     */
    public function test_retry_of_the_told_action_renews_once_the_registry_confirms_the_year_is_missing(): void
    {
        [$domain, $line] = $this->transferredAndLagging();
        $this->rdapExpiry = null;
        $sync = app(DomainSyncService::class);
        $sync->sync($domain->refresh());
        Carbon::setTestNow('2026-10-23 11:00:00');
        $sync->sync($domain->refresh());
        $this->assertNotNull($domain->fresh()->meta['renew_transfer']['told_at'] ?? null, 'precondition: staff told');

        $this->rdapExpiry = '2026-11-01T00:00:00Z'; // readable now: the year is missing at the registry
        $outcome = app(DomainRenewalService::class)->renewLine($line->id, retry: true);

        $this->assertNotSame(DomainRenewalService::NOTHING, $outcome, 'the Retry the message offers does something');
        $this->assertSame(1, $this->opRenewals);
    }

    /**
     * R2-3: told once, then staff renew it at the registrar by hand and the sync shows the year: renewAfterTransfer returns
     * at told_at before anything, so the record is never settled (meta.renew_transfer with its «prior» stays forever, and
     * RenewAsTransfer refuses any later transfer of this domain: «already on record»).
     */
    public function test_a_told_record_is_settled_once_the_year_shows_up(): void
    {
        [$domain, $line] = $this->transferredAndLagging();
        $this->rdapExpiry = null;
        $sync = app(DomainSyncService::class);
        $sync->sync($domain->refresh());
        Carbon::setTestNow('2026-10-23 11:00:00');
        $sync->sync($domain->refresh());
        $this->assertNotNull($domain->fresh()->meta['renew_transfer']['told_at'] ?? null, 'precondition: staff told');

        $this->opExpiry = '2027-11-01 00:00:00'; // renewed there by hand
        Carbon::setTestNow('2026-10-24 11:00:00');
        $sync->sync($domain->refresh());
        // Fix of R2-2: the line is open for staff; the next cron run finds the year there and adopts it, uncharged.
        $this->assertSame(DomainRenewalService::ADOPTED, app(DomainRenewalService::class)->renewLine($line->id));

        $this->assertSame(0, $this->opRenewals);
        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertArrayNotHasKey('renew_transfer', $domain->fresh()->meta, 'settled into renew_transfer_last');
    }

    /**
     * R2-4: «read now, not cached»: the cache forgotten is the RDAP key, but a .gr answer is cached under its key plus
     * GrWhois::scope(), so an answer up to an hour old (a whois page view) is taken as the registry's word.
     */
    public function test_a_gr_registry_answer_is_read_now_never_from_the_cache(): void
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60', 'whois_username' => 'who4',
            'whois_password' => 'Whois-pw1'], ['password', 'whois_password']);
        $old = new RdapRecord;
        $old->domain = 'example.gr';
        $old->expires = '2026-11-01';
        Cache::put('domain-whois-answer:' . sha1('example.gr') . ':' . GrWhois::scope(),
            ['status' => 'found', 'record' => $old, 'text' => null], 3600);

        $read = (new \ReflectionMethod(DomainSyncService::class, 'registryExpiry'))->invoke(app(DomainSyncService::class), 'example.gr');

        $this->assertSame('2027-11-01', $read?->toDateString(), 'the registry now (2027-11-01), not the cached 2026-11-01');
    }
}
