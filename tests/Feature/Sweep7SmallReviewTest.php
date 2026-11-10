<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Independent and adversarial review of #777 (sweep 7, the small findings). Each test fails on the PR's head as reviewed
 * (b08e3629) and says what it wants instead. Invented data only.
 *
 * REV-777-1  Ekdosi: «read» taken as paid_at, which is the DATE OF THE LAST PAYMENT as staff gave it (a transfer recorded
 *            later with its value date), not when the invoice became paid: a split after that date and before the payment
 *            was recorded still refuses the MARK for good (the finding the PR fixes, on the bank-transfer path).
 * REV-777-2  Ekdosi: an unpaid invoice of an «immediate invoicing» account is Ekdosi's to read with no request and no
 *            payment (E3: `invoices {status: Unpaid, userids}`, `invoice {invoice_id}`): split before Ekdosi read it, its
 *            MARK is still refused.
 * REV-777-3  Ekdosi: the document of the invoice as it was BEFORE a split is now accepted once the invoice is paid (or
 *            sent again): before the PR it was refused. The author's «known limit»; here with its amounts.
 * REV-777-4  cPanel: «Login to Webmail» (a customer's SSO, same create_user_session) is not guarded.
 * REV-777-5  cPanel: the same domain written another way (www., a trailing dot, an IDN in Unicode against WHM's
 *            punycode) is «another account»: suspend, terminate, login and password refused for the customer's own account.
 * REV-777-6  cPanel: unsuspend acts on a stranger's account (declared «not guarded» by the PR; here as a test).
 * REV-777-7  Deploy: another install whose path ENDS with this one's (…/backup/var/www/app/artisan) is now taken for
 *            this install's scheduler even with a readable cwd elsewhere (before the PR a readable cwd settled it).
 */
class Sweep7SmallReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private const WHM = 'https://whm.example.com:2087/json-api';

    private Client $client;

    /** @var list<resource> */
    private array $procs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.com/ekdosi',
            'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->forceFill(['type' => 'business', 'company_name' => 'Example', 'tax_id' => '099999999', 'country' => 'GR'])->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            proc_terminate($p, SIGKILL);
            proc_close($p);
        }
        parent::tearDown();
    }

    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json']), $raw);
    }

    /** @return array{Invoice, int} an unpaid invoice of two lines (100 + 20, net), and the id of the second line */
    private function invoice(): array
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting example.com', 'quantity' => 1, 'price' => '100.00']);
        $b = $invoice->items()->create(['description' => 'Domain example.com', 'quantity' => 1, 'price' => '20.00']);

        return [$invoice->fresh(), (int) $b->id];
    }

    /**
     * REV-777-1. Monday: the customer's bank transfer arrives. Tuesday: staff split the (still unpaid) invoice. Wednesday:
     * staff record the transfer with its date, Monday («Payment date», MT-1c): InvoiceObserver sets paid_at to the date of
     * the last payment, Monday. Ekdosi reads the Inbox on Wednesday (the invoice as the split left it), issues, writes
     * back: split_at (Tuesday) > paid_at (Monday) → 409 invoice_changed, for good (a paid invoice is never split again,
     * its paid_at never moves). The same with account credit from an older payment (spendCredit dates it as its source).
     */
    public function test_rev_777_1_a_split_invoice_paid_by_a_transfer_dated_before_the_split_takes_its_document(): void
    {
        [$invoice, $line] = $this->invoice();
        $monday = now()->toImmutable();
        $this->travel(1)->days();
        (new SplitInvoice)->handle($invoice->id, [$line]);
        $this->travel(1)->days();
        (new RecordPaymentService)->handle($invoice->id, Gateway::query()->where('extension', 'BankTransfer')->sole(), (string) $invoice->fresh()->total,
            transactionId: 'BANK-MON', enteredByStaff: true, paidAt: $monday);
        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertTrue($invoice->paid_at->lt(Invoice::query()->where('split_from_id', $invoice->id)->sole()->split_at), 'precondition: paid_at is the transfer\'s date, before the split');

        // Ekdosi reads the Inbox now: the invoice of one line, 124.00.
        $read = collect($this->bridge(['op' => 'invoices'])->assertOk()->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertNotNull($read);
        $this->assertEqualsWithDelta(124.00, (float) $read['total'], 0.001);

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000077701', 'code' => 'TPY-1'])
            ->assertOk(); // 409 invoice_changed «Split since», though Ekdosi read it after the split
    }

    /**
     * REV-777-2. An account with «immediate invoicing» (τιμολόγιο πριν την πληρωμή, E3): Ekdosi reads its unpaid invoices
     * by `invoices {status: Unpaid, userids}` with no «Send to Ekdosi» and no payment. Staff split one before Ekdosi read
     * it: $since is null → «any split is after the read» → refused. The document exists at AADE, MyCS has no MARK for it:
     * add_payment answers no_active_document, and once paid it is back in the Inbox for a second document.
     */
    public function test_rev_777_2_an_immediate_invoicing_accounts_unpaid_invoice_split_before_ekdosi_read_it_takes_its_document(): void
    {
        $this->client->forceFill(['immediate_invoicing' => true])->save();
        [$invoice, $line] = $this->invoice();
        (new SplitInvoice)->handle($invoice->id, [$line]);
        $this->travel(1)->days();

        // Ekdosi reads it now, as E3 does: the invoice as the split left it.
        $read = collect($this->bridge(['op' => 'invoices', 'status' => 'Unpaid', 'userids' => [$this->client->id]])->assertOk()->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertNotNull($read, 'precondition: the unpaid invoice is Ekdosi\'s to read');
        $this->assertEqualsWithDelta(124.00, (float) $read['total'], 0.001);

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000077702', 'code' => 'TIM-2'])
            ->assertOk(); // 409 invoice_changed
    }

    /**
     * REV-777-3 (the PR's «known limit», with its amounts). Staff send the invoice to Ekdosi on credit; Ekdosi reads it:
     * two lines, 148.80. Staff split it (the request is withdrawn): 124.00 stays, 24.80 moves to a new invoice. The
     * customer pays the 124.00. Ekdosi issues from what it read (it keeps what it reads in its Inbox until staff issue
     * there) and writes back a document of 148.80: before the PR 409 invoice_changed («Άλλαξε στη MyCS»); now accepted.
     * The invoice of 124.00 is filed under a document of 148.80, and the 24.80 that moved is in the Inbox again: billed twice.
     */
    public function test_rev_777_3_a_document_of_the_invoice_as_it_was_before_a_split_is_not_accepted_once_it_is_paid(): void
    {
        [$invoice, $line] = $this->invoice();
        Invoice::query()->whereKey($invoice->id)->update(['ekdosi_requested_at' => now()]);
        $read = collect($this->bridge(['op' => 'invoices', 'status' => 'requested'])->assertOk()->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertEqualsWithDelta(148.80, (float) $read['total'], 0.001, 'precondition: Ekdosi read the invoice of two lines');

        $this->travel(1)->hours();
        $new = (new SplitInvoice)->handle($invoice->id, [$line]);
        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'precondition: the split withdrew the request');
        $this->travel(1)->hours();
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-3');
        $this->assertEqualsWithDelta(124.00, (float) $invoice->fresh()->total, 0.001);

        // The document of 148.80, from the first read.
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000077703', 'code' => 'TIM-3'])
            ->assertStatus(409)->assertJsonPath('error', 'invoice_changed'); // 200: accepted
        $this->assertSame(0, $invoice->ekdosiDocuments()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $new->fresh()->status);
    }

    private function cpanelService(string $domain): Service
    {
        Http::preventStrayRequests();
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.example.com:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'Business20']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'expires_at' => now()->addMonth(),
            'currency_code' => 'EUR', 'price' => 10.00, 'domain' => $domain, 'username' => 'exampl']);
        $service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampl']);

        return $service->fresh();
    }

    private function whm(string $primaryDomain): void
    {
        Http::fake([
            self::WHM . '/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'exampl', 'domain' => $primaryDomain, 'owner' => 'root', 'suspended' => 0]]]]),
            self::WHM . '/listaccts*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => []]]),
            self::WHM . '/listresellers*' => Http::response(['metadata' => ['result' => 1], 'data' => ['reseller' => []]]),
            self::WHM . '/*' => Http::response(['metadata' => ['result' => 1], 'data' => ['url' => 'https://whm.example.com:2096/cpsess1/login/?session=x']]),
        ]);
    }

    /** @return list<string> the WHM calls that change or open the account */
    private function acted(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => basename((string) parse_url($pair[0]->url(), PHP_URL_PATH)))
            ->reject(fn (string $path) => in_array($path, ['accountsummary', 'listaccts', 'listresellers', 'listpkgs', 'getpkginfo', 'version'], true))
            ->values()->all();
    }

    private function attempt(callable $action): ?Throwable
    {
        try {
            $action();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * REV-777-4. The PR guards the customer's «Login to cPanel» (getLoginUrl). «Login to Webmail» is the button next to
     * it (getActions, `sso`), the same create_user_session for the same username: the customer is signed in to the
     * stranger's webmail (the account's own mailbox, and from there its mail settings).
     */
    public function test_rev_777_4_the_customer_is_not_signed_in_to_the_webmail_of_an_account_whose_primary_domain_is_not_the_services(): void
    {
        $service = $this->cpanelService('example.com');
        $this->whm('stranger.example.net');

        $url = null;
        $this->attempt(function () use ($service, &$url) {
            $url = ExtensionHelper::callService($service, 'getWebmailUrl');
        });

        $this->assertNull($url, 'a signed-in webmail session of another domain\'s account was handed out');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/create_user_session'));
    }

    /** @return array<string, array{string, string}> the service's domain as MyCS keeps it, WHM's primary domain */
    public static function sameDomainWrittenAnotherWay(): array
    {
        return [
            'www. in the service\'s domain (as typed at the order, or kept from WHMCS)' => ['www.example.com', 'example.com'],
            'a trailing dot' => ['example.com.', 'example.com'],
            'an IDN: Unicode here, punycode in WHM' => ['παράδειγμα.gr', 'xn--hxajbheg2az3al.gr'],
            'an IDN: punycode here, Unicode in WHM' => ['xn--hxajbheg2az3al.gr', 'παράδειγμα.gr'],
        ];
    }

    /**
     * REV-777-5. The comparison is strtolower(trim()) of services.domain against accountsummary's `domain`: the same
     * domain written another way is refused as «for another domain», and with it the cron's suspension of an overdue
     * account, its termination, the customer's login and a new password, each a failed module action for staff.
     */
    #[DataProvider('sameDomainWrittenAnotherWay')]
    public function test_rev_777_5_the_services_own_account_is_suspended_when_its_domain_is_written_another_way(string $ours, string $whm): void
    {
        $service = $this->cpanelService($ours);
        $this->whm($whm);

        $e = $this->attempt(fn () => ExtensionHelper::suspendServer($service));

        $this->assertNull($e, 'refused: ' . $e?->getMessage());
        $this->assertSame(['suspendacct'], $this->acted());
    }

    /**
     * REV-777-6. Declared by the PR as not guarded: unsuspend. The stranger's account (suspended in WHM for its own
     * reason: abuse, its reseller's unpaid bill) is unsuspended when this service's invoice is paid.
     */
    public function test_rev_777_6_unsuspend_does_not_unsuspend_an_account_whose_primary_domain_is_not_the_services(): void
    {
        $service = $this->cpanelService('example.com');
        $service->forceFill(['status' => Service::STATUS_SUSPENDED])->save();
        $this->whm('stranger.example.net');

        $this->attempt(fn () => ExtensionHelper::unsuspendServer($service->fresh()));

        $this->assertSame([], $this->acted(), 'unsuspendacct was sent for an account of another domain');
    }

    /**
     * REV-777-7. Before the PR a readable cwd settled whose scheduler it is; now the command line is read as well, by
     * str_contains($cmd, $here . '/artisan '): another install whose path ends with this one's (a copy under
     * /srv/backup/var/www/app, a chroot or CageFS view of the same path) is taken for ours, and the deploy waits its 15
     * minutes for a scheduler that is not this install's and then stops. The same match in deploy/update.sh
     * (_queue_worker_pids: *"$here/artisan "*) waits for, and gives up on, another install's queue worker.
     */
    public function test_rev_777_7_cron_wait_does_not_wait_for_the_scheduler_of_an_install_whose_path_ends_with_this_ones(): void
    {
        Sleep::fake();
        $this->app->instance(Schedule::class, new Schedule);
        $here = realpath(base_path()) ?: base_path();
        $title = 'php /srv/backup' . $here . '/artisan schedule:run';
        $code = 'cli_set_process_title(' . var_export($title, true) . '); sleep(30);';
        $p = proc_open([PHP_BINARY, '-r', $code], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, sys_get_temp_dir());
        $this->assertIsResource($p);
        $this->procs[] = $p;
        for ($i = 0, $c = 1; $i < 40 && $c !== 0; $i++) {
            usleep(50_000);
            exec('pgrep -P ' . getmypid() . ' -f ' . escapeshellarg(preg_quote($title)), $o, $c);
        }
        $this->assertSame(0, $c, 'the fake process never showed its title');

        $this->assertSame(0, Artisan::call('app:cron-wait', ['--timeout' => 3]), 'the deploy waited for another install\'s scheduler: ' . Artisan::output());
    }
}
