<?php

namespace Tests\Feature\Domains;

use App\Admin\Widgets\Renewals\RecordsToCheck;
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
use App\Models\Order;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\RetryModuleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of feat/renew-as-transfer (9ea32655): a paid renewal transferred to another registrar connection
 * instead of renewed (RenewAsTransfer). Each test names its finding (RAT-ADV-n). FAILS = a problem on 9ea32655; GUARD =
 * passes and stays as a guard. Http::fake only: CNIC (source) and Openprovider (target).
 */
class RenewAsTransferAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> CNIC commands */
    private array $cnic = [];

    /** @var list<array{0: string, 1: array}> Openprovider transfer POSTs */
    private array $op = [];

    private ?string $auth = 'Epp-Code-123';

    /** null: takes it; 'refuse': 4xx; 'refuse-echo': 4xx echoing the code; 'down': 5xx */
    private ?string $target = null;

    /** Openprovider lists the domain (after a transfer it took, or when the test says so). */
    private bool $opHas = false;

    private string $opStatus = 'REQ';

    private string $opExpiry = '2027-11-01 00:00:00';

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
        $has = $this->opHas || $this->op !== [];

        return match (true) {
            str_contains($path, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => Http::response(['data' => ['handle' => 'MP000001-GR']]),
            $request->method() === 'POST' && str_ends_with($path, '/domains/transfer') => $this->opTransfer($path, $request->data()),
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $has ? [$this->record()] : [], 'total' => $has ? 1 : 0] + ($has ? $this->record() : [])]),
            default => Http::response([], 404),
        };
    }

    private function record(): array
    {
        return ['id' => 9001, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => $this->opStatus,
            'expiration_date' => $this->opExpiry, 'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']],
            'owner_handle' => 'MP000001-GR', 'admin_handle' => 'MP000001-GR', 'tech_handle' => 'MP000001-GR', 'billing_handle' => 'MP000001-GR'];
    }

    private function opTransfer(string $path, array $data)
    {
        if ($this->target === 'refuse') {
            return Http::response(['code' => 399, 'desc' => 'Invalid auth code'], 400);
        }
        if ($this->target === 'refuse-echo') {
            return Http::response(['code' => 399, 'desc' => 'Invalid auth code ' . ($data['auth_code'] ?? '')], 400);
        }
        if ($this->target === 'down') {
            return Http::response([], 503);
        }
        $this->op[] = [$path, $data];

        return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => 'REQ']]);
    }

    /** @return list<string> */
    private function mails(string $template): array
    {
        return Mail::queued(\App\Mail\Mail::class)->filter(fn ($mail) => $mail->templateKey === $template)->map(fn ($mail) => $mail->templateKey)->values()->all();
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

    /**
     * RAT-ADV-1 (HIGH) — FAILS. The batched sync (SyncDomains) loads domains 100 at a time and reads them one by one with a
     * pause between: a copy loaded before RenewAsTransfer moved the domain to the target is then synced at its OLD
     * connection (the stale model's registrar_connection_id), and DomainSyncService::apply() writes the stale copy's meta
     * back: meta.sent_at and meta.renew_transfer are gone. From then on transferOutcome() returns at once (no sent_at): a
     * transfer that fails at the target (FAI) is never told to staff, the customer was told «renewed», and the name
     * expires at the source. Before this branch a domain's connection never changed under the batch.
     * Fix: sync() re-reads the domain under its lock (and its connection) before reading the registrar, or apply() merges
     * into the stored meta, not the caller's copy.
     */
    public function test_rat_adv_1_a_stale_batch_copy_synced_after_the_transfer_loses_its_record_and_its_failure(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $stale = Domain::query()->findOrFail($domain->id); // the batch's copy, loaded before the renewal ran

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        try {
            app(DomainSyncService::class)->sync($stale);
        } catch (\Throwable) {
            // whatever the stale read does, what matters is what it left behind
        }

        $fresh = $domain->fresh();
        $this->assertSame($this->to->id, $fresh->registrar_connection_id);
        $this->assertArrayHasKey('renew_transfer', $fresh->meta ?? [], 'its record of the transfer that replaced the renewal survives a stale sync');
        $this->assertNotEmpty($fresh->meta['sent_at'] ?? null, 'sent_at survives a stale sync: transferOutcome needs it');

        // The consequence: the target reports the transfer failed, and staff must hear of it.
        $this->opStatus = 'FAI';
        app(DomainSyncService::class)->sync($domain->refresh());
        // Fix of RAT-ADV-4: the failure is the renewal's line (reverted, renewed at the source), not an order's transfer.
        $this->assertTrue(ModuleAction::query()->open()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_RENEW)->exists(),
            'a failed transfer that replaced a paid renewal reaches staff');
    }

    /**
     * RAT-ADV-2 (MEDIUM) — FAILS. A renewal transfer with no clear answer (5xx) stays «pending» (correct). It went through
     * after all: staff point the domain at the target, and the line is adopted (the target shows the year). apply()
     * settles only pending renew/restore logs, never this transfer: unconfirmed() keeps finding it, and every later paid
     * renewal of the domain (next year, and every year after) goes to staff as «A transfer asked on … had no clear
     * answer» instead of being renewed. The same trap ADV-1 of #302 closed for renewals.
     * Fix: apply() (and markRenewedByHand) also settles pending `transfer` logs with request.renewal.
     */
    public function test_rat_adv_2_a_renewal_transfer_found_done_later_is_settled_not_pending_forever(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->target = 'down';
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));

        // It went through at the target: staff set its registrar there; the target shows the year.
        $domain->refresh()->forceFill(['registrar_connection_id' => $this->to->id])->save();
        $this->opHas = true;
        $this->opStatus = 'ACT';
        $this->assertSame(DomainRenewalService::ADOPTED, app(DomainRenewalService::class)->renewLine($line->id, retry: true));
        $this->assertNotNull($line->fresh()->applied_at);

        $this->assertNotSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'transfer')->sole()->status,
            'settled once the registrar shows the period: else every later renewal of the domain is blocked');
    }

    /**
     * RAT-ADV-3 (MEDIUM) — FAILS. No clear answer from the target: the domain correctly stays at its source, but its
     * source handles were emptied before the call (clearHandles) and are put back only on a refusal; worse, the
     * target's adapter stored ITS handle (MP000001-GR, made for the transfer) on the domain. The domain is at CNIC with
     * Openprovider's handle and no meta.contact_handles: the next contact change pushes a foreign handle to CNIC; and a
     * later RenewAsTransfer run overwrites meta.renew_transfer.prior with these (the source's lost for good).
     * Fix: restore the handles on the unknown path too (the domain is still the source's until the target is known to
     * have it), or let the target's call use handles kept apart from the domain's until it took it.
     */
    public function test_rat_adv_3_no_answer_leaves_the_domain_at_its_source_with_its_handles(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->target = 'down';

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));

        $domain->refresh();
        $this->assertSame($this->from->id, $domain->registrar_connection_id, 'still at its source');
        $this->assertSame('P-CNIC1', $domain->registrant_handle, 'its source handle, as the domain is still there');
        $this->assertSame('P-CNIC1', $domain->meta['contact_handles'][$domain->registrant_contact_id] ?? null);
    }

    /**
     * RAT-ADV-4 (MEDIUM) — FAILS. The target took the request, the line was applied and the customer told «renewed»; later
     * the target reports the transfer failed (FAI). The name is still at its source with its old expiry, but here it stays
     * Pending Transfer at the TARGET connection, with the target's handles, its paid line applied although no year was
     * added. Staff are told to «set its registrar back» by hand; until they do, the sync reads the wrong registrar and the
     * source's handles live only in meta.renew_transfer.prior.
     * Fix: on a failed renewal transfer, put it back as it was (its source connection, Active, its prior handles,
     * registrar_domain_id), and un-apply the line (applied_at null) so the normal renewal path (refusedFor/staff) holds it.
     */
    public function test_rat_adv_4_a_renewal_transfer_that_fails_later_puts_the_domain_back_at_its_source(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));

        $this->opStatus = 'FAI';
        app(DomainSyncService::class)->sync($domain->refresh());

        $domain->refresh();
        $this->assertTrue(ModuleAction::query()->open()->where('domain_id', $domain->id)->exists(), 'staff told (passes)');
        $this->assertSame([$this->from->id, 'P-CNIC1'], [$domain->registrar_connection_id, $domain->registrant_handle],
            'back at its source, with its source handles');
        $this->assertNull($line->fresh()->applied_at, 'no year was added: the paid line is not applied');
    }

    /**
     * RAT-ADV-5 (MEDIUM) — FAILS. The failed renewal transfer is told to staff as a DOMAIN_TRANSFER row (transferOutcome).
     * For a domain that was ordered here once (order_id, a paid registration line from years ago), its Retry runs
     * DomainOrderFulfilment::fulfil(): open() is true (Pending Transfer + transfer_failed), lineOf() is the OLD order's
     * line, paid: it sends an ORDER transfer with the domain's stored epp_code (empty: refused locally; a code there: a
     * second transfer charged at the target, outside the renewal's guards and with no renewal line), and mails the
     * customer as for an order. Without an order_id the same Retry does nothing. The Retry of a renewal transfer must
     * not go through the order path.
     * Fix: transferOutcome() files a renewal transfer's failure as DOMAIN_RENEW on its renewal line (meta.renew_transfer.line),
     * or DomainOrderFulfilment::open() excludes meta.renew_transfer.
     */
    public function test_rat_adv_5_retry_of_a_failed_renewal_transfer_never_runs_the_order_path(): void
    {
        [$domain, $line] = $this->paidRenewal();
        // Ordered here once (a registration years ago), paid.
        $order = Order::query()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR']);
        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '10.00', 'description' => 'example.com',
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER, 'applied_at' => '2024-11-01']);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        $domain->forceFill(['order_id' => $order->id])->save();

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->opStatus = 'FAI';
        app(DomainSyncService::class)->sync($domain->refresh());
        // Fix: the failure is the renewal line's row (reverted to its source), so its Retry renews there.
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->where('action', ModuleAction::DOMAIN_RENEW)->sole();

        // A code on the domain (staff fetched a fresh one), then Retry.
        $domain->refresh()->forceFill(['epp_code' => 'Fresh-Code-9'])->save();
        $this->opStatus = 'REQ';
        try {
            RetryModuleAction::run($action);
        } catch (\Throwable) {
        }

        $this->assertCount(1, $this->op, 'one transfer charged at the target for one paid line');
        $this->assertSame(1, DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'transfer')->count(),
            'no order transfer sent for a renewal transfer\'s failure');
        $this->assertSame([], $this->mails('domain_transfer_started'), 'the customer paid a renewal: never told of a transfer');
        $this->assertSame(1, $this->sent('RenewDomain'), 'its Retry renews it at its source, where it is again');
        $this->assertNotNull($line->fresh()->applied_at);
    }

    /**
     * RAT-ADV-6 (LOW) — FAILS. «Transfer pending for more than 14 days» (RecordsToCheck, Health) counts from the DOMAIN's
     * created_at: every renewal transfer of a name registered long ago is flagged stale the moment it is sent (189 a year
     * at myip), and Health is WARN all the time. Fix: count from meta.renew_transfer.sent_at / meta.sent_at.
     */
    public function test_rat_adv_6_a_renewal_transfer_of_an_old_domain_is_not_stale_at_once(): void
    {
        [$domain, $line] = $this->paidRenewal();
        Domain::query()->whereKey($domain->id)->update(['created_at' => '2019-05-01 00:00:00']);

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));

        $this->assertFalse(RecordsToCheck::query()->whereKey($domain->id)->exists(), 'sent a minute ago, not «pending for more than 14 days»');
    }

    /**
     * RAT-ADV-7 (LOW) — FAILS. No EPP code (or an unlock that fails, or no nameservers): staff get it, but no refusal is
     * logged for the line, so refusedFor() is false and every renewal cron run unlocks the name at the source, asks its
     * code and locks it again (two ModifyDomain per run, the lock flapping, a new attempt on staff's row), until staff act.
     * A refused transfer waits for Retry; these should too. Fix: log these failures for the line as refused (or keep a
     * per-line «waits for staff» mark) so only staff's Retry runs it again.
     */
    public function test_rat_adv_7_no_epp_code_is_not_tried_again_by_every_cron_run(): void
    {
        [, $line] = $this->paidRenewal();
        $this->auth = null;
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->cnic = [];

        $outcome = app(DomainRenewalService::class)->renewLine($line->id); // the next cron run, on its own

        $this->assertSame(0, $this->sent('ModifyDomain'), 'not unlocked again at the source without staff');
        $this->assertSame(DomainRenewalService::WAITING, $outcome);
    }

    /**
     * RAT-ADV-8 (LOW) — FAILS. A target that echoes the auth code in its refusal («invalid authcode X»): the log's error
     * is Redact::text()ed, but Redact knows CNIC's AUTH syntax only, and the ModuleAction's last_error is the same text.
     * The EPP code is stored (and mailed to staff). Fix: take the code read in step 2 out of every message
     * RenewAsTransfer stores (Redact::values($text, [$code])).
     */
    public function test_rat_adv_8_an_echoed_epp_code_is_never_stored(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->target = 'refuse-echo';

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));

        $stored = json_encode([DomainRegistrarLog::query()->where('domain_id', $domain->id)->get()->toArray(),
            ModuleAction::query()->get(['last_error'])->toArray(), $domain->fresh()->meta]);
        $this->assertStringNotContainsString('Epp-Code-123', (string) $stored);
    }

    /** GUARD (owner 2026-10-22: no exceptions). A second-level TLD (co.uk) is transferred as any other. */
    public function test_guard_a_second_level_tld_is_transferred_too(): void
    {
        [$domain, $line] = $this->paidRenewal('co.uk');

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame(0, $this->sent('RenewDomain'));
        $this->assertCount(1, $this->op);
        $this->assertSame($this->to->id, $domain->fresh()->registrar_connection_id);
    }

    /** GUARD — passes. A two-year line is renewed here (a transfer adds one year only), never transferred. */
    public function test_guard_a_two_year_line_is_renewed_here(): void
    {
        [, $line] = $this->paidRenewal('com', 2);

        app(DomainRenewalService::class)->renewLine($line->id);

        $this->assertSame([], $this->op);
        $this->assertSame(1, $this->sent('RenewDomain'));
    }

    /**
     * GUARD — passes. A cycle (A → B, B → A) and a second paid line while the transfer is pending: no second transfer, no
     * renewal (the domain is Pending Transfer at the target, which is not renewable).
     */
    public function test_guard_a_second_paid_line_while_pending_sends_nothing(): void
    {
        $this->to->update(['renew_transfer_to_id' => $this->from->id]);
        [$domain, $line] = $this->paidRenewal();
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));

        $invoice = Invoice::factory()->create(['client_id' => $domain->client_id, 'currency_code' => 'EUR']);
        $second = InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '23.33', 'description' => 'example.com',
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'period_start' => '2026-11-01', 'period_end' => '2027-11-01']);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        $this->assertNotSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($second->id));
        $this->assertCount(1, $this->op);
        $this->assertSame(0, $this->sent('RenewDomain'));
    }
}
