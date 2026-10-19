<?php

namespace Tests\Feature\Domains;

use App\Contracts\ChangesOwner;
use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * G8-5a: a .gr domain's holder changed at the registry, charged and never recalled: its name (ownerNameChange) or
 * another holder (ownerChange), to a new registry contact of ours with the holder's details. Done (1000) or awaiting the
 * EETT (1001); refused before anything that costs where the domain is not ours, not changeable or the EETT forbids it.
 */
class GreppOwnerChangeTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->user = User::factory()->create();
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου',
            'company_name' => null, 'email' => 'maria@example.test', 'phone' => '+30 210 0000000', 'address1' => 'Οδός 1', 'address2' => null,
            'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10000', 'country' => 'GR']);
    }

    private function domain(): Domain
    {
        $tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'cdnet', 'tld' => 'gr', 'fqdn' => 'cdnet.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active,
            'registrant_contact_id' => $this->contact()->id, 'registrant_handle' => 'c60_mcs1']);
    }

    /** domain:info of ours, with these EPP and EETT statuses. */
    private function info(array $statuses = ['ok'], array $regulator = []): string
    {
        $s = implode('', array_map(fn ($x) => "<domain:status s=\"{$x}\"/>", $statuses));
        $r = $regulator !== [] ? '<extension><extdomain:resData xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3">'
            . implode('', array_map(fn ($x) => "<extdomain:status s=\"{$x}\"/>", $regulator)) . '</extdomain:resData></extension>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>cdnet.gr</domain:name><domain:roid>d1-gr</domain:roid>'
            . "{$s}<domain:registrant>c60_mcs1</domain:registrant><domain:ns><domain:hostObj>ns1.myip.gr</domain:hostObj></domain:ns><domain:clID>myip</domain:clID>"
            . "<domain:exDate>2028-08-16T23:59:59.9Z</domain:exDate></domain:infData></resData>{$r}<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>";
    }

    private function free(string ...$ids): string
    {
        $cd = implode('', array_map(fn ($id) => "<contact:cd><contact:id avail=\"1\">{$id}</contact:id></contact:cd>", $ids));

        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . "<resData><contact:chkData xmlns:contact=\"urn:ietf:params:xml:ns:contact-1.0\">{$cd}</contact:chkData></resData>"
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>';
    }

    private function fixture(string $name): string
    {
        return (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/v4.3/{$name}.xml")));
    }

    private function registry(string ...$answers): void
    {
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        foreach ($answers as $answer) {
            $this->transport->queue(new HttpResponse(200, $answer));
        }
    }

    private function credentials(): DomainRegistrarCredentials
    {
        return app(DomainRegistrarFactory::class)->credentialsFor($this->gr);
    }

    /** @return list<string> the bodies sent that carry $needle */
    private function sent(string $needle): array
    {
        return array_values(array_map(fn (array $r) => $r['body'], array_filter($this->transport->sent, fn (array $r) => str_contains($r['body'], $needle))));
    }

    public function test_the_same_holders_name_changed_to_a_new_contact_of_ours(): void
    {
        $domain = $this->domain();
        $renamed = $this->contact(['first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου-Νέα']);
        $this->registry($this->info(), $this->free('c60_mow' . base_convert((string) $domain->id, 10, 36)), $this->fixture('14-contact-create-response'),
            $this->fixture('45-domain-update-owner-name-change-response'));

        $result = (new GrEppRegistrar)->changeOwner($domain, $renamed, ChangesOwner::NAME, $this->credentials());

        $id = 'c60_mow' . base_convert((string) $domain->id, 10, 36);
        $this->assertSame($id, $result->details['owner_contact'], 'its new contact, in its details (the registry read again says the rest)');
        $this->assertSame('name change: done', $result->details['owner_change']);
        $this->assertStringContainsString('Παπαδοπούλου-Νέα', $this->sent('contact:create')[0]);
        $update = $this->sent('extdomain:op')[0];
        $this->assertStringContainsString("<domain:registrant>{$id}</domain:registrant>", $update);
        $this->assertStringContainsString('ownerNameChange', $update);
    }

    public function test_another_holder_awaiting_the_eett(): void
    {
        $domain = $this->domain();
        $this->registry($this->info(), $this->free('c60_mow' . base_convert((string) $domain->id, 10, 36)), $this->fixture('14-contact-create-response'),
            $this->fixture('48-domain-update-owner-change-action-pending-response'));

        $result = (new GrEppRegistrar)->changeOwner($domain, $this->contact(['first_name' => 'Γιώργος', 'email' => 'g@example.test']), ChangesOwner::OWNER, $this->credentials());

        $this->assertSame('change of holder: awaiting the EETT', $result->details['owner_change']);
        $this->assertStringContainsString('<extdomain:op>ownerChange</extdomain:op>', $this->sent('extdomain:op')[0]);
    }

    public function test_never_asked_where_it_cannot_be_done(): void
    {
        $domain = $this->domain();
        $other = User::factory()->create()->client();
        foreach ([
            'the EETT forbids a change of holder' => [ChangesOwner::OWNER, [$this->info(['ok'], ['regulatorOwnerChangeProhibited'])], []],
            'the EETT forbids a name change' => [ChangesOwner::NAME, [$this->info(['ok'], ['regulatorOwnerNameChangeProhibited'])], []],
            'held by the EETT' => [ChangesOwner::OWNER, [$this->info(['ok'], ['regulatorHold'])], []],
            'locked at the registry' => [ChangesOwner::OWNER, [$this->info(['serverUpdateProhibited'])], []],
            'a name change to another account\'s contact' => [ChangesOwner::NAME, [$this->info()], ['client_id' => $other->id]],
            'not a change of holder' => ['delete', [], []],
        ] as $why => [$kind, $answers, $holder]) {
            $this->registry(...$answers);
            try {
                (new GrEppRegistrar)->changeOwner($domain, $this->contact($holder), $kind, $this->credentials());
                $refused = null;
            } catch (RegistrarNotAsked $e) {
                $refused = $e;
            }
            $this->assertNotNull($refused, "asked: {$why}");
            $this->assertSame([], $this->sent('extdomain:op'), $why);
            $this->assertSame([], $this->sent('contact:create'), "{$why}: no contact made");
        }
    }

    public function test_its_answer_lost_is_maybe_done(): void
    {
        $domain = $this->domain();
        $this->registry($this->info(), $this->free('c60_mow' . base_convert((string) $domain->id, 10, 36)), $this->fixture('14-contact-create-response'), 'not xml');

        $this->expectException(RegistrarOutcomeUnknown::class);
        (new GrEppRegistrar)->changeOwner($domain, $this->contact(), ChangesOwner::OWNER, $this->credentials());
    }
}
