<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\CnicRegistrar;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CentralNic Reseller, step 2 (owner 2026-10-21: «πιο ολοκληρωμένο σύστημα», domains there until 2034): its
 * nameservers, its lock, its contacts. Nothing charged; another owner never through the API.
 */
class CnicManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> the commands sent, as their lines */
    private array $sent = [];

    /** @var array<string, string> command → its answer */
    private array $answers = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str($request->body(), $form);
            $command = (string) ($form['s_command'] ?? '');
            $this->sent[] = $command;
            preg_match('/^COMMAND=(\w+)/', $command, $m);

            return Http::response($this->answers[$m[1] ?? ''] ?? "[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n");
        });
        $this->user = User::factory()->create();
        $this->answers['StatusDomain'] = self::statusAnswer(['OWNER CONTACT' => 'P-OWN1', 'ADMIN CONTACT' => 'P-OWN1', 'TECH CONTACT' => 'P-OWN1', 'BILLING CONTACT' => 'P-OWN1']);
    }

    private static function statusAnswer(array $extra = []): string
    {
        $lines = ['PAIDUNTIL DATE' => '2031-03-02 09:12:44.0', 'STATUS' => 'ACTIVE', 'TRANSFER LOCK' => '1', 'NAMESERVER' => 'ns1.example.net'] + $extra;
        $body = "[RESPONSE]\ncode = 200\ndescription = OK\n";
        foreach ($lines as $key => $value) {
            $body .= "property[{$key}][0] = {$value}\n";
        }

        return $body . "EOF\n";
    }

    private function credentials(): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], sandbox: false);
    }

    private function contact(string $first, array $attributes = []): Contact
    {
        return $this->user->client()->contacts()->create($attributes + ['type' => 'individual', 'first_name' => $first, 'last_name' => 'Papadopoulou',
            'email' => mb_strtolower($first) . '@example.test', 'phone' => '+30 210 1234567', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10431', 'country' => 'GR']);
    }

    private function domain(array $attributes = []): Domain
    {
        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $cnic->id, 'status' => DomainStatus::Active]);
    }

    public function test_its_nameservers_and_lock_in_one_modify_then_read_again(): void
    {
        $domain = $this->domain();

        $result = (new CnicRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['NS1.Example.org', 'ns2.example.org.'], transferLock: false), $this->credentials());

        $this->assertSame("COMMAND=ModifyDomain\nDOMAIN=example.com\nNAMESERVER0=ns1.example.org\nNAMESERVER1=ns2.example.org\nTRANSFERLOCK=0", $this->sent[0]);
        $this->assertStringStartsWith('COMMAND=StatusDomain', $this->sent[1], 'read again');
        $this->assertSame('2031-03-02', $result->expiresAt);
    }

    public function test_a_nameserver_inside_the_name_or_privacy_is_never_sent(): void
    {
        $domain = $this->domain();
        try {
            (new CnicRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['ns1.example.com', 'ns2.example.net']), $this->credentials());
            $this->fail('glue');
        } catch (RegistrarRefused $e) {
            $this->assertStringContainsString('glue', $e->getMessage());
        }
        try {
            (new CnicRegistrar)->updateDomain($domain, new DomainChanges(whoisPrivacy: true), $this->credentials());
            $this->fail('privacy');
        } catch (RegistrarNotAsked) {
        }
        $this->assertSame([], $this->sent);
    }

    public function test_a_new_admin_gets_its_handle_made_once_and_only_its_role_is_sent(): void
    {
        $owner = $this->contact('Maria');
        $admin = $this->contact('Nikos');
        $domain = $this->domain(['registrant_contact_id' => $owner->id, 'registrant_handle' => 'P-OWN1', 'admin_contact_id' => $admin->id]);
        $this->answers['AddContact'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[CONTACT][0] = P-NEW9\nEOF\n";

        $result = (new CnicRegistrar)->updateDomain($domain->fresh(), new DomainChanges(applyContacts: true), $this->credentials());

        $add = collect($this->sent)->first(fn ($c) => str_starts_with($c, 'COMMAND=AddContact'));
        $this->assertStringContainsString("FIRSTNAME=Nikos\nLASTNAME=Papadopoulou", $add);
        $this->assertStringContainsString('PHONE=+30.2101234567', $add);
        $modify = collect($this->sent)->first(fn ($c) => str_starts_with($c, 'COMMAND=ModifyDomain'));
        $this->assertSame("COMMAND=ModifyDomain\nDOMAIN=example.com\nADMINCONTACT0=P-NEW9", $modify, 'only the role that changed, never the owner');
        $this->assertSame('P-NEW9', $domain->fresh()->admin_handle);
        $this->assertSame('P-NEW9', $result->contactHandles['admin']);
    }

    public function test_another_owner_is_never_set_through_it(): void
    {
        $other = $this->contact('Eleni');
        $domain = $this->domain(['registrant_contact_id' => $other->id, 'registrant_handle' => 'P-ELENI']);

        $this->expectException(RegistrarRefused::class);
        try {
            (new CnicRegistrar)->updateDomain($domain, new DomainChanges(applyContacts: true), $this->credentials());
        } finally {
            $this->assertCount(0, collect($this->sent)->filter(fn ($c) => str_starts_with($c, 'COMMAND=ModifyDomain')));
        }
    }

    public function test_a_contacts_details_are_updated_never_who_it_is(): void
    {
        (new CnicRegistrar)->updateContact('P-OWN1', $this->contact('Maria', ['address1' => 'Odos 9', 'email' => 'new@example.test']), $this->credentials());

        $this->assertStringStartsWith("COMMAND=ModifyContact\nCONTACT=P-OWN1\nSTREET0=Odos 9", $this->sent[0]);
        $this->assertStringContainsString('EMAIL=new@example.test', $this->sent[0]);
        $this->assertStringNotContainsString('FIRSTNAME', $this->sent[0]);
        $this->assertStringNotContainsString('LASTNAME', $this->sent[0]);
    }

    public function test_its_answer_says_try_later_refused_or_not_known(): void
    {
        $domain = $this->domain();
        foreach (["code = 421\ndescription = Temporary error" => RegistrarNotAsked::class,
            "code = 504\ndescription = Missing required attribute" => RegistrarRefused::class,
            '<html>Bad gateway</html>' => RegistrarOutcomeUnknown::class] as $answer => $exception) {
            $this->answers['ModifyDomain'] = $answer;
            try {
                (new CnicRegistrar)->updateDomain($domain, new DomainChanges(transferLock: true), $this->credentials());
                $this->fail($exception);
            } catch (\Throwable $e) {
                $this->assertInstanceOf($exception, $e);
                $this->assertStringNotContainsString('Cnic-Secret-1', $e->getMessage());
            }
        }
    }
}
