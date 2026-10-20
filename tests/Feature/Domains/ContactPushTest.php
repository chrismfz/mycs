<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Jobs\Domains\PushContactJob;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Domains\ContactPush;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * P1b-2 (owner 2026-10-20): a contact's address, phone or email, changed, reaches the registrar of each domain that uses
 * it (its role's handle), once per handle, off the request; its name never. What fails is a Pending Module Action.
 */
class ContactPushTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    private DomainRegistrarConnection $op;

    private FakeTransport $transport;

    private User $user;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
        $this->user = User::factory()->create();
        $this->contact = $this->user->client()->contacts()->create(['type' => 'individual', 'first_name' => 'Maria', 'last_name' => 'Papadopoulou',
            'email' => 'maria@example.test', 'phone' => '+30.2101234567', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10431', 'country' => 'GR']);
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    private function domain(string $fqdn, DomainRegistrarConnection $connection, array $roles): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true,
            'registrar_connection_id' => $connection->id]);
        $attributes = ['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id,
            'status' => DomainStatus::Active, 'registrar_connection_id' => $connection->id];
        foreach ($roles as $role => $handle) {
            $attributes["{$role}_contact_id"] = $this->contact->id;
            $attributes["{$role}_handle"] = $handle;
        }

        return Domain::factory()->create($attributes);
    }

    public function test_each_handle_is_updated_once_with_its_domains(): void
    {
        // The .gr registry's contact is one per contact, shared by its domains; an OP handle is the domain's.
        $a = $this->domain('alpha.gr', $this->gr, ['registrant' => 'c60_mcs1', 'admin' => 'c60_mcs1']);
        $b = $this->domain('beta.gr', $this->gr, ['tech' => 'c60_mcs1']);
        $c = $this->domain('gamma.com', $this->op, ['registrant' => 'OP1-GR', 'billing' => 'OP1-GR']);
        $this->domain('pending.com', $this->op, ['admin' => null]); // never sent: no handle

        $targets = ContactPush::targets($this->contact);

        $this->assertSame([$this->gr->id . '|c60_mcs1' => [$a->id, $b->id], $this->op->id . '|OP1-GR' => [$c->id]], $targets);
    }

    public function test_a_changed_address_phone_or_email_is_sent_who_it_is_and_notes_are_not(): void
    {
        $this->domain('alpha.gr', $this->gr, ['registrant' => 'c60_mcs1']);
        Queue::fake();

        $this->contact->update(['notes' => 'staff only']);
        Queue::assertNothingPushed();

        $this->contact->update(['address1' => 'Odos 2', 'phone' => '+30.2107654321']);
        Queue::assertPushed(PushContactJob::class, fn (PushContactJob $job) => $job->handle === 'c60_mcs1' && $job->contactId === $this->contact->id);
    }

    public function test_openprovider_takes_the_details_never_the_name(): void
    {
        $domain = $this->domain('gamma.com', $this->op, ['registrant' => 'OP1-GR']);
        $sent = null;
        Http::fake(function (Request $request) use (&$sent) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), '/v1/customers/OP1-GR')) {
                $sent = $request->data();

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
            }

            return Http::response([], 404);
        });

        $this->contact->forceFill(['address1' => 'Odos 12', 'email' => 'new@example.test'])->save();

        $this->assertSame('new@example.test', $sent['email'] ?? null);
        $this->assertSame(['Odos', '12', 'GR'], [$sent['address']['street'] ?? null, $sent['address']['number'] ?? null, $sent['address']['country'] ?? null]);
        $this->assertArrayNotHasKey('name', $sent);
        $this->assertArrayNotHasKey('company_name', $sent);
        $this->assertSame(0, ModuleAction::query()->where('domain_id', $domain->id)->where('status', ModuleAction::STATUS_OPEN)->count());
    }

    public function test_the_gr_registry_takes_a_contact_update_without_a_name(): void
    {
        $this->domain('alpha.gr', $this->gr, ['registrant' => 'c60_mcs1']);
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . '<result code="1000"><msg>ok</msg></result><trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>'));

        $this->contact->update(['address1' => 'Odos 3', 'email' => 'new@example.test']);

        $update = collect($this->transport->sent)->pluck('body')->first(fn (string $body) => str_contains($body, 'contact:update'));
        $this->assertNotNull($update, 'a contact:update was sent');
        $this->assertStringContainsString('c60_mcs1', $update);
        $this->assertStringContainsString('Odos 3', $update);
        $this->assertStringContainsString('new@example.test', $update);
        $this->assertStringNotContainsString('<contact:name>', $update);
    }

    public function test_a_push_that_keeps_failing_is_a_pending_module_action_staff_retry(): void
    {
        $domain = $this->domain('gamma.com', $this->op, ['registrant' => 'OP1-GR']);
        Http::fake(fn (Request $request) => str_contains($request->url(), '/auth/login')
            ? Http::response(['data' => ['token' => 'tok']]) : Http::response(['code' => 399, 'desc' => 'Invalid zipcode'], 400));
        $job = new PushContactJob($this->contact->id, 'OP1-GR', [$domain->id]);
        $job->tries = 1; // its last attempt

        $job->handle(app(DomainRegistrarFactory::class));

        $action = ModuleAction::query()->where('domain_id', $domain->id)->sole();
        $this->assertSame([ModuleAction::DOMAIN_CONTACT, ModuleAction::STATUS_OPEN], [$action->action, $action->status]);

        Queue::fake();
        $this->assertSame(1, ContactPush::retryDomain($domain->id));
        Queue::assertPushed(PushContactJob::class);
    }

    public function test_a_success_closes_an_open_failure(): void
    {
        $domain = $this->domain('gamma.com', $this->op, ['registrant' => 'OP1-GR']);
        ModuleAction::domainFailed($domain, null, 'openprovider', new \RuntimeException('down'), ModuleAction::DOMAIN_CONTACT);
        Http::fake(fn (Request $request) => str_contains($request->url(), '/auth/login')
            ? Http::response(['data' => ['token' => 'tok']]) : Http::response(['code' => 0, 'data' => ['success' => true]]));

        (new PushContactJob($this->contact->id, 'OP1-GR', [$domain->id]))->handle(app(DomainRegistrarFactory::class));

        $this->assertSame(0, ModuleAction::query()->where('domain_id', $domain->id)->where('status', ModuleAction::STATUS_OPEN)->count());
    }
}
