<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\CnicRegistrar;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #300 (CentralNic Reseller step 2: nameservers, lock, contacts). Written without the author's
 * context. The tests under «findings» fail on the PR as it is; those under «guards» pass and stay as guards.
 */
class CnicManagementAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> the commands sent, as their lines */
    private array $sent = [];

    /** @var array<string, string|int|list<string|int>> command → its answer (a list: one per call, the last repeated; an int: an HTTP status) */
    private array $answers = [];

    private User $user;

    private DomainRegistrarConnection $cnic;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str($request->body(), $form);
            $command = (string) ($form['s_command'] ?? '');
            $this->sent[] = $command;
            preg_match('/^COMMAND=(\w+)/', $command, $m);
            $answer = $this->answers[$m[1] ?? ''] ?? "[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n";
            if (is_array($answer)) {
                $answer = count($answer) > 1 ? array_shift($this->answers[$m[1]]) : $answer[0];
            }

            return is_int($answer) ? Http::response('<html>Gateway</html>', $answer) : Http::response($answer);
        });
        $this->user = User::factory()->create();
        $this->answers['StatusDomain'] = self::statusAnswer(['OWNER CONTACT' => 'P-OWN1', 'ADMIN CONTACT' => 'P-OWN1', 'TECH CONTACT' => 'P-OWN1', 'BILLING CONTACT' => 'P-OWN1']);
    }

    private static function statusAnswer(array $extra = []): string
    {
        $lines = $extra + ['PAIDUNTIL DATE' => '2031-03-02 09:12:44.0', 'STATUS' => 'ACTIVE', 'TRANSFER LOCK' => '1', 'NAMESERVER' => 'ns1.example.net']; // its own first
        $body = "[RESPONSE]\ncode = 200\ndescription = OK\n";
        foreach ($lines as $key => $value) {
            foreach ((array) $value as $i => $one) {
                $body .= "property[{$key}][{$i}] = {$one}\n";
            }
        }

        return $body . "EOF\n";
    }

    private function credentials(): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], sandbox: false);
    }

    private function contact(string $first, array $attributes = [], ?User $user = null): Contact
    {
        return ($user ?? $this->user)->client()->contacts()->create($attributes + ['type' => 'individual', 'first_name' => $first, 'last_name' => 'Papadopoulou',
            'email' => mb_strtolower($first) . '@example.test', 'phone' => '+30 210 1234567', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10431', 'country' => 'GR']);
    }

    private function domain(array $attributes = [], ?User $user = null): Domain
    {
        $this->cnic ??= tap(DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']),
            fn ($c) => $c->storeCredentials(['username' => 'myipreseller', 'password' => 'Cnic-Secret-1'], ['password']));
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);

        return Domain::factory()->create($attributes + ['client_id' => ($user ?? $this->user)->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->cnic->id, 'status' => DomainStatus::Active, 'expires_at' => '2031-03-02']);
    }

    private function sentCount(string $command): int
    {
        return collect($this->sent)->filter(fn ($c) => str_starts_with($c, "COMMAND={$command}"))->count();
    }

    // ---------------------------------------------------------------- findings (fail)

    /**
     * ADV-1 (high). ModifyDomain is done (200), then the read again fails (a 421, a timeout): the service takes it as a
     * failed change and undoes the roles here, while CentralNic has the new admin. MyCS and CentralNic disagree on who the
     * admin is, and the log says FAILED for a change that was made.
     */
    public function test_adv1_a_done_modify_whose_read_again_fails_is_never_undone_here(): void
    {
        $maria = $this->contact('Maria');
        $nikos = $this->contact('Nikos');
        $domain = $this->domain(['registrant_contact_id' => $maria->id, 'registrant_handle' => 'P-OWN1', 'admin_contact_id' => $maria->id, 'admin_handle' => 'P-OWN1']);
        $this->answers['AddContact'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[CONTACT][0] = P-NEW9\nEOF\n";
        $this->answers['StatusDomain'] = [$this->answers['StatusDomain'], "[RESPONSE]\ncode = 421\ndescription = Temporary error\nEOF\n"];

        try {
            app(DomainManagementService::class)->setContacts($domain, ['admin' => $nikos->id], null);
        } catch (Throwable) {
        }

        $this->assertSame(1, $this->sentCount('ModifyDomain'), 'the change was sent and CentralNic answered 200');
        $domain->refresh();
        $this->assertSame([$nikos->id, 'P-NEW9'], [$domain->admin_contact_id, $domain->admin_handle],
            'CentralNic has P-NEW9 (Nikos) as admin: undoing it here leaves MyCS saying Maria');
    }

    /**
     * ADV-2 (medium). An HTTP 502/504 from its front (a proxy that timed out waiting for the API) on a write is a
     * RuntimeException, so the service treats it as «not done» and undoes the roles: the write may well have landed.
     * It is the «no code: not known» case the PR itself names.
     */
    public function test_adv2_a_gateway_error_on_a_write_is_not_known_never_a_failure(): void
    {
        $domain = $this->domain();
        $this->answers['ModifyDomain'] = 504;

        try {
            (new CnicRegistrar)->updateDomain($domain, new DomainChanges(transferLock: true), $this->credentials());
            $this->fail('no answer');
        } catch (Throwable $e) {
            $this->assertInstanceOf(RegistrarOutcomeUnknown::class, $e, 'a 504 says nothing of whether CentralNic did it');
        }
    }

    /**
     * ADV-3 (medium). Taking a role's contact away (admin: none) sends nothing (only roles with a handle are sent) and is
     * reported as done: CentralNic keeps the old admin (an agency, a former employee) with its rights, MyCS shows none.
     */
    public function test_adv3_a_role_emptied_here_is_refused_not_left_at_cnic(): void
    {
        $maria = $this->contact('Maria');
        $agency = $this->contact('Agency');
        $domain = $this->domain(['registrant_contact_id' => $maria->id, 'registrant_handle' => 'P-OWN1', 'admin_contact_id' => $agency->id, 'admin_handle' => 'P-AGENCY']);
        $this->answers['StatusDomain'] = self::statusAnswer(['OWNER CONTACT' => 'P-OWN1', 'ADMIN CONTACT' => 'P-AGENCY']);

        try {
            app(DomainManagementService::class)->setContacts($domain, ['admin' => null], null);
        } catch (Throwable) {
        }

        $this->assertSame(0, $this->sentCount('ModifyDomain'));
        $this->assertSame($agency->id, $domain->fresh()->admin_contact_id, 'CentralNic still has P-AGENCY as admin: MyCS must not say «none»');
    }

    /**
     * ADV-4 (medium). The result's handles are ours laid over what CentralNic says after the change ($handles + read):
     * a role it did not take (ignored for this TLD, pending) reads as done, and the sync's «check the contact» warning
     * never comes. The read again is there to say what CentralNic has.
     */
    public function test_adv4_the_result_says_cnics_handles_after_the_change_not_ours(): void
    {
        $maria = $this->contact('Maria');
        $nikos = $this->contact('Nikos');
        $domain = $this->domain(['registrant_contact_id' => $maria->id, 'registrant_handle' => 'P-OWN1', 'admin_contact_id' => $nikos->id]);
        $this->answers['AddContact'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[CONTACT][0] = P-NEW9\nEOF\n";
        // ModifyDomain 200, but its record read again still has P-OWN1 as admin.

        $result = (new CnicRegistrar)->updateDomain($domain->fresh(), new DomainChanges(applyContacts: true), $this->credentials());

        $this->assertSame('P-OWN1', $result->contactHandles['admin'], 'CentralNic read again says P-OWN1');
    }

    /**
     * ADV-5 (low). A contact that already has a handle on this domain in a later role (tech) gets another one made when
     * it is set in an earlier role (admin): the roles are walked in order, the later role's handle not yet known. A
     * duplicate contact at CentralNic, and two handles of one contact here.
     */
    public function test_adv5_a_contact_with_a_handle_in_a_later_role_is_never_made_again(): void
    {
        $maria = $this->contact('Maria');
        $nikos = $this->contact('Nikos');
        $domain = $this->domain(['registrant_contact_id' => $maria->id, 'registrant_handle' => 'P-OWN1',
            'tech_contact_id' => $nikos->id, 'tech_handle' => 'P-NIK1', 'admin_contact_id' => $nikos->id]);
        $this->answers['StatusDomain'] = self::statusAnswer(['OWNER CONTACT' => 'P-OWN1', 'ADMIN CONTACT' => 'P-OWN1', 'TECH CONTACT' => 'P-NIK1']);
        $this->answers['AddContact'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[CONTACT][0] = P-DUP1\nEOF\n";

        (new CnicRegistrar)->updateDomain($domain->fresh(), new DomainChanges(applyContacts: true), $this->credentials());

        $this->assertSame(0, $this->sentCount('AddContact'), 'Nikos is P-NIK1 on this domain already');
        $this->assertSame('P-NIK1', $domain->fresh()->admin_handle);
    }

    /**
     * ADV-6 (low; the portal's and admin's HOST pattern stops it before). The glue check reads the host before its line
     * breaks are taken out (Command::clean): «ns1.ex\rample.com» passes the check and goes out as ns1.example.com, an
     * in-zone nameserver without glue. The registrar's own guard should hold for any caller.
     */
    public function test_adv6_a_line_break_never_carries_an_in_zone_nameserver_past_the_glue_check(): void
    {
        $domain = $this->domain();

        try {
            (new CnicRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ["ns1.ex\rample.com", 'ns2.example.net']), $this->credentials());
        } catch (Throwable) {
        }

        $this->assertStringNotContainsString('=ns1.example.com', implode("\n", $this->sent), 'an in-zone nameserver was sent');
    }

    /**
     * ADV-7 (low). A detail taken away (the second address line, the state) is left out of ModifyContact (an empty value
     * is never sent), so CentralNic keeps the old one while the push is recorded as done: stale WHOIS data.
     */
    public function test_adv7_an_address_line_taken_away_is_cleared_at_cnic_too(): void
    {
        // A contact MyCS made: only such is modified there in the shared account (code review 3 of #300).
        $this->domain(['meta' => ['contact_handles' => [1 => 'P-OWN1']]]);
        (new CnicRegistrar)->updateContact('P-OWN1', $this->contact('Maria', ['address2' => '', 'state' => '']), $this->credentials());

        $this->assertMatchesRegularExpression('/^STREET1=/m', $this->sent[0], 'the old second line stays at CentralNic');
    }

    /**
     * ADV-8 (low, a design limit). A domain on its own nameservers (ns1.example.com, already at CentralNic with its glue,
     * since the registry holds it as one of its nameservers now) can never change any nameserver from MyCS: every
     * in-zone host is refused, including one that is already there and needs nothing new.
     */
    public function test_adv8_an_in_zone_nameserver_already_in_use_does_not_block_a_change(): void
    {
        $domain = $this->domain();
        $this->answers['StatusDomain'] = self::statusAnswer(['NAMESERVER' => ['ns1.example.com', 'ns2.example.com'], 'OWNER CONTACT' => 'P-OWN1']);

        try {
            (new CnicRegistrar)->updateDomain($domain, new DomainChanges(nameservers: ['ns1.example.com', 'ns2.other.net']), $this->credentials());
        } catch (Throwable) {
        }

        $this->assertSame(1, $this->sentCount('ModifyDomain'), 'ns1.example.com is in use there already: its glue exists');
    }

    /**
     * ADV-9 (low; the service refuses a new owner before). The registrar makes the handles (AddContact, kept on the domain
     * at once) before it checks the owner: a registrant without a handle gets a contact made at CentralNic and its
     * handle written here as the registrant's, then «another owner is not set». Its handle then is not CentralNic's owner.
     */
    public function test_adv9_another_owner_is_refused_before_any_contact_is_made(): void
    {
        $eleni = $this->contact('Eleni');
        $domain = $this->domain(['registrant_contact_id' => $eleni->id]);
        $this->answers['AddContact'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[CONTACT][0] = P-ELENI\nEOF\n";

        try {
            (new CnicRegistrar)->updateDomain($domain->fresh(), new DomainChanges(applyContacts: true), $this->credentials());
            $this->fail('another owner');
        } catch (RegistrarRefused) {
        }

        $this->assertSame(0, $this->sentCount('AddContact'), 'a contact made at CentralNic for an owner change that is refused');
        $this->assertNull($domain->fresh()->registrant_handle, 'P-ELENI is not the owner at CentralNic');
    }

    // ---------------------------------------------------------------- guards (pass)

    /** Guard: a line break in a contact's email never adds a key (a role, a name) to ModifyContact. */
    public function test_guard_a_line_break_in_a_contact_never_adds_a_key(): void
    {
        $this->domain(['meta' => ['contact_handles' => [1 => 'P-OWN1']]]); // a contact MyCS made (code review 3 of #300)
        (new CnicRegistrar)->updateContact('P-OWN1', $this->contact('Maria', ['email' => "m@example.test\nOWNERCONTACT0=P-EVIL\nFIRSTNAME=Evil"]), $this->credentials());

        $this->assertDoesNotMatchRegularExpression('/^(OWNERCONTACT0|FIRSTNAME|LASTNAME|ORGANIZATION)=/m', $this->sent[0]);
    }

    /** Guard: a nameserver or contact change never carries TRANSFERLOCK (never turned off on the side). */
    public function test_guard_the_lock_is_never_sent_with_another_change(): void
    {
        $maria = $this->contact('Maria');
        $nikos = $this->contact('Nikos');
        $domain = $this->domain(['registrant_contact_id' => $maria->id, 'registrant_handle' => 'P-OWN1', 'admin_contact_id' => $nikos->id]);
        $this->answers['AddContact'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[CONTACT][0] = P-NEW9\nEOF\n";

        (new CnicRegistrar)->updateDomain($domain->fresh(), new DomainChanges(nameservers: ['ns1.other.net', 'ns2.other.net']), $this->credentials());
        (new CnicRegistrar)->updateDomain($domain->fresh(), new DomainChanges(applyContacts: true), $this->credentials());

        $this->assertStringNotContainsString('TRANSFERLOCK', implode("\n", $this->sent));
        $this->assertSame(2, $this->sentCount('ModifyDomain'));
    }

    /** Guard: a refused or failed write never puts the password in the error or the registrar log. */
    public function test_guard_the_password_is_never_in_an_error_or_the_log(): void
    {
        $domain = $this->domain();
        foreach ([500, "[RESPONSE]\ncode = 531\ndescription = Authorization failed\nEOF\n"] as $answer) {
            $this->answers['ModifyDomain'] = $answer;
            try {
                app(DomainManagementService::class)->setNameservers($domain, ['ns1.other.net', 'ns2.other.net'], null);
                $this->fail('refused');
            } catch (Throwable $e) {
                $this->assertStringNotContainsString('Cnic-Secret-1', $e->getMessage());
            }
        }
        $this->assertStringNotContainsString('Cnic-Secret-1', json_encode(DomainRegistrarLog::query()->get()->toArray()));
    }

    /** Guard: another account's contact, or another owner, is refused by the service before anything is sent. */
    public function test_guard_another_accounts_contact_or_another_owner_sends_nothing(): void
    {
        $maria = $this->contact('Maria');
        $stranger = $this->contact('Stranger', [], User::factory()->create());
        $mine = $this->contact('Nikos');
        $domain = $this->domain(['registrant_contact_id' => $maria->id, 'registrant_handle' => 'P-OWN1']);

        foreach ([['admin' => $stranger->id], ['registrant' => $stranger->id], ['registrant' => $mine->id]] as $roles) {
            try {
                app(DomainManagementService::class)->setContacts($domain->fresh(), $roles, null);
                $this->fail('refused');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->sent);
        $this->assertSame([$maria->id, 'P-OWN1', null], [$domain->fresh()->registrant_contact_id, $domain->fresh()->registrant_handle, $domain->fresh()->admin_contact_id]);
    }

    /** Guard: a refused ModifyDomain undoes the roles, and the retry reuses the handle made (one AddContact in all). */
    public function test_guard_a_retry_after_a_refusal_makes_no_second_contact(): void
    {
        $maria = $this->contact('Maria');
        $nikos = $this->contact('Nikos');
        $domain = $this->domain(['registrant_contact_id' => $maria->id, 'registrant_handle' => 'P-OWN1', 'admin_contact_id' => $maria->id, 'admin_handle' => 'P-OWN1']);
        $this->answers['AddContact'] = "[RESPONSE]\ncode = 200\ndescription = OK\nproperty[CONTACT][0] = P-NEW9\nEOF\n";
        $this->answers['ModifyDomain'] = ["[RESPONSE]\ncode = 504\ndescription = Missing required attribute\nEOF\n", "[RESPONSE]\ncode = 200\ndescription = OK\nEOF\n"];

        try {
            app(DomainManagementService::class)->setContacts($domain, ['admin' => $nikos->id], null);
            $this->fail('refused');
        } catch (RegistrarRefused) {
        }
        $this->assertSame([$maria->id, 'P-OWN1'], [$domain->fresh()->admin_contact_id, $domain->fresh()->admin_handle], 'undone');

        app(DomainManagementService::class)->setContacts($domain->fresh(), ['admin' => $nikos->id], null);

        $this->assertSame(1, $this->sentCount('AddContact'));
        $this->assertSame([$nikos->id, 'P-NEW9'], [$domain->fresh()->admin_contact_id, $domain->fresh()->admin_handle]);
    }

    /** Guard: another account's CNIC domain cannot be changed from the portal; nothing reaches CentralNic. */
    public function test_guard_another_accounts_cnic_domain_is_not_reached(): void
    {
        $theirs = $this->domain(['sld' => 'theirs', 'fqdn' => 'theirs.com'], User::factory()->create());

        foreach (['toggleTransferLock', 'saveNameservers'] as $action) {
            Livewire::actingAs($this->user)->test(Show::class, ['domain' => $theirs])
                ->set('nameservers', [['host' => 'ns1.evil.net'], ['host' => 'ns2.evil.net']])->call($action)->assertStatus(404);
        }
        $this->assertSame([], $this->sent);
    }

    /** Guard: its portal page opens, and privacy, DNSSEC and glue are not offered or reached (no 500, nothing sent). */
    public function test_guard_privacy_dnssec_and_glue_are_not_offered_for_it(): void
    {
        $domain = $this->domain();
        $domain->domainTld->forceFill(['id_protection' => true])->save();

        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->assertOk();
        $this->assertFalse($page->instance()->privacyOffered());
        $this->assertTrue($page->instance()->lockable());
        foreach (['toggleIdProtection', 'readDnssec', 'readChildHosts'] as $action) {
            $page->call($action)->assertOk();
        }
        $this->assertSame([], $this->sent);
    }
}
