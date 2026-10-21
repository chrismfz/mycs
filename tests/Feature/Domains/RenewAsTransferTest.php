<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Step 4 (owner 2026-10-21): a paid renewal at a connection set to «transfer it to X» is transferred there instead (the
 * WHMCS hook DomainMigration, CNIC → Openprovider, as a setting): unlocked, its EPP code read, the transfer asked of the
 * target with its nameservers and its own contacts; the domain moves only once the target took it.
 */
class RenewAsTransferTest extends TestCase
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
            'renew_transfer_to_id' => $this->to->id, 'renew_transfer_except' => ['eu', 'uk']]);
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

    public function test_a_paid_renewal_is_transferred_to_the_target_not_renewed_here(): void
    {
        [$domain, $line] = $this->paidRenewal();

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(DomainRenewalService::NOTHING, app(DomainRenewalService::class)->renewLine($line->id), 'applied: never again');

        $this->assertSame(0, $this->sent('RenewDomain'), 'never renewed at the source');
        $this->assertStringContainsString('TRANSFERLOCK=0', collect($this->cnic)->first(fn ($c) => str_starts_with($c, 'COMMAND=ModifyDomain')));
        $this->assertCount(1, $this->op);
        [, $sent] = $this->op[0];
        $this->assertSame('Epp-Code-123', $sent['auth_code']);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], array_column($sent['name_servers'], 'name'));
        $this->assertSame('MP000001-GR', $sent['owner_handle'], 'its own contact at the target, never the source\'s handle');

        $domain->refresh();
        $this->assertSame([$this->to->id, DomainStatus::PendingTransfer], [$domain->registrar_connection_id, $domain->status]);
        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertSame('P-CNIC1', $domain->meta['renew_transfer']['prior']['handles']['registrant'], 'the source\'s handle kept for the record');
        $log = DomainRegistrarLog::query()->where('action', 'transfer')->sole();
        $this->assertSame([DomainRegistrarLog::STATUS_OK, true], [$log->status, $log->request['renewal']]);
        $this->assertStringNotContainsString('Epp-Code-123', json_encode([$log->toArray(), $domain->meta, $domain->epp_code]), 'its EPP code is never stored');
    }

    public function test_a_refusal_leaves_it_where_it_was_as_it_was(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->target = 'refuse';

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));

        $domain->refresh();
        $this->assertSame([$this->from->id, DomainStatus::Active, 'P-CNIC1'], [$domain->registrar_connection_id, $domain->status, $domain->registrant_handle]);
        $this->assertArrayNotHasKey('renew_transfer', $domain->meta);
        $this->assertSame('P-CNIC1', $domain->meta['contact_handles'][$domain->registrant_contact_id]);
        $this->assertNull($line->fresh()->applied_at);
        $this->assertSame(2, $this->sent('ModifyDomain'), 'unlocked, then locked again');
        $this->assertStringContainsString('TRANSFERLOCK=1', collect($this->cnic)->last(fn ($c) => str_starts_with($c, 'COMMAND=ModifyDomain')));
        $this->assertSame(ModuleAction::STATUS_OPEN, ModuleAction::query()->sole()->status);

        $this->assertSame(DomainRenewalService::WAITING, app(DomainRenewalService::class)->renewLine($line->id), 'refused: never again by the cron on its own');
    }

    public function test_no_epp_code_changes_nothing(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->auth = null;

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));

        $this->assertSame([], $this->op);
        $this->assertSame([$this->from->id, DomainStatus::Active], [$domain->fresh()->registrar_connection_id, $domain->fresh()->status]);
        $this->assertStringContainsString('TRANSFERLOCK=1', collect($this->cnic)->last(fn ($c) => str_starts_with($c, 'COMMAND=ModifyDomain')));
        $this->assertStringContainsString('EPP code', ModuleAction::query()->sole()->last_error);
    }

    public function test_no_answer_is_pending_and_never_asked_again_blindly(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->target = 'down';

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'transfer')->sole()->status);

        $this->target = null;
        $this->assertSame(DomainRenewalService::WAITING, app(DomainRenewalService::class)->renewLine($line->id, retry: true));
        $this->assertSame([], $this->op);
        $this->assertSame(0, $this->sent('RenewDomain'));
    }

    public function test_an_excepted_tld_is_renewed_here(): void
    {
        [$domain, $line] = $this->paidRenewal('eu');

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(1, $this->sent('RenewDomain'));
        $this->assertSame([], $this->op);
        $this->assertSame($this->from->id, $domain->fresh()->registrar_connection_id);
    }

    public function test_no_setting_renews_here(): void
    {
        $this->from->update(['renew_transfer_to_id' => null]);
        [, $line] = $this->paidRenewal();

        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertSame(1, $this->sent('RenewDomain'));
        $this->assertSame([], $this->op);
    }

    public function test_a_target_not_usable_is_staffs_never_a_renewal_here(): void
    {
        $this->to->update(['is_active' => false]);
        [, $line] = $this->paidRenewal();

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(0, $this->sent('RenewDomain'));
        $this->assertSame(0, $this->sent('ModifyDomain'));
    }

    public function test_the_customer_is_told_renewed_once_never_transferred(): void
    {
        [$domain, $line] = $this->paidRenewal();
        app(DomainRenewalService::class)->renewLine($line->id);
        $this->assertSame(['domain_renewed'], $this->mails('domain_renewed'));

        $this->opStatus = 'ACT';
        app(DomainSyncService::class)->sync($domain->refresh());

        $domain->refresh();
        $this->assertSame([DomainStatus::Active, $this->to->id, '2027-11-01'], [$domain->status, $domain->registrar_connection_id, $domain->expires_at->toDateString()]);
        $this->assertNotNull($domain->meta['renew_transfer']['done_at']);
        $this->assertSame([], $this->mails('domain_transferred'), 'a renewal to the customer, not a transfer');
        $this->assertCount(1, $this->mails('domain_renewed'));
    }

    public function test_a_transfer_that_added_no_year_is_told_to_staff(): void
    {
        [$domain, $line] = $this->paidRenewal();
        app(DomainRenewalService::class)->renewLine($line->id);

        $this->opStatus = 'ACT';
        $this->opExpiry = '2026-11-01 00:00:00'; // a ccTLD whose transfer adds no year, left off the exceptions
        app(DomainSyncService::class)->sync($domain->refresh());

        $row = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();
        $this->assertSame($line->id, $row->invoice_item_id);
        $this->assertStringContainsString('added no year', $row->last_error);
    }
}
