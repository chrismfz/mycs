<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Livewire\Cart;
use App\Livewire\DomainShop\Search;
use App\Mail\Mail;
use App\Mail\SystemMail;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\RetryModuleAction;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Nixpal\DomainTools\Whois\SocketConnector;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of #293 (domain lifecycle mails): a customer or staff mail that is wrong, repeated, lost or leaks,
 * and what the new sync path changes on a domain or its Pending Module Actions. Each test names its finding (ADV-n);
 * GUARD tests pass and stay as guards.
 */
class DomainMailsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainRegistrarConnection $op;

    /** @var list<array{0: string, 1: array}> POSTs to the registrar's domain endpoints */
    private array $created = [];

    /** null: answers; 'refuse': 400 on the transfer; 'contact': 400 on a contact; 'busy': 429 on the transfer; 'balance' */
    private ?string $mode = null;

    private ?\Closure $during = null;

    private string $status = 'REQ';

    /** A test's own registrar, in place of the order flow's. */
    private ?\Closure $handler = null;

    /** Renewal harness: the registrar's record; a renew that is charged but not shown yet when $lag. */
    private array $record = [];

    private bool $lag = false;

    /** @var list<string> */
    private array $charges = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Cache::flush();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        $this->app->bind(SocketConnector::class, fn () => new class implements SocketConnector
        {
            public function query($host, $query, $timeout = 8, $max = 65536)
            {
                return null;
            }
        });
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        session(['currency' => 'EUR']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $this->op->id,
            'epp_code_required' => true, 'grace_days' => 40, 'redemption_days' => 0]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['renew', 2, '46.66'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->user = User::factory()->create();
        $this->user->client()->ensurePrimaryContact()->update(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
        Http::fake(fn (Request $request) => $this->handler !== null ? ($this->handler)($request) : $this->registrar($request));
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── the order flow's registrar (from DomainOrderFulfilmentTest) ─────────────────────────────────────────────────

    private function registrar(Request $request)
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match (true) {
            str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
            str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                'domain' => $d['name'] . '.' . $d['extension'],
                'status' => $d['name'] . '.' . $d['extension'] === 'nixpal.com' ? 'active' : 'free',
            ], $request->data()['domains'])]]),
            $request->method() === 'POST' && str_ends_with($path, '/customers') => $this->mode === 'contact'
                ? Http::response(['code' => 10, 'desc' => 'Invalid phone number format'], 400)
                : Http::response(['data' => ['handle' => 'MP000001-GR']]),
            $request->method() === 'POST' && (str_ends_with($path, '/domains') || str_ends_with($path, '/domains/transfer')) => $this->create($path, $request->data()),
            $request->method() === 'GET' && str_contains($path, '/v1/domains') => Http::response(['data' => ['results' => $this->created === [] ? [] : [$this->orderRecord()], 'total' => $this->created === [] ? 0 : 1] + $this->orderRecord()]),
            str_contains($url, 'data.iana.org') => Http::response(['services' => [[['com'], ['https://rdap.example/']]]]),
            str_contains($url, 'rdap.example/domain/nixpal.com') => Http::response(['objectClassName' => 'domain', 'ldhName' => 'nixpal.com',
                'events' => [['eventAction' => 'expiration', 'eventDate' => '2027-11-17T18:08:48Z']],
                'nameservers' => [['ldhName' => 'NS1.MYIP.GR'], ['ldhName' => 'NS2.MYIP.GR']]]),
            default => Http::response([], 404),
        };
    }

    private function create(string $path, array $data)
    {
        if ($this->mode === 'refuse') {
            return Http::response(['code' => 399, 'desc' => 'Invalid auth code ' . ($data['auth_code'] ?? '')], 400);
        }
        if ($this->mode === 'busy') {
            return Http::response(['code' => 429, 'desc' => 'Too many requests, slow down'], 429);
        }
        if ($this->mode === 'balance') {
            return Http::response(['code' => 1, 'desc' => 'Insufficient funds: reseller 204417 balance EUR 3.12'], 400);
        }
        $this->created[] = [$path, $data];
        if ($this->during !== null) {
            ($this->during)();
        }

        return Http::response(['code' => 0, 'data' => ['id' => 9001, 'status' => $this->status]]);
    }

    private function orderRecord(): array
    {
        $fqdn = $this->created === [] ? '' : $this->created[0][1]['domain']['name'] . '.' . $this->created[0][1]['domain']['extension'];

        return ['id' => 9001, 'domain' => ['name' => explode('.', $fqdn)[0], 'extension' => 'com'], 'status' => $this->status,
            'expiration_date' => '2027-10-20 10:00:00', 'name_servers' => array_map(fn ($ns) => ['name' => $ns['name']], $this->created[0][1]['name_servers'] ?? []),
            'owner_handle' => 'MP000001-GR', 'admin_handle' => 'MP000001-GR', 'tech_handle' => 'MP000001-GR', 'billing_handle' => 'MP000001-GR'];
    }

    private function order(string $fqdn, string $epp): Domain
    {
        Livewire::test(Search::class)->call('transfer', $fqdn)->assertRedirect(route('cart'));
        session([CurrentClient::SESSION_KEY => $this->user->client()->id]);
        $cart = Livewire::withCookie('cart', (string) \App\Models\Cart::query()->latest('id')->value('ulid'))->actingAs($this->user)->test(Cart::class);
        $item = \App\Models\Cart::query()->latest('id')->first()->items()->sole();
        $cart->set("epp.{$item->id}", $epp);
        $cart->call('checkout')->assertRedirect();

        return Domain::query()->where('fqdn', $fqdn)->sole();
    }

    private function pay(Domain $domain): void
    {
        $invoice = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole()->invoice;
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-' . $domain->id);
    }

    private function runJobs(): void
    {
        foreach (Queue::pushed(FulfilDomainOrderJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    /** @return list<string> the customer's mails of this template, rendered */
    private function mails(string $template): array
    {
        return MailFacade::queued(Mail::class)->filter(fn (Mail $mail) => $mail->templateKey === $template)
            ->map(fn (Mail $mail) => $mail->renderedSubject . "\n" . strip_tags($mail->render()))->values()->all();
    }

    /** @return list<string> staff's mails, subject and body */
    private function staffMails(): array
    {
        return MailFacade::queued(SystemMail::class)->map(fn (SystemMail $mail) => $mail->envelope()->subject . "\n" . strip_tags($mail->render()))->values()->all();
    }

    // ── a domain on its own, for the sync path ──────────────────────────────────────────────────────────────────────

    private function pendingTransfer(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => DomainTld::query()->where('tld', 'com')->value('id'), 'registrar_connection_id' => $this->op->id,
            'status' => DomainStatus::PendingTransfer, 'expires_at' => null, 'registered_at' => null, 'currency_code' => 'EUR']);
    }

    /** Openprovider answering by id ($byId: id => record, missing: 404) and by name ($byName). */
    private function opRecords(array $byId, array $byName): void
    {
        $this->handler = function (Request $request) use ($byId, $byName) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'GET' && preg_match('#/v1/domains/(\d+)$#', $path, $m) === 1) {
                return isset($byId[$m[1]]) ? Http::response(['data' => $byId[$m[1]]]) : Http::response(['code' => 320, 'desc' => 'Domain not found'], 404);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/v1/domains')) {
                return Http::response(['data' => ['results' => $byName, 'total' => count($byName)]]);
            }

            return Http::response([], 404);
        };
    }

    private static function opRecord(int $id, string $status, ?string $expires = null): array
    {
        return ['id' => $id, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => $status,
            'expiration_date' => $expires, 'name_servers' => [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']]];
    }

    // ── ADV-1: domainSucceeded in the sync path closes staff's «sent after a refund» ───────────────────────────────

    /**
     * ADV-1 (high, FAILING). Staff refund the order while its transfer is being sent: it goes out anyway and a Pending
     * Module Action «done at the registrar, but its order was cancelled or refunded: decide» is opened. When the transfer
     * completes, transferOutcome() calls domainSucceeded() for the transfer line, which marks that row «succeeded»: staff
     * lose the only reminder that a refunded domain is now Active at our cost (and the customer is told it is theirs).
     */
    public function test_adv1_a_transfer_completing_does_not_close_staffs_sent_after_refund_action(): void
    {
        $domain = $this->order('nixpal.com', 'Epp#Code-77');
        $this->pay($domain);
        $this->during = fn () => Invoice::query()->update(['status' => Invoice::STATUS_REFUNDED]);
        $this->runJobs();
        $this->during = null;

        $action = ModuleAction::query()->open()->sole();
        $this->assertSame(__('domains.order.sent_after_cancel', ['domain' => 'nixpal.com']), $action->last_error);
        $this->assertSame(DomainStatus::PendingTransfer, $domain->fresh()->status);

        $this->status = 'ACT';
        app(DomainSyncService::class)->sync($domain->refresh());

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertSame(ModuleAction::STATUS_OPEN, $action->fresh()->status,
            'the refunded order sent anyway still waits for staff: a completed transfer is not its resolution');
    }

    // ── ADV-2: «its own record» is checked after the sync already took the tombstone's id ──────────────────────────

    /**
     * ADV-2 (medium, FAILING). Its transfer request gave id 111. A sync that cannot read 111 (404) falls back to the
     * search by name, which finds only an OLD FAI tombstone of the same name (id 222: a transfer of years ago). apply()
     * writes registrar_domain_id = 222 first, then transferOutcome() compares the result's id with the domain's id it
     * just wrote: always «ours». The customer is told their transfer failed, staff get a Pending Module Action.
     */
    public function test_adv2_an_old_fai_tombstone_found_by_name_is_not_this_transfers_failure(): void
    {
        $domain = $this->pendingTransfer(['registrar_domain_id' => '111', 'meta' => ['sent_at' => now()->subDay()->toIso8601String()]]);
        $this->opRecords([], [self::opRecord(222, 'FAI')]);

        app(DomainSyncService::class)->sync($domain);

        $this->assertSame([], $this->mails('domain_transfer_failed'), 'not the record its request gave (111): no «failed» to the customer');
        $this->assertSame(0, ModuleAction::query()->open()->count());
    }

    // ── ADV-3, ADV-4: «refused» that is not the transfer's refusal ─────────────────────────────────────────────────

    /**
     * ADV-3 (medium, FAILING). The contact's handle cannot be created (Openprovider refuses the contact: a phone format).
     * createDomainObject() rethrows that RegistrarRefused as is, so the fulfilment tells the customer «the transfer did
     * not go through: a wrong EPP code, a lock…» though no transfer was ever asked. The code's own comment says: «Not one
     * we did not send».
     */
    public function test_adv3_a_contact_refused_before_the_transfer_is_not_told_as_a_failed_transfer(): void
    {
        $this->mode = 'contact';
        $domain = $this->order('nixpal.com', 'Epp#Code-77');
        $this->pay($domain);
        $this->runJobs();

        $this->assertSame([], $this->created, 'no transfer was asked');
        $this->assertSame([], $this->mails('domain_transfer_failed'), 'nothing was sent: not «your transfer did not go through»');
    }

    /**
     * ADV-4 (medium, FAILING). Openprovider rate-limits us (429: a 4xx, so RegistrarRefused). The customer is told their
     * transfer failed and to check their EPP code; with many transfers paid at once, every one of them is.
     */
    public function test_adv4_a_rate_limit_is_not_told_to_the_customer_as_a_failed_transfer(): void
    {
        $this->mode = 'busy';
        $domain = $this->order('nixpal.com', 'Epp#Code-77');
        $this->pay($domain);
        $this->runJobs();

        $this->assertSame([], $this->mails('domain_transfer_failed'), 'ours to retry, not the customer\'s EPP code');
    }

    /**
     * ADV-5 (low, FAILING). The registrar's answer goes to the customer verbatim (up to 300 characters): our registrar's
     * name and whatever it says about our account (here its reseller id and balance).
     */
    public function test_adv5_the_reason_told_the_customer_does_not_leak_our_registrar_account(): void
    {
        $this->mode = 'balance';
        $domain = $this->order('nixpal.com', 'Epp#Code-77');
        $this->pay($domain);
        $this->runJobs();

        $failed = $this->mails('domain_transfer_failed');
        foreach ($failed as $mail) {
            $this->assertStringNotContainsString('reseller 204417', $mail);
            $this->assertStringNotContainsString('balance', $mail);
            $this->assertStringNotContainsString('Openprovider', $mail);
        }
        $this->assertSame([], $failed, 'our account\'s funds are not the customer\'s transfer failing');
    }

    /** GUARD (passes): a refusal that repeats the EPP code never puts it in the customer's or staff's mail. */
    public function test_guard_the_epp_code_echoed_by_a_refusal_is_in_no_mail(): void
    {
        $this->mode = 'refuse';
        $domain = $this->order('nixpal.com', 'Secret-Epp-90210');
        $this->pay($domain);
        $this->runJobs();

        $this->assertCount(1, $this->mails('domain_transfer_failed'));
        foreach (array_merge($this->mails('domain_transfer_failed'), $this->staffMails()) as $mail) {
            $this->assertStringNotContainsString('Secret-Epp-90210', $mail);
            $this->assertStringNotContainsString('Op-Secret-Pa55', $mail);
        }
        $this->assertCount(1, array_filter($this->staffMails(), fn ($m) => str_starts_with($m, 'Domain transfer needs staff: nixpal.com')));
    }

    // ── ADV-6: «renewed until» the old date ────────────────────────────────────────────────────────────────────────

    /**
     * ADV-6 (medium, FAILING). Charged, but the registrar does not show the new expiry yet (it lags): the line is applied
     * and staff get «Renewed, but the registrar shows 2026-11-01 for a period paid to 2027-11-01». The customer is told
     * «nixpal.com is renewed until 01/11/2026»: the date it expires anyway, the renewal not seen.
     */
    public function test_adv6_a_renewal_the_registrar_does_not_show_yet_is_not_told_renewed_until_the_old_expiry(): void
    {
        $domain = $this->activeForRenewal();
        $line = $this->billAndPay($domain);
        $this->lag = true;

        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertCount(1, $this->charges);
        $this->assertSame(1, ModuleAction::query()->open()->count(), 'staff told it is short');

        $this->assertNotContains('nixpal.com is renewed until 01/11/2026', array_map(fn ($m) => strtok($m, "\n"), $this->mails('domain_renewed')),
            'the old expiry is not a renewal');
    }

    /** GUARD (passes): a renewal line refunded before it is renewed tells no one it is renewed. */
    public function test_guard_a_refunded_renewal_is_not_told_renewed(): void
    {
        $domain = $this->activeForRenewal();
        $line = $this->billAndPay($domain);
        Invoice::query()->whereKey($line->invoice_id)->update(['status' => Invoice::STATUS_REFUNDED]); // as its refund leaves it
        $this->record['expiration_date'] = '2027-11-01 10:00:00'; // even covered at the registrar

        $this->assertSame(DomainRenewalService::NOTHING, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([], $this->mails('domain_renewed'));
    }

    /** GUARD (passes): many renewals failing at once (the registrar down) mail staff once each, never again per run. */
    public function test_guard_a_registrar_down_mails_staff_once_per_domain_not_per_run(): void
    {
        $lines = [];
        foreach (['nixpal', 'myip', 'zeta'] as $sld) {
            $lines[] = $this->billAndPay($this->activeForRenewal($sld));
        }
        $this->handler = fn (Request $request) => Http::response([], 503);
        foreach ([1, 2] as $run) {
            foreach ($lines as $line) {
                app(DomainRenewalService::class)->renewLine($line->id);
            }
        }

        $this->assertCount(3, array_filter($this->staffMails(), fn ($m) => str_starts_with($m, 'Domain renewal needs staff')));
        $this->assertSame([], $this->mails('domain_renewed'));
    }

    /** GUARD (passes): a Pending Module Action opened in a transaction that rolls back mails no one. */
    public function test_guard_a_rolled_back_failure_mails_no_staff(): void
    {
        $domain = $this->activeForRenewal();
        try {
            DB::transaction(function () use ($domain) {
                ModuleAction::domainFailed($domain, null, 'openprovider', new RuntimeException('boom'));
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, ModuleAction::query()->count());
        $this->assertSame([], $this->staffMails());
    }

    private function activeForRenewal(string $sld = 'nixpal'): Domain
    {
        $this->record = ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2026-11-01 10:00:00', 'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']]];
        $this->handler = function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/renew')) {
                $this->charges[] = $path;
                if (!$this->lag) {
                    $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears((int) $request->data()['period'])->format('Y-m-d H:i:s');
                }

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
        };

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'com', 'fqdn' => "{$sld}.com",
            'domain_tld_id' => DomainTld::query()->where('tld', 'com')->value('id'), 'registrar_connection_id' => $this->op->id,
            'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01', 'currency_code' => 'EUR',
            'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function billAndPay(Domain $domain): InvoiceItem
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return $line->fresh();
    }

    // ── ADV-7: «transferred» to a domain imported pending years ago ────────────────────────────────────────────────

    /**
     * ADV-7 (medium, FAILING). A domain imported from WHMCS still «Pending Transfer» (myip has a list of these, done long
     * ago: RecordsToCheck), never sent from here (no order, no meta.sent_at). Its first sync after the cut-over finds it
     * Active and tells the customer «nixpal.com is now with us» for a transfer done months or years ago, one per such
     * domain on the first sync run.
     */
    public function test_adv7_an_imported_pending_transfer_found_active_is_not_told_transferred(): void
    {
        $domain = $this->pendingTransfer(['order_id' => null, 'meta' => null, 'created_at' => '2024-03-01 10:00:00']);
        $this->opRecords([], [self::opRecord(5150, 'ACT', '2027-03-01 10:00:00')]);

        app(DomainSyncService::class)->sync($domain);

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertSame([], $this->mails('domain_transferred'), 'not a transfer sent from here: nothing to announce');
    }

    // ── ADV-8, ADV-9: after a registrar's FAI ───────────────────────────────────────────────────────────────────────

    /**
     * ADV-8 (medium, FAILING). After Openprovider's FAI, staff are mailed «retry it there once the cause is fixed» and the
     * row says «send it again»; the customer is told «we will send the transfer again». Retry on that row cannot: the
     * domain is marked sent (meta.sent_at) and its line applied, so fulfil() answers DONE and nothing is sent. The
     * `unset($meta['transfer_failed'])` on a resend is unreachable from the Pending Module Action.
     */
    public function test_adv8_retry_on_a_registrar_reported_failure_sends_the_transfer_again(): void
    {
        $domain = $this->order('nixpal.com', 'Epp#Code-77');
        $this->pay($domain);
        $this->runJobs();
        $this->status = 'FAI';
        app(DomainSyncService::class)->sync($domain->refresh());
        $action = ModuleAction::query()->open()->sole();
        $this->assertCount(1, $this->created);

        // The customer sends a new code; staff Retry, as their mail says.
        $domain->refresh()->forceFill(['epp_code' => 'New-Code-1'])->save();
        $this->status = 'REQ';
        RetryModuleAction::run($action);

        $this->assertCount(2, $this->created, 'Retry sends the transfer again');
        $this->assertSame('New-Code-1', $this->created[1][1]['auth_code'] ?? null);
    }

    /**
     * ADV-9 (low, FAILING). meta.transfer_failed is only cleared by a resend through fulfil(). Staff resend it at the
     * registrar by hand («Done by hand» on the row), the new request gets its own id (here staff set it), and that one
     * fails too: nobody is told, the domain stays pending in silence, which is what this PR set out to end.
     */
    public function test_adv9_a_later_real_failure_after_a_resend_by_hand_is_reported(): void
    {
        $domain = $this->pendingTransfer(['registrar_domain_id' => '111', 'meta' => ['sent_at' => now()->subDays(6)->toIso8601String()]]);
        $this->opRecords(['111' => self::opRecord(111, 'FAI')], [self::opRecord(111, 'FAI')]);
        app(DomainSyncService::class)->sync($domain);
        $this->assertCount(1, $this->mails('domain_transfer_failed'));

        // Sent again at the registrar by hand: done by hand, its new request's id recorded.
        ModuleAction::query()->open()->sole()->markResolved(null);
        Carbon::setTestNow('2026-10-27 10:00:00');
        $domain->refresh()->forceFill(['registrar_domain_id' => '333'])->save();
        $this->opRecords(['333' => self::opRecord(333, 'FAI'), '111' => self::opRecord(111, 'FAI')], [self::opRecord(111, 'FAI'), self::opRecord(333, 'FAI')]);
        app(DomainSyncService::class)->sync($domain->refresh());

        $this->assertSame(1, ModuleAction::query()->open()->count(), 'the second attempt failed too: staff are told');
        $this->assertCount(2, $this->mails('domain_transfer_failed'));
    }

    /** GUARD (passes): a DEL in quarantine of its own id, or a FAI never sent from here, tells no one. */
    public function test_guard_a_quarantined_del_or_an_unsent_transfer_is_not_a_failure(): void
    {
        $quarantined = self::opRecord(111, 'DEL') + ['soft_quarantine_expiry_date' => '2026-11-15 10:00:00'];
        $domain = $this->pendingTransfer(['registrar_domain_id' => '111', 'meta' => ['sent_at' => now()->subDay()->toIso8601String()]]);
        $this->opRecords(['111' => $quarantined], [$quarantined]);
        app(DomainSyncService::class)->sync($domain);

        $domain->refresh()->forceFill(['meta' => ['read_by' => $this->op->id]])->save(); // never sent from here
        $this->opRecords(['111' => self::opRecord(111, 'FAI')], [self::opRecord(111, 'FAI')]);
        app(DomainSyncService::class)->sync($domain->refresh());

        $this->assertSame(DomainStatus::PendingTransfer, $domain->fresh()->status);
        $this->assertSame([], $this->mails('domain_transfer_failed'));
        $this->assertSame(0, ModuleAction::query()->count());
    }

    /** GUARD (passes): the transferred mail and its staff row are not repeated by later syncs or the cron's sync. */
    public function test_guard_a_completed_transfer_is_told_once_across_syncs(): void
    {
        $domain = $this->order('nixpal.com', 'Epp#Code-77');
        $this->pay($domain);
        $this->runJobs();
        $this->status = 'ACT';
        foreach ([1, 2, 3] as $run) {
            app(DomainSyncService::class)->sync($domain->refresh());
        }

        $this->assertCount(1, $this->mails('domain_transferred'));
        $this->assertSame([], array_filter($this->staffMails(), fn ($m) => str_contains($m, 'needs staff')));
    }
}
