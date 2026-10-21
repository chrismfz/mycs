<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Staff;
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
 * Adversarial review of #307: RenewAsTransfer::revert locks the domain again at its source registrar when the transfer
 * that replaced its paid renewal is later reported failed. CNIC (source, with a transfer lock that remembers its state)
 * → Openprovider (target). test_adv* fail on the PR head and show a defect; test_guard* pass and pin what holds.
 */
class Pr307AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const EPP = 'Epp-Code-307xyz';

    /** @var list<string> CNIC commands */
    private array $cnic = [];

    private bool $cnicLocked = true;

    /** CNIC refuses to lock it again (TRANSFERLOCK=1). */
    private bool $lockFails = false;

    /** null: the target takes it; 'down': 5xx (no clear answer) */
    private ?string $target = null;

    private string $opStatus = 'REQ';

    private int $opTransfers = 0;

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
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->to = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->to->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->from = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production',
            'renew_transfer_to_id' => $this->to->id]);
        $this->from->storeCredentials(['username' => 'reseller', 'password' => 'Cnic-Secret-1'], ['password']);
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
        if (str_starts_with($command, 'COMMAND=ModifyDomain')) {
            if (str_contains($command, 'TRANSFERLOCK=1') && $this->lockFails) {
                return Http::response("[RESPONSE]\ncode = 531\ndescription = Authorization failed\nEOF\n");
            }
            if (str_contains($command, 'TRANSFERLOCK=0')) {
                $this->cnicLocked = false;
            } elseif (str_contains($command, 'TRANSFERLOCK=1')) {
                $this->cnicLocked = true;
            }

            return Http::response("[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n");
        }
        if (str_starts_with($command, 'COMMAND=RenewDomain')) {
            return Http::response("[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nproperty[REGISTRATIONEXPIRATIONDATE][0] = 2027-11-01 00:00:00.0\nEOF\n");
        }
        $lock = $this->cnicLocked ? 1 : 0;
        $paid = $this->sent('RenewDomain') > 0 ? '2027-11-01' : '2026-11-01';

        return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\nproperty[PAIDUNTIL DATE][0] = {$paid} 00:00:00.0\n"
            . "property[STATUS][0] = ACTIVE\nproperty[TRANSFER LOCK][0] = {$lock}\nproperty[NAMESERVER][0] = ns1.example.net\nproperty[NAMESERVER][1] = ns2.example.net\n"
            . 'property[OWNER CONTACT][0] = P-CNIC1' . "\nproperty[AUTH][0] = " . self::EPP . "\nEOF\n");
    }

    private function opAnswer(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $record = ['id' => 9001, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => $this->opStatus,
            'expiration_date' => '2027-11-01 00:00:00', 'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']],
            'owner_handle' => 'MP000001-GR', 'admin_handle' => 'MP000001-GR', 'tech_handle' => 'MP000001-GR', 'billing_handle' => 'MP000001-GR'];

        return match (true) {
            str_contains($path, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => Http::response(['data' => ['handle' => 'MP000001-GR']]),
            $request->method() === 'POST' && str_ends_with($path, '/domains/transfer') => (function () {
                if ($this->target === 'down') {
                    return Http::response([], 503);
                }
                $this->opTransfers++;

                return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => 'REQ']]);
            })(),
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->opTransfers === 0 ? [] : [$record],
                'total' => $this->opTransfers === 0 ? 0 : 1] + ($this->opTransfers === 0 ? [] : $record)]),
            default => Http::response([], 404),
        };
    }

    private function sent(string $command, ?string $with = null): int
    {
        return collect($this->cnic)->filter(fn ($c) => str_starts_with($c, "COMMAND={$command}") && ($with === null || str_contains($c, $with)))->count();
    }

    /** @return array{0: Domain, 1: InvoiceItem} */
    private function paidRenewal(): array
    {
        $domainTld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);
        DomainTldPrice::query()->create(['domain_tld_id' => $domainTld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
        $user = User::factory()->create();
        $contact = $user->client()->ensurePrimaryContact();
        $contact->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test', 'phone' => '+30.2100000000',
            'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $domainTld->id, 'registrar_connection_id' => $this->from->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'auto_renew' => true, 'years' => 1, 'registrant_contact_id' => $contact->id, 'registrant_handle' => 'P-CNIC1',
            'meta' => ['contact_handles' => [$contact->id => 'P-CNIC1']]]);
        $domain->nameservers()->create(['host' => 'ns1.example.net', 'sort' => 1]);
        $domain->nameservers()->create(['host' => 'ns2.example.net', 'sort' => 2]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return [$domain, $line->fresh()];
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /** Sent and taken (REQ), then reported failed (FAI) by the target's sync: RenewAsTransfer::revert. */
    private function transferredThenFailed(Domain $domain, InvoiceItem $line, bool $retry = false): void
    {
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id, retry: $retry));
        $this->assertSame($this->to->id, $domain->fresh()->registrar_connection_id, 'taken by the target');
        $this->opStatus = 'FAI';
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertSame([$this->from->id, DomainStatus::Active], [$domain->fresh()->registrar_connection_id, $domain->fresh()->status], 'back at its source');
    }

    /**
     * ADV-1 (high). A first run gets no clear answer from the target (5xx): it stays at its source, unlocked there (on
     * purpose, it may be under way). Staff check the target, find nothing, «Close» the row and Retry. The second run reads
     * the source: «transfer lock: off» (our own unlock of the first run), so it unlocks nothing and records
     * renew_transfer.unlocked = false. The target takes it, then reports FAI: revert() trusts the flag and never locks it
     * again. The name stays open to anyone with its code, the exact hole #307 closes.
     * Fix: record «unlocked by us» across runs (keep it in renew_transfer_unknown and carry it into the next run's record,
     * or set unlocked = true when the source shows a lock off that a prior renewal transfer of ours left), or relock on
     * revert whenever the source shows it unlocked and nothing says the customer unlocked it.
     */
    public function test_adv1_a_retry_after_no_clear_answer_forgets_it_was_unlocked_by_us_and_a_later_fai_never_relocks(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->target = 'down';
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertFalse($this->cnicLocked, 'unlocked by us for the first run');

        ModuleAction::query()->where('domain_id', $domain->id)->sole()->closeUnrenewed($this->staff());
        $this->target = null;
        $this->transferredThenFailed($domain, $line, retry: true);

        $this->assertTrue($this->cnicLocked, 'unlocked by us (first run) for a transfer that failed: locked again at its source');
    }

    /**
     * ADV-2 (high). The lock at revert fails (CNIC refuses it). lockAtSource() writes «still unlocked … lock it there by
     * hand» into ModuleAction::domainFailed(domain, line, DOMAIN_RENEW) — the very row revert() opened for the renewal
     * at the source. The next cron run renews the line at the source and domainSucceeded() completes that row: the only
     * record that the name is still unlocked is closed as «succeeded», and nothing ever tries the lock again.
     * Fix: a row of its own for the lock (a distinct subject, e.g. 'relock', or action), never closed by the renewal's
     * success; or retry the lock on the renewal at the source (renew_here_line) and only then close.
     */
    public function test_adv2_a_failed_relock_is_closed_as_succeeded_by_the_renewal_at_the_source(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->lockFails = true;
        $this->transferredThenFailed($domain, $line);
        $this->assertStringContainsString('lock it there by hand', (string) ModuleAction::query()->open()->where('domain_id', $domain->id)->latest('id')->value('last_error'));

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id), 'renewed at its source');
        $this->assertSame(1, $this->sent('RenewDomain'));
        $this->assertFalse($this->cnicLocked, 'still unlocked at CNIC');

        $this->assertTrue(ModuleAction::query()->open()->where('domain_id', $domain->id)->where('last_error', 'like', '%lock%')->exists(),
            'a name still unlocked at its registrar stays open for staff until it is locked');
    }

    /**
     * ADV-3 (medium). Same failed lock: staff are mailed once, when revert() opens its row («… the next run renews it
     * there»). The lock failure lands on that row already open, so ModuleAction::domainFailed sends no mail: no one is
     * ever told by mail that the name is unlocked at its old registrar.
     * Fix: its own row (as ADV-2), which mails on open; or send the mail explicitly from lockAtSource().
     */
    public function test_adv3_staff_are_never_mailed_that_the_relock_failed(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->lockFails = true;
        $this->transferredThenFailed($domain, $line);

        $mails = EmailLog::query()->where('template_key', 'system')->get();
        $this->assertNotEmpty($mails, 'staff mailed about the failed transfer');
        $this->assertTrue($mails->contains(fn ($mail) => str_contains($mail->body, 'unlocked')),
            'a staff mail says it is still unlocked at its old registrar: ' . $mails->pluck('subject')->implode(' | '));
    }

    /**
     * ADV-4 (medium, owner decision to confirm). No clear answer: left unlocked on purpose, «staff resolve it». Staff
     * resolve it with «Close» (checked at the target: not done; ModuleAction::closeUnrenewed marks the pending transfer
     * log failed). Nothing locks it then either: the name is left unlocked at the source for good, with no row open.
     * Fix: closeUnrenewed on a renewal whose pending log is a renewal transfer (request.renewal) relocks at the source
     * (meta.renew_transfer_unknown.from), or opens a «lock it by hand» row.
     */
    public function test_adv4_staff_closing_a_transfer_with_no_clear_answer_leaves_it_unlocked_for_good(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->target = 'down';
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));

        ModuleAction::query()->where('domain_id', $domain->id)->sole()->closeUnrenewed($this->staff());

        $this->assertTrue($this->cnicLocked || ModuleAction::query()->open()->where('domain_id', $domain->id)->exists(),
            'closed as not done: locked again at its source, or still open for staff to lock it');
    }

    /** The happy path: locked again once, at CNIC (its source), logged; its EPP code nowhere; the line renewed there. */
    public function test_guard_relocked_once_at_the_source_and_renewed_there_without_leaking_its_code(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->transferredThenFailed($domain, $line);

        $this->assertTrue($this->cnicLocked);
        $this->assertSame(1, $this->sent('ModifyDomain', 'TRANSFERLOCK=1'));
        $lock = DomainRegistrarLog::query()->where('action', 'lock')->sole();
        $this->assertSame([$this->from->id, DomainRegistrarLog::STATUS_OK], [$lock->registrar_connection_id, $lock->status]);

        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertSame(1, $this->sent('ModifyDomain', 'TRANSFERLOCK=1'), 'a later sync locks nothing again');

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(1, $this->sent('RenewDomain'), 'renewed at its source: its expiry');
        $this->assertNotNull($line->fresh()->applied_at);

        $stored = json_encode([DomainRegistrarLog::query()->get()->toArray(), ModuleAction::query()->get()->toArray(), $domain->fresh()->meta,
            EmailLog::query()->get()->toArray()]);
        $this->assertStringNotContainsString(self::EPP, $stored, 'its EPP code is never stored');
    }

    /** A domain the customer had unlocked (lock off before we touched it): no lock sent at revert. */
    public function test_guard_no_lock_where_we_never_unlocked_it(): void
    {
        $this->cnicLocked = false;
        [$domain, $line] = $this->paidRenewal();
        $this->transferredThenFailed($domain, $line);

        $this->assertSame(0, $this->sent('ModifyDomain', 'TRANSFERLOCK=1'));
        $this->assertSame(0, DomainRegistrarLog::query()->where('action', 'lock')->count());
    }

    /** The source connection inactive at revert: nothing sent to it, reverted all the same, staff told to lock it by hand. */
    public function test_guard_source_inactive_still_reverts_and_says_lock_by_hand(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->from->update(['is_active' => false]);
        $this->opStatus = 'FAI';
        $before = $this->sent('ModifyDomain', 'TRANSFERLOCK=1');

        app(DomainSyncService::class)->sync($domain->refresh());

        $this->assertSame($before, $this->sent('ModifyDomain', 'TRANSFERLOCK=1'));
        $this->assertSame([$this->from->id, DomainStatus::Active], [$domain->fresh()->registrar_connection_id, $domain->fresh()->status]);
        $this->assertNull($line->fresh()->applied_at, 'its line open again: renewed at its source');
        // On a row of its own, which no renewal closes (ADV-2 of #307).
        $this->assertStringContainsString('lock it there by hand', (string) ModuleAction::query()->open()->where('domain_id', $domain->id)
            ->where('subject', ModuleAction::SUBJECT_RELOCK)->value('last_error'));
    }

    /** A failed lock never throws out of revert: reverted fully, the line open for its renewal at the source. */
    public function test_guard_a_failed_lock_leaves_the_revert_whole(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->lockFails = true;
        $this->transferredThenFailed($domain, $line);

        $domain->refresh();
        $this->assertSame('P-CNIC1', $domain->registrant_handle);
        $this->assertArrayNotHasKey('renew_transfer', $domain->meta);
        $this->assertSame($line->id, $domain->meta['renew_here_line']);
        $this->assertNull($line->fresh()->applied_at);
    }
}
