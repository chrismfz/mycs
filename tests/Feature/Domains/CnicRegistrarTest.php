<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\Registrars\CnicRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CentralNic Reseller, step 1 (owner 2026-10-21: myip's domains there, some paid to 2034): its reads, never a write.
 * Its answers as its command reference gives them (kb.centralnicreseller.com/api/api-command).
 */
class CnicRegistrarTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{url: string, command: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function credentials(bool $sandbox = false): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], sandbox: $sandbox);
    }

    /** @param  array<string, string>  $answers  command → its answer */
    private function cnic(array $answers): void
    {
        Http::fake(function (Request $request) use ($answers) {
            parse_str($request->body(), $form);
            $command = (string) ($form['s_command'] ?? '');
            $this->sent[] = ['url' => $request->url(), 'command' => $command, 'login' => $form['s_login'] ?? null];
            preg_match('/^COMMAND=(\w+)/', $command, $m);

            return Http::response($answers[$m[1] ?? ''] ?? "[RESPONSE]\ncode = 500\ndescription = Invalid command name\nEOF\n");
        });
    }

    private const STATUS_DOMAIN = "[RESPONSE]\r\ncode = 200\r\ndescription = Command completed successfully\r\n"
        . "property[DOMAIN][0] = example.com\r\nproperty[ROID][0] = 1234567_DOMAIN_COM-VRSN\r\n"
        . "property[STATUS][0] = ACTIVE\r\nproperty[STATUS][1] = clientTransferProhibited\r\n"
        . "property[CREATED_DATE][0] = 2015-03-02 09:12:44.0\r\n"
        . "property[REGISTRATION_EXPIRATION_DATE][0] = 2031-03-02 09:12:44.0\r\nproperty[PAIDUNTIL_DATE][0] = 2031-03-02 09:12:44.0\r\n"
        . "property[RENEWALMODE][0] = AUTOEXPIRE\r\nproperty[TRANSFER_LOCK][0] = 1\r\n"
        . "property[NAMESERVER][0] = NS1.EXAMPLE.NET\r\nproperty[NAMESERVER][1] = ns2.example.net.\r\n"
        . "property[OWNER_CONTACT][0] = P-ABC123\r\nproperty[ADMIN_CONTACT][0] = P-ABC123\r\nproperty[TECH_CONTACT][0] = P-TECH1\r\n"
        . "property[AUTH][0] = s3cr3t-Auth!\r\nEOF\r\n";

    public function test_a_domain_reads_as_its_record_says_its_auth_code_never_in_its_details(): void
    {
        $this->cnic(['StatusDomain' => self::STATUS_DOMAIN]);
        $domain = Domain::factory()->make(['sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com']);

        $result = (new CnicRegistrar)->syncDomain($domain, $this->credentials());

        $this->assertSame(['2031-03-02', '2015-03-02', DomainStatus::Active, true], [$result->expiresAt, $result->registeredAt, $result->status, $result->transferLock]);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $result->nameservers);
        $this->assertSame(['registrant' => 'P-ABC123', 'admin' => 'P-ABC123', 'tech' => 'P-TECH1'], $result->contactHandles);
        $this->assertSame('1234567_DOMAIN_COM-VRSN', $result->registrarDomainId);
        $this->assertSame('AUTOEXPIRE', $result->details['renewalmode']);
        $this->assertStringNotContainsString('s3cr3t', json_encode($result->details));
        $this->assertSame("COMMAND=StatusDomain\nDOMAIN=example.com", $this->sent[0]['command']);
        $this->assertSame('https://api.rrpproxy.net/api/call.cgi', $this->sent[0]['url'], 'production: its live API');
        $this->assertSame('s3cr3t-Auth!', (new CnicRegistrar)->getEppCode($domain, $this->credentials()));
    }

    public function test_one_not_in_the_account_is_not_found_and_a_sandbox_reaches_its_ote(): void
    {
        $this->cnic(['StatusDomain' => "[RESPONSE]\ncode = 545\ndescription = Entity reference not found\nEOF\n"]);

        try {
            (new CnicRegistrar)->syncDomain(Domain::factory()->make(['fqdn' => 'gone.com']), $this->credentials(sandbox: true));
            $this->fail('not found');
        } catch (DomainNotFoundAtRegistrar $e) {
            $this->assertSame(545, $e->resultCode);
        }
        $this->assertSame('https://api-ote.rrpproxy.net/api/call.cgi', $this->sent[0]['url']);
    }

    public function test_its_ping_balance_list_and_contacts(): void
    {
        $this->cnic([
            'StatusDomain' => self::STATUS_DOMAIN,
            'StatusAccount' => "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[AMOUNT][0] = 1520.00\nproperty[CREDIT][0] = 312.40\nproperty[CURRENCY][0] = EUR\nEOF\n",
            'QueryDomainList' => "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[DOMAIN][0] = alpha.com\nproperty[DOMAIN][1] = beta.eu\n"
                . "property[DOMAIN_REGISTRATION_EXPIRATION_DATE][0] = 2027-01-10 00:00:00.0\nproperty[DOMAIN_REGISTRATION_EXPIRATION_DATE][1] = 2029-05-05 00:00:00.0\n"
                . "property[RENEWALMODE][0] = AUTOEXPIRE\nproperty[RENEWALMODE][1] = AUTORENEW\nproperty[TOTAL][0] = 198\nEOF\n",
            'StatusContact' => "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[FIRST_NAME][0] = Maria\nproperty[LAST_NAME][0] = Papadopoulou\n"
                . "property[ORGANIZATION][0] = Example IKE\nproperty[STREET][0] = Odos 1\nproperty[CITY][0] = Athina\nproperty[ZIP][0] = 10431\n"
                . "property[COUNTRY][0] = gr\nproperty[PHONE][0] = +30.2101234567\nproperty[EMAIL][0] = maria@example.test\nEOF\n",
        ]);
        $cnic = new CnicRegistrar;

        $this->assertTrue($cnic->ping($this->credentials()));
        // AMOUNT is its balance (its account page; myip's live answer, 2026-10-21), CREDIT a credit line on top.
        $this->assertSame(['1520.00', 'EUR'], [$cnic->accountBalance($this->credentials())->amount, $cnic->accountBalance($this->credentials())->currency]);
        $page = $cnic->listDomains($this->credentials(), 0, 100);
        $this->assertSame([198, 2], [$page->total, $page->rawCount]);
        // Each read as its sync reads it (StatusDomain): the same expiry, nameservers and contacts (reviews of #297).
        $this->assertSame(['alpha.com', '2031-03-02', false, ['ns1.example.net', 'ns2.example.net']],
            [$page->records[0]->fqdn(), $page->records[0]->expiresAt, $page->records[0]->autoRenew, $page->records[0]->nameservers]);
        $this->assertSame(['beta', 'eu'], [$page->records[1]->sld, $page->records[1]->tld]);
        $contact = $cnic->getContact('P-ABC123', $this->credentials());
        $this->assertSame(['Maria Papadopoulou', 'Example IKE', 'GR', '10431'], [$contact->name, $contact->org, $contact->country, $contact->postcode]);
        $this->assertFalse($cnic->ping(new DomainRegistrarCredentials([], sandbox: false)), 'no credentials: false, never thrown');
    }

    public function test_no_write_is_ever_sent_yet(): void
    {
        $this->cnic([]);
        $domain = Domain::factory()->make(['fqdn' => 'example.com']);
        foreach ([fn ($c) => $c->renew($domain, 1, $this->credentials()), fn ($c) => $c->register($domain, 1, $this->credentials()),
            fn ($c) => $c->transferIn($domain, 'x', $this->credentials()), fn ($c) => $c->restore($domain, $this->credentials())] as $write) {
            try {
                $write(new CnicRegistrar);
                $this->fail('a write was made');
            } catch (RegistrarNotAsked $e) {
                $this->assertStringContainsString('nothing was sent', $e->getMessage());
            }
        }
        $this->assertSame([], $this->sent, 'nothing reached CentralNic');
    }

    public function test_a_domain_at_cnic_is_synced_through_its_connection(): void
    {
        $this->cnic(['StatusDomain' => self::STATUS_DOMAIN]);
        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $cnic->storeCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $cnic->id, 'status' => DomainStatus::Active, 'expires_at' => '2030-03-02']);

        $this->assertInstanceOf(CnicRegistrar::class, app(DomainRegistrarFactory::class)->for($cnic));
        app(DomainSyncService::class)->sync($domain);

        $domain->refresh();
        $this->assertSame(['2031-03-02', ['ns1.example.net', 'ns2.example.net']], [$domain->expires_at->toDateString(), $domain->nameservers()->orderBy('sort')->pluck('host')->all()]);
        $this->assertSame('myipreseller', $this->sent[0]['login']);
    }

    public function test_its_availability_and_that_nothing_is_sold_or_changed_through_it_yet(): void
    {
        $this->cnic(['CheckDomains' => "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[DOMAINCHECK][0] = 210 Domain name available\nEOF\n"]);
        $this->assertTrue((new CnicRegistrar)->checkAvailability('free-name.com', $this->credentials())->available);

        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $cnic->storeCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 30, 'registrar_connection_id' => $cnic->id]);
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $cnic->id, 'status' => DomainStatus::Active, 'expires_at' => '2030-03-02']);

        $this->assertFalse($tld->fresh()->registrarCan('register'), 'not sold through it (reviews of #297)');
        $this->assertFalse(app(DomainManagementService::class)->isManageable($domain), 'its forms not shown: nothing is changed through it yet');
    }

    public function test_its_live_answer_with_spaces_in_the_names_reads_the_same(): void
    {
        // As myip's live account answered StatusDomain (2026-10-21), its values made up: names with spaces, not underscores.
        $this->cnic(['StatusDomain' => "[RESPONSE]\r\ncode = 200\r\ndescription = Command completed successfully\r\n"
            . "property[CREATED DATE][0] = 2009-09-16 18:57:28.0\r\nproperty[PAIDUNTIL DATE][0] = 2027-09-16 18:57:28.0\r\n"
            . "property[REGISTRATION EXPIRATION DATE][0] = 2027-09-16 18:57:28.0\r\nproperty[RENEWAL DATE][0] = 2027-10-21 18:57:28.0\r\n"
            . "property[RENEWALMODE][0] = AUTOEXPIRE\r\nproperty[STATUS][0] = clientTransferProhibited\r\nproperty[TRANSFER LOCK][0] = 1\r\n"
            . "property[NAMESERVER][0] = ns1.example.net\r\nproperty[NAMESERVER][1] = ns2.example.net\r\n"
            . "property[OWNER CONTACT][0] = P-OWN1\r\nproperty[ADMIN CONTACT][0] = P-ADM1\r\nproperty[AUTH][0] = live-Auth-9\r\nEOF\r\n"]);

        $result = (new CnicRegistrar)->syncDomain(Domain::factory()->make(['fqdn' => 'example.com']), $this->credentials());

        $this->assertSame(['2027-09-16', '2009-09-16', true, DomainStatus::Active], [$result->expiresAt, $result->registeredAt, $result->transferLock, $result->status],
            'paid until (its renewal date is later for a .com)');
        $this->assertSame(['registrant' => 'P-OWN1', 'admin' => 'P-ADM1'], $result->contactHandles);
        $this->assertSame('2027-10-21 18:57:28.0', $result->details['renewal_date']);
        $this->assertStringNotContainsString('live-Auth', json_encode($result->details));
    }
}
