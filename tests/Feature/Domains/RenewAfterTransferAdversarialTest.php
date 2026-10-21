<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #304: a transfer that replaced a paid renewal and added no year, its line opened again after a
 * day and renewed at the target (DomainSyncService::renewAfterTransfer). Http::fake only. ADV-n tests FAIL on c012dc05;
 * the guards pass.
 */
class RenewAfterTransferAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> CNIC commands */
    private array $cnic = [];

    /** @var list<array{0: string, 1: array}> Openprovider POSTs */
    private array $op = [];

    private ?string $auth = 'Epp-Code-123';

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

    /** The target's expiry does not show a renewal yet. */
    private bool $lagRenew = false;

    private function opRenew()
    {
        $this->opRenewals++;
        if ($this->lagRenew) {
            return Http::response(['code' => 0, 'data' => ['status' => 'ACT']]);
        }
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

    /** Sent, then done at the target without the year: short_since noted at $doneAt. */
    private function doneShort(string $doneAt = '2026-10-21 12:00:00'): array
    {
        [$domain, $line] = $this->paidRenewal();
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        Carbon::setTestNow($doneAt);
        $this->opStatus = 'ACT';
        $this->opExpiry = '2026-11-01 00:00:00'; // its transfer added no year
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertNotEmpty($domain->fresh()->meta['renew_transfer']['short_since']);

        return [$domain, $line];
    }

    /**
     * ADV-1 (high, FAILS): a renewal transfer completes days before its expiry; the 24h wait carries it past it, the lifecycle
     * moves it to Grace, and renewAfterTransfer only looks at Active domains: the paid line is never opened again, staff are
     * never told (the old code's Pending Module Action is gone), and the name the customer paid for lapses.
     */
    public function test_adv1_a_short_transfer_that_lapses_during_the_wait_is_renewed_or_told(): void
    {
        [$domain, $line] = $this->doneShort('2026-10-31 10:00:00');

        Carbon::setTestNow('2026-11-02 12:00:00');
        app(DomainLifecycle::class)->advance($domain->refresh());
        $this->assertNotSame(DomainStatus::Active, $domain->fresh()->status, 'past its expiry: lapsed here');
        app(DomainSyncService::class)->sync($domain->refresh());

        $this->assertTrue($line->fresh()->applied_at === null || ModuleAction::query()->open()->where('domain_id', $domain->id)->exists(),
            'paid for a year it never got: opened again to be renewed (or restored) at the target, or staff told; never silently left to lapse');
    }

    /**
     * ADV-2 (medium, FAILS): the reopened line is renewed by the ordinary path, which transfers again when the target has a
     * «transfer it to» of its own (only a direct cycle is refused): unlocked at the target, its EPP read, then refused by the
     * prior still on record. Never renewed there, logged refused for the line: the cron never tries again.
     */
    public function test_adv2_a_reopened_line_is_renewed_at_the_target_never_transferred_again(): void
    {
        $third = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP2', 'is_active' => true, 'mode' => 'production']);
        $third->storeCredentials(['username' => 'u2', 'password' => 'Op2-Secret'], ['password']);
        [$domain, $line] = $this->doneShort();
        $this->to->update(['renew_transfer_to_id' => $third->id]);

        Carbon::setTestNow('2026-10-22 13:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertNull($line->fresh()->applied_at, 'opened again');

        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertSame(1, $this->opRenewals, 'the year its transfer did not add, renewed at the target');
        $this->assertSame(1, DomainRegistrarLog::query()->where('action', 'transfer')->count(), 'never a second transfer');
    }

    /**
     * ADV-3 (low, FAILS): the target turned inactive after the line was opened again: staff are asked, and «Renewed by hand»
     * takes the manual branch, which mails «renewed» unconditionally: the customer, told when the transfer was sent, is told
     * twice (apply()'s reopened_at guard is not on that branch).
     */
    public function test_adv3_renewed_by_hand_after_a_reopen_does_not_mail_the_customer_again(): void
    {
        [$domain, $line] = $this->doneShort();
        Carbon::setTestNow('2026-10-22 13:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->assertNull($line->fresh()->applied_at);
        $this->to->update(['is_active' => false]);
        $this->assertSame(DomainRenewalService::WAITING, app(DomainRenewalService::class)->renewLine($line->id));

        app(DomainRenewalService::class)->markRenewedByHand($line->fresh(), null);

        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertCount(1, $this->mails('domain_renewed'), 'told «renewed» once, when it was sent');
    }

    /** Guard (passes): a line refunded or cancelled after its transfer is opened again but never charged. */
    public function test_guard_a_refunded_line_is_never_charged_at_the_target(): void
    {
        $this->deadLineIsNeverCharged(Invoice::STATUS_REFUNDED);
    }

    public function test_guard_a_cancelled_line_is_never_charged_at_the_target(): void
    {
        $this->deadLineIsNeverCharged(Invoice::STATUS_CANCELLED);
    }

    private function deadLineIsNeverCharged(string $status): void
    {
        [$domain, $line] = $this->doneShort();
        DB::table('invoices')->where('id', $line->invoice_id)->update(['status' => $status]); // as its refund or cancel left it
        Carbon::setTestNow('2026-10-22 13:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        $this->artisan('app:cron-job')->assertExitCode(0);
        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertSame(0, $this->opRenewals, "{$status}: never charged");
    }

    /** Guard (passes): «Renew now» at the target inside the wait, its expiry lagging: the reopen adopts it, no second charge. */
    public function test_guard_renew_now_inside_the_wait_is_never_charged_twice(): void
    {
        [$domain, $line] = $this->doneShort();
        $this->lagRenew = true;
        $renewed = app(DomainRenewalService::class)->renewNow($domain->refresh(), Staff::factory()->create());
        $this->assertSame($line->id, $renewed->id, 'the paid line, renewed now');
        $this->assertSame(1, $this->opRenewals);

        Carbon::setTestNow('2026-10-22 13:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainRenewalService::class)->renewLine($line->id);
        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertSame(1, $this->opRenewals, 'charged once');
        $this->assertNotNull($line->fresh()->applied_at);
    }

    /** Guard (passes): two syncs after the wait open it once; the renewal charges once; later syncs never reopen it. */
    public function test_guard_two_syncs_reopen_once(): void
    {
        [$domain, $line] = $this->doneShort();
        Carbon::setTestNow('2026-10-22 13:00:00');
        $stale = $domain->fresh();
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainSyncService::class)->sync($stale);
        app(DomainRenewalService::class)->renewLine($line->id);
        $this->lagRenew = true;
        Carbon::setTestNow('2026-10-25 13:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertSame(1, $this->opRenewals);
        $this->assertNotNull($line->fresh()->applied_at);
    }

    /** Guard (passes): next year's renewal at the target is charged and mailed as any other (the reopen guard is per line). */
    public function test_guard_next_years_renewal_is_billed_charged_and_told(): void
    {
        [$domain, $line] = $this->doneShort();
        Carbon::setTestNow('2026-10-22 13:00:00');
        app(DomainSyncService::class)->sync($domain->refresh());
        app(DomainRenewalService::class)->renewLine($line->id);
        $this->assertSame('2027-11-01', $domain->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2027-10-21 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $next = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->latest('id')->firstOrFail();
        $this->assertNotSame($line->id, $next->id);
        (new RecordPaymentService)->handle($next->invoice, null, (float) $next->invoice->fresh()->total, transactionId: 'tx-' . $next->id);
        app(DomainRenewalService::class)->renewLine($next->id);

        $this->assertSame(2, $this->opRenewals);
        $this->assertSame('2028-11-01', $domain->fresh()->expires_at->toDateString());
        $this->assertCount(2, $this->mails('domain_renewed'), 'last year at its transfer, this year at its renewal');
    }
}
