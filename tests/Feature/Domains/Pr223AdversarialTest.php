<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Adversarial review of PR #223 (P3: a domain's DNSSEC in the client portal). Each test is a finding (ADV-n) and fails
 * on the PR as pushed; those marked «guard» pass and pin behaviour that must not regress.
 */
class Pr223AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const DIGEST = '2BB183AF5F22588179A53B0A98631FAD1A292118F8D0CFE5F1C2C2F7C1D3A9B4';

    private const KEY = 'mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==';

    private FakeTransport $transport;

    private User $user;

    /** @var list<array<string, mixed>> Openprovider's DNSKEYs */
    private array $opKeys = [];

    /** @var list<array<string, mixed>> what was PUT to Openprovider */
    private array $puts = [];

    /** The HTTP status Openprovider answers a PUT with (and keeps the keys anyway: it landed). */
    private int $opPutStatus = 200;

    /** Openprovider answers PUT with code 0 but keeps nothing. */
    private bool $opIgnores = false;

    /** Openprovider's reads answer an error envelope (code != 0) while this is true. */
    private bool $opReadErrors = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
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

    private function grDomain(): Domain
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'registrar_connection_id' => $gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'cdnet', 'tld' => 'gr', 'fqdn' => 'cdnet.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Active]);
    }

    private function opDomain(): Domain
    {
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'registrar_connection_id' => $op->id, 'registrar_domain_id' => '42', 'status' => DomainStatus::Active]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'PUT') {
                $this->puts[] = $request->data();
                $this->opKeys = $this->opIgnores ? [] : ($request->data()['dnssec_keys'] ?? []);

                return $this->opPutStatus === 200 ? Http::response(['code' => 0, 'data' => ['id' => 42]])
                    : Http::response(['desc' => 'Internal error'], $this->opPutStatus);
            }
            if ($this->opReadErrors) {
                return Http::response(['code' => 399, 'desc' => 'Temporary backend problem', 'data' => null]);
            }

            return Http::response(['code' => 0, 'data' => ['id' => 42, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'ACT',
                'dnssec' => $this->opKeys === [] ? 'unsigned' : 'signedDelegation', 'dnssec_keys' => $this->opKeys]]);
        });

        return $domain;
    }

    private function info(array $ds = []): string
    {
        $records = implode('', array_map(fn (array $d) => "<secDNS:dsData><secDNS:keyTag>{$d[0]}</secDNS:keyTag><secDNS:alg>{$d[1]}</secDNS:alg>"
            . "<secDNS:digestType>{$d[2]}</secDNS:digestType><secDNS:digest>{$d[3]}</secDNS:digest></secDNS:dsData>", $ds));

        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>cdnet.gr</domain:name><domain:roid>d1-gr</domain:roid>'
            . '<domain:status s="ok"/><domain:ns><domain:hostObj>ns1.myip.gr</domain:hostObj></domain:ns><domain:clID>myip</domain:clID>'
            . '<domain:exDate>2028-08-16T23:59:59.9Z</domain:exDate></domain:infData></resData>'
            . ($records !== '' ? "<extension><secDNS:infData xmlns:secDNS=\"urn:ietf:params:xml:ns:secDNS-1.1\">{$records}</secDNS:infData></extension>" : '')
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>';
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

    private function updates(): array
    {
        return array_values(array_filter(array_map(fn (array $r) => $r['body'], $this->transport->sent), fn (string $b) => str_contains($b, 'secDNS:update')));
    }

    /**
     * ADV-1 (high): the confirmation is on the submit button, but the action is the form's wire:submit. Livewire binds
     * wire:confirm to the action directive of the SAME element (el.__livewire_confirm, read only by that element's
     * wire:<event>), so neither «Send these records?» nor «Remove every DNSSEC record (unsigned)?» is ever asked.
     */
    public function test_adv1_the_save_and_unsign_confirmation_is_on_the_element_that_runs_the_action(): void
    {
        $domain = $this->grDomain();
        $this->registry($this->info([[2371, 13, 2, self::DIGEST]]));
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec')
            ->call('removeDnssecRow', 0);

        $html = $page->html();
        preg_match_all('/<[a-z][a-z0-9.-]*\b[^>]*wire:(?:submit|click)(?:\.[a-z]+)*="saveDnssec"[^>]*>/i', $html, $tags);
        $this->assertNotEmpty($tags[0], 'something runs saveDnssec');
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('wire:confirm', $tag, "the element that runs saveDnssec asks first: {$tag}");
        }
    }

    /**
     * ADV-2 (medium): Openprovider answers the PUT with a 5xx (it may well have landed: here it did). The portal says
     * «The registry could not be reached. Please try again later», not «maybe done, read again». A 5xx is a plain
     * RuntimeException (OpenproviderRegistrar::request), which neither the service nor Show maps to «maybe».
     */
    public function test_adv2_an_openprovider_5xx_after_the_put_is_maybe_done_not_failed(): void
    {
        $domain = $this->opDomain();
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec')
            ->call('addDnssecRow')->set('dnssecRows.0', ['flags' => '257', 'algorithm' => '13', 'public_key' => self::KEY]);

        $this->opPutStatus = 502;
        $page->call('saveDnssec');

        $this->assertCount(1, $this->puts);
        $this->assertNotEmpty($this->opKeys, 'it landed');
        $page->assertNotDispatched('notify', ['message' => __('domains.portal.registrar_failed'), 'type' => 'error'])
            ->assertDispatched('notify', ['message' => __('domains.portal.dnssec_maybe'), 'type' => 'error']);
    }

    /**
     * ADV-3 (medium): Openprovider's read BEFORE the PUT (the «changed since read» check) answers an error envelope:
     * request() throws RegistrarOutcomeUnknown for it, so the customer is told «it may have been done» (and the service
     * writes a «maybe» note) although nothing was sent.
     */
    public function test_adv3_a_failed_read_before_anything_is_sent_is_not_maybe_done(): void
    {
        $domain = $this->opDomain();
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec')
            ->call('addDnssecRow')->set('dnssecRows.0', ['flags' => '257', 'algorithm' => '13', 'public_key' => self::KEY]);

        $this->opReadErrors = true;
        $page->call('saveDnssec');

        $this->assertSame([], $this->puts, 'nothing was sent');
        $page->assertNotDispatched('notify', ['message' => __('domains.portal.dnssec_maybe'), 'type' => 'error']);
    }

    /**
     * ADV-4 (low): the server checks DNSKEY algorithms against every algorithm (DnssecOptions::ALGORITHMS), not the
     * DNSKEY list the form offers (DnssecOptions::algorithms(DNSKEY): 3, 6, 12 left out, as staff's form). A request
     * with algorithm 12 (GOST, «MUST NOT» sign, RFC 8624) reaches Openprovider.
     */
    public function test_adv4_a_dnskey_algorithm_the_form_does_not_offer_never_reaches_openprovider(): void
    {
        $domain = $this->opDomain();
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec')
            ->call('addDnssecRow')->set('dnssecRows.0', ['flags' => '257', 'algorithm' => '12', 'public_key' => self::KEY]);

        $page->call('saveDnssec');

        $this->assertSame([], $this->puts);
    }

    /**
     * ADV-5 (low): after «maybe done» at Openprovider (answered, kept nothing), the page shows the .gr registry's note
     * «The registry checks the DS records ... within about 20 minutes; meanwhile no other change is possible» for a
     * .com DNSKEY domain: the note shown is any stored note (here the «maybe» one), whatever the registrar, and kept
     * across reads with no age.
     */
    public function test_adv5_the_ds_check_note_is_not_shown_for_an_openprovider_domain(): void
    {
        $domain = $this->opDomain();
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec')
            ->call('addDnssecRow')->set('dnssecRows.0', ['flags' => '257', 'algorithm' => '13', 'public_key' => self::KEY]);

        $this->opIgnores = true;
        $page->call('saveDnssec')->assertDispatched('notify', ['message' => __('domains.portal.dnssec_maybe'), 'type' => 'error'])
            ->set('editDnssec', false)->call('readDnssec');

        $page->assertDontSee(__('domains.portal.dnssec_note'));
    }

    /**
     * ADV-6 (low): told «changed since read: read them again», the customer reads again; the form stays open with the
     * old rows and the old $dnssecSeen, so every save is refused the same way (a loop: the advice given cannot work).
     * Reading while editing should close (or restart) the form.
     */
    public function test_adv6_reading_again_while_editing_restarts_the_form(): void
    {
        $domain = $this->grDomain();
        $this->registry($this->info([[2371, 13, 2, self::DIGEST]]));
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec');

        $both = $this->info([[2371, 13, 2, self::DIGEST], [4444, 13, 2, str_repeat('C', 64)]]);
        $this->registry($both, $both);
        $page->call('removeDnssecRow', 0)->call('saveDnssec')
            ->assertDispatched('notify', ['message' => __('domains.portal.dnssec_changed'), 'type' => 'error'])
            ->call('readDnssec');

        $this->assertSame([], $this->updates(), 'guard: nothing unseen removed');
        $this->assertTrue(!$page->get('editDnssec') || count(json_decode($page->get('dnssecSeen'), true)) === 2,
            'after reading again the form is closed or starts from the new read');
    }

    /**
     * ADV-7 (low): the domain stops being manageable while the form is open (connection switched off): «Save» does
     * nothing at all (saveDnssec returns on dnssecKind() === null before mayCallRegistrar's «managed by our team»).
     */
    public function test_adv7_saving_on_a_domain_no_longer_manageable_says_so(): void
    {
        $domain = $this->grDomain();
        $this->registry($this->info());
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec');

        $domain->registrarConnection()->update(['is_active' => false]);
        $page->call('saveDnssec')->assertDispatched('notify', ['message' => __('domains.portal.not_manageable'), 'type' => 'error']);
    }

    /**
     * ADV-8 (low): customer-facing strings not in lang/ (CLAUDE.md): the DNSSEC form's placeholders/labels «Key tag»,
     * «Digest», «Flags», «Public key» are hard-coded English in the Greek portal.
     */
    public function test_adv8_the_form_s_labels_are_translated(): void
    {
        App::setLocale('el');
        $domain = $this->grDomain();
        $this->registry($this->info([[2371, 13, 2, self::DIGEST]]));
        $html = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec')->html();

        $this->assertStringNotContainsString('placeholder="Key tag"', $html);
        $this->assertStringNotContainsString('placeholder="Digest"', $html);
    }

    /** Guard: a tampered list of 17 rows, or rows that are not arrays, never reach the registry. */
    public function test_guard_tampered_rows_never_reach_the_registry(): void
    {
        $domain = $this->grDomain();
        $this->registry($this->info());
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec');
        $row = ['key_tag' => '2371', 'algorithm' => '13', 'digest_type' => '2', 'digest' => self::DIGEST];

        $this->registry($this->info(), $this->info());
        $page->set('dnssecRows', array_fill(0, 17, $row))->call('saveDnssec')->assertHasErrors('dnssecRows');
        $page->set('dnssecRows', [['digest' => "AB\x00CD"] + $row])->call('saveDnssec');
        $page->set('dnssecRows', [['key_tag' => '+5', 'algorithm' => '13', 'digest_type' => '2', 'digest' => self::DIGEST]])->call('saveDnssec');
        $this->assertSame([], $this->updates());
    }
}
