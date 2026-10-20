<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Jobs\Domains\PushContactJob;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\ContactPush;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Mcp\Proposals;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #248 (P1b-2, a contact's details pushed to the registrars). Failing tests are findings; the
 * test_holds_… ones pin behaviour that is right. No real registrar: Openprovider is Http::fake, the .gr registry a
 * FakeTransport.
 */
class ContactPushAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    private DomainRegistrarConnection $op;

    private User $user;

    private Contact $contact;

    /** @var list<array{url: string, data: array<string, mixed>}> */
    private array $puts = [];

    private bool $fail = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        GrEppRegistrar::$transport = fn () => new FakeTransport; // nothing queued: any .gr command would fail, never leave
        GrEppRegistrar::reset();
        $this->user = User::factory()->create();
        $this->contact = $this->makeContact($this->user, 'maria@example.test');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'PUT') {
                $this->puts[] = ['url' => $request->url(), 'data' => $request->data()];

                return $this->fail ? Http::response(['code' => 399, 'desc' => 'Invalid zipcode'], 400) : Http::response(['code' => 0, 'data' => ['success' => true]]);
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    private function makeContact(User $user, string $email): Contact
    {
        return $user->client()->contacts()->create(['type' => 'individual', 'first_name' => 'Maria', 'last_name' => 'P',
            'email' => $email, 'phone' => '+30.2101234567', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10431', 'country' => 'GR']);
    }

    /** @param array<string, array{0: Contact, 1: ?string}> $roles */
    private function domain(string $fqdn, DomainRegistrarConnection $connection, array $roles, ?int $clientId = null): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true,
            'registrar_connection_id' => $connection->id]);
        $attributes = ['client_id' => $clientId ?? $this->user->client()->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id,
            'status' => DomainStatus::Active, 'registrar_connection_id' => $connection->id];
        foreach ($roles as $role => [$contact, $handle]) {
            $attributes["{$role}_contact_id"] = $contact->id;
            $attributes["{$role}_handle"] = $handle;
        }

        return Domain::factory()->create($attributes);
    }

    private function runJob(PushContactJob $job, bool $last = true): void
    {
        if ($last) {
            $job->tries = 1;
        }
        $job->handle(app(DomainRegistrarFactory::class));
    }

    // ---- sends what it must not -------------------------------------------------------------------------------------

    /**
     * A1 (high): the same handle is a contact of another account's domain (a .gr registry contact is shared by its
     * domains; a handle linked by staff or matched by email at a sync can be an agency's). One account's edit rewrites
     * the other account's WHOIS.
     */
    public function test_a_handle_another_accounts_domain_uses_is_never_sent_this_accounts_details(): void
    {
        $other = User::factory()->create();
        $theirs = $this->makeContact($other, 'agency@example.test');
        $this->domain('ours.gr', $this->gr, ['admin' => [$this->contact, 'c60_shared']]);
        $this->domain('theirs.gr', $this->gr, ['admin' => [$theirs, 'c60_shared']], $other->client()->id);
        Queue::fake();

        $this->contact->update(['address1' => 'Odos 99']);

        Queue::assertNotPushed(PushContactJob::class, fn (PushContactJob $job) => $job->handle === 'c60_shared');
    }

    /** A2 (high): the same, within one account: two contacts here are one handle there; X's edit becomes Y's WHOIS. */
    public function test_a_handle_another_contact_uses_is_never_sent_this_contacts_details(): void
    {
        $y = $this->makeContact($this->user, 'y@example.test');
        $this->domain('one.com', $this->op, ['tech' => [$this->contact, 'OP-SHARED']]);
        $this->domain('two.com', $this->op, ['tech' => [$y, 'OP-SHARED']]);
        Queue::fake();

        $this->contact->update(['email' => 'x-new@example.test']);

        Queue::assertNotPushed(PushContactJob::class, fn (PushContactJob $job) => $job->handle === 'OP-SHARED');
    }

    /** A3 (high): the job trusts what was true at dispatch; the domain transferred away before it ran. */
    public function test_a_job_for_a_domain_that_ended_before_it_ran_sends_nothing(): void
    {
        $domain = $this->domain('gone.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);
        $job = new PushContactJob($this->contact->id, 'OP1-GR', [$domain->id]);
        $domain->forceFill(['status' => DomainStatus::TransferredAway])->save();

        $this->runJob($job);

        $this->assertSame([], $this->puts, 'nothing sent for a domain no longer ours');
    }

    /** A4 (high): the role's handle is now another contact's (staff linked/relinked it) before the job ran. */
    public function test_a_job_whose_handle_is_now_another_contacts_sends_nothing(): void
    {
        $y = $this->makeContact($this->user, 'y@example.test');
        $domain = $this->domain('swap.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);
        $job = new PushContactJob($this->contact->id, 'OP1-GR', [$domain->id]);
        $domain->forceFill(['registrant_contact_id' => $y->id, 'registrant_handle' => 'OP1-GR'])->save();

        $this->runJob($job);

        $this->assertSame([], $this->puts, 'X\'s details never reach the handle that is Y\'s now');
    }

    /** A5 (high): the domain moved to another account before the job ran. */
    public function test_a_job_for_a_domain_moved_to_another_account_sends_nothing(): void
    {
        $domain = $this->domain('moved.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);
        $job = new PushContactJob($this->contact->id, 'OP1-GR', [$domain->id]);
        $domain->forceFill(['client_id' => User::factory()->create()->client()->id])->save();

        $this->runJob($job);

        $this->assertSame([], $this->puts);
    }

    /** A6 (medium): staff's Retry of an old failure after the domain ended sends to a registrar no longer ours. */
    public function test_staff_retry_for_a_domain_that_ended_sends_nothing(): void
    {
        $domain = $this->domain('ended.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);
        ModuleAction::domainFailed($domain, null, 'openprovider', new \RuntimeException('down'), ModuleAction::DOMAIN_CONTACT);
        $domain->forceFill(['status' => DomainStatus::TransferredAway])->save();
        Queue::fake();

        $this->assertSame(0, ContactPush::retryDomain($domain->id));
        Queue::assertNotPushed(PushContactJob::class);
    }

    /** A7 (medium): a connection staff switched off (is_active false / mode off) is still written. */
    public function test_a_registrar_connection_switched_off_is_never_written(): void
    {
        $this->domain('off.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);
        $this->op->forceFill(['is_active' => false, 'mode' => 'off'])->save();

        $this->contact->update(['address1' => 'Odos 5']);

        $this->assertSame([], $this->puts, 'nothing sent through a switched-off connection');
    }

    // ---- leaves staff without a way out -----------------------------------------------------------------------------

    /**
     * A8 (medium): the failure row is per domain, not per handle: another role's (another contact's) push succeeding
     * closes it, while the failed handle still has stale details at the registrar.
     */
    public function test_another_roles_success_never_closes_a_failed_roles_action(): void
    {
        $y = $this->makeContact($this->user, 'y@example.test');
        $domain = $this->domain('mixed.com', $this->op, ['registrant' => [$this->contact, 'OP-R'], 'admin' => [$y, 'OP-A']]);
        $this->fail = true;
        $this->runJob(new PushContactJob($this->contact->id, 'OP-R', [$domain->id]));
        $this->assertSame(1, ModuleAction::query()->open()->where('domain_id', $domain->id)->count());

        $this->fail = false;
        $this->runJob(new PushContactJob($y->id, 'OP-A', [$domain->id]));

        $this->assertSame(1, ModuleAction::query()->open()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_CONTACT)->count(),
            'OP-R was never updated: its failure stays open');
    }

    /**
     * A9 (medium): a failure outside the try (the handle's lock not had in 30 s, a worker timeout) on the last attempt:
     * the job fails with no Pending Module Action (no failed() hook), so staff never learn of it.
     */
    public function test_a_lock_timeout_on_the_last_attempt_is_a_pending_module_action(): void
    {
        $domain = $this->domain('locked.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);
        $job = new PushContactJob($this->contact->id, 'OP1-GR', [$domain->id]);
        $job->tries = 1;
        Cache::partialMock()->shouldReceive('lock')->andReturn(new class
        {
            public function block(int $seconds, ?callable $callback = null): mixed
            {
                throw new LockTimeoutException;
            }
        });

        try {
            $job->handle(app(DomainRegistrarFactory::class));
        } catch (Throwable $e) {
            // What the worker does with a job that threw on its last attempt.
            if (method_exists($job, 'failed')) {
                $job->failed($e);
            }
        }

        $this->assertSame(1, ModuleAction::query()->open()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_CONTACT)->count());
    }

    /** A10 (low): staff's MCP way to retry it is refused forever («still being retried by the queue»: given_up_at never set). */
    public function test_an_mcp_retry_proposal_of_a_contact_push_is_accepted(): void
    {
        $domain = $this->domain('mcp.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);
        $action = ModuleAction::domainFailed($domain, null, 'openprovider', new \RuntimeException('down'), ModuleAction::DOMAIN_CONTACT);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);

        $proposal = Proposals::propose(Proposals::RETRY_MODULE_ACTION, ['module_action_id' => $action->id], $staff);

        $this->assertNotNull($proposal->id);
    }

    // ---- holds ------------------------------------------------------------------------------------------------------

    public function test_holds_a_change_of_country_only_is_pushed(): void
    {
        $this->domain('country.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);

        $this->contact->update(['country' => 'CY']);

        $this->assertCount(1, $this->puts);
        $this->assertSame('CY', $this->puts[0]['data']['address']['country'] ?? null);
        $this->assertArrayNotHasKey('name', $this->puts[0]['data']);
    }

    public function test_holds_a_name_change_of_a_locked_contact_sends_nothing(): void
    {
        $this->domain('name.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR']]);

        try {
            $this->contact->update(['first_name' => 'Eleni', 'address1' => 'Odos 7']);
        } catch (Throwable) {
            // refused (P1b-1)
        }

        $this->assertSame([], $this->puts);
        $this->assertSame('Maria', $this->contact->fresh()->first_name);
    }

    public function test_holds_a_handle_of_a_domain_already_ended_is_not_a_target(): void
    {
        $this->domain('old.com', $this->op, ['registrant' => [$this->contact, 'OP-OLD']])->forceFill(['status' => DomainStatus::TransferredAway])->save();
        $this->domain('dead.com', $this->op, ['registrant' => [$this->contact, 'OP-DEAD']])->forceFill(['status' => DomainStatus::Cancelled])->save();

        $this->assertSame([], ContactPush::targets($this->contact));
    }

    public function test_holds_a_success_after_staff_retry_closes_the_row_and_nothing_loops(): void
    {
        $domain = $this->domain('loop.com', $this->op, ['registrant' => [$this->contact, 'OP1-GR'], 'admin' => [$this->contact, 'OP1-GR']]);
        ModuleAction::domainFailed($domain, null, 'openprovider', new \RuntimeException('down'), ModuleAction::DOMAIN_CONTACT);

        ContactPush::retryDomain($domain->id); // sync queue in tests: runs now

        $this->assertSame(0, ModuleAction::query()->open()->where('domain_id', $domain->id)->count());
        $this->assertLessThanOrEqual(2, count($this->puts), 'one push per role at most, no echo');
    }
}
