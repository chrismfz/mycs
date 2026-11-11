<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Helpers\ExtensionHelper;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OauthUser;
use Mockery;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * Independent and adversarial review of #769 (sweep 6: domains, Mailcow, ticket mail). Each test fails on the PR's head
 * and says what should hold instead. Invented fixtures only (example.com).
 */
class Sweep6DmtReviewTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://mail.test';

    /** @var array<string, array<string, mixed>> */
    private array $domains = [];

    /** @var list<array<string, mixed>> */
    private array $admins = [];

    // ---------------------------------------------------------------------------------------------------------------
    // REV-769-1: the importer marks verified a WHMCS login whose address WHMCS never verified.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * REV-769-1. WHMCS let anyone sign up with any unused address and never asked them to prove it
     * (tblusers.email_verified_at NULL). SocialLoginController refuses such a login on purpose («whoever registered an
     * address they never confirmed knows its password: logging its owner in through the provider would hand them that
     * account»). The importer now writes every login verified, so the guard no longer sees them: the real owner of the
     * address, signing in with Google, lands in the account whose password someone else chose.
     */
    public function test_rev_769_1_a_whmcs_login_never_verified_there_is_not_opened_by_a_provider_login(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        // Signed up in WHMCS with an address that is not theirs; never verified there.
        $whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([8, 'Not', 'Theirs', 'victim@example.com', Hash::make('Squatter-Pass-1'), '', null, null, '2024-02-01 00:00:00', '2025-01-01 00:00:00']);
        $whmcs->exec("INSERT INTO tblclients VALUES (300, 'Not', 'Theirs', '', 'victim@example.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')");
        $whmcs->exec("INSERT INTO tblusers_clients VALUES (1, 8, 300, 1, NULL, NULL, '2024-01-01 00:00:00', NULL)");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->secondFactor = null;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);
        $this->assertSame('victim@example.com', User::query()->findOrFail(8)->email);

        // The address's real owner signs in with Google (which did verify it).
        $oauth = (new OauthUser)->setRaw(['email_verified' => true, 'verified' => true])->map(['id' => '1', 'email' => 'victim@example.com']);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($oauth);
        $socialite = Mockery::mock();
        $socialite->shouldReceive('buildProvider')->andReturn($driver);
        Socialite::swap($socialite);
        config(['settings.oauth_google' => true]);

        $this->get('/oauth/google/callback');

        $this->assertGuest(null, 'a login WHMCS never verified was opened by the provider login: the importer made it «verified»');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // REV-769-2 / REV-769-3: Mailcow's shared-admin guard.
    // ---------------------------------------------------------------------------------------------------------------

    private function mailcow(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $ok = Http::response([['type' => 'success', 'msg' => ['ok']]]);

        return match (true) {
            str_starts_with($path, '/api/v1/get/domain-admin/all') => Http::response($this->admins),
            str_starts_with($path, '/api/v1/get/domain/') => Http::response($this->domains[urldecode(basename($path))] ?? []),
            str_starts_with($path, '/api/v1/get/dkim/') => Http::response([]),
            str_starts_with($path, '/api/v1/get/mailbox/all') => Http::response([]),
            str_starts_with($path, '/api/v1/get/alias/all') => Http::response([]),
            default => $ok,
        };
    }

    /** @return list<Request> */
    private function sent(string $path): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === $path)->values()->all();
    }

    /**
     * Two live services on one mailcow server and one domain admin «example».
     *
     * @param  array{0: string, 1: string}  $domains  the domain of each service
     * @return array{0: Service, 1: Service}
     */
    private function twoServicesOnOneAdmin(array $domains, bool $sameClient): array
    {
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $server = Server::query()->create(['name' => 'Mail Node', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => self::API, 'apikey' => 'api-key-1', 'sso_path' => '/domainadmin/'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $clientId = User::factory()->create()->client()->id;
        $services = [];
        foreach ($domains as $domain) {
            $service = Service::factory()->create(['client_id' => $sameClient ? $clientId : User::factory()->create()->client()->id,
                'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active',
                'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(), 'domain' => $domain, 'username' => 'example']);
            $service->properties()->create(['key' => 'mailcow_username', 'name' => 'mailcow domain admin', 'value' => 'example']);
            $this->domains[$domain] = ['domain_name' => $domain];
            $services[] = $service;
        }
        $this->admins = [['username' => 'example', 'selected_domains' => implode(',', array_unique($domains)), 'active' => 1]];
        Http::fake(fn (Request $request) => $this->mailcow($request));

        return [$services[0], $services[1]];
    }

    /**
     * REV-769-2 (false refusal). One customer, two mail domains under ONE domain admin (what mailcow's admins are for, and
     * what the module's Policy was written for: «a domain admin that manages several domains is not locked unless forced»).
     * Suspending the service of the second domain used to succeed without touching the admin. Now it is refused, because
     * the other domain's service «has the domain admin too», although nothing was going to be done to that admin.
     */
    public function test_rev_769_2_suspending_one_domain_of_a_multi_domain_admin_is_not_refused(): void
    {
        [, $b] = $this->twoServicesOnOneAdmin(['example.com', 'example.org'], sameClient: true);

        $refused = null;
        try {
            ExtensionHelper::suspendServer($b->fresh());
        } catch (Throwable $e) {
            $refused = $e->getMessage();
        }

        $this->assertCount(0, $this->sent('/api/v1/edit/domain-admin'), 'the Policy never locks an admin of several domains');
        $this->assertNull($refused, 'a suspension that does nothing to the shared admin was refused and sent to staff');
    }

    /**
     * REV-769-2 (false refusal). The same customer cancels the second domain; the product is delete_all. The module
     * already ends only that domain (its mailboxes, its aliases, the domain; the admin keeps its other domain:
     * Policy::adminIsOnlyFor). Now the termination is refused outright and the domain's mail stays on the server.
     */
    public function test_rev_769_2_terminating_one_domain_of_a_multi_domain_admin_ends_only_that_domain(): void
    {
        [, $b] = $this->twoServicesOnOneAdmin(['example.com', 'example.org'], sameClient: true);
        $b->product->settings()->create(['key' => 'terminate_mode', 'value' => 'delete_all']);

        $refused = null;
        try {
            ExtensionHelper::terminateServer($b->fresh());
        } catch (Throwable $e) {
            $refused = $e->getMessage();
        }

        $this->assertNull($refused, 'the termination of one domain of a multi-domain admin was refused');
        $this->assertCount(0, $this->sent('/api/v1/delete/domain-admin'), 'the admin stays: it has the other domain');
        $this->assertCount(1, $this->sent('/api/v1/delete/domain'), 'its own domain is removed');
    }

    /**
     * REV-769-3 (hole). Two customers' live services on the same admin and domain (the PR's own fixture: an imported second
     * row). Suspend, terminate and create are refused now, but «Change password» from the second customer's service
     * still sets the password of the admin the first customer uses (and getLoginUrl still signs them into it).
     */
    public function test_rev_769_3_change_password_never_changes_an_admin_another_customers_live_service_holds(): void
    {
        [$a, $b] = $this->twoServicesOnOneAdmin(['example.com', 'example.com'], sameClient: false);
        $this->assertNotSame($a->client_id, $b->client_id);

        try {
            ExtensionHelper::callService($b->fresh(), 'changePassword', ['New-Pass-123456', false]);
        } catch (Throwable) {
            // a refusal is the right answer
        }

        $this->assertCount(0, $this->sent('/api/v1/edit/domain-admin'),
            "service #{$a->id}'s domain admin got a new password from another customer's service #{$b->id}");
    }

    // ---------------------------------------------------------------------------------------------------------------
    // REV-769-4 / REV-769-5: the paid line of an ended domain.
    // ---------------------------------------------------------------------------------------------------------------

    /** @return array{0: Domain, 1: Invoice, 2: InvoiceItem} a cancelled row with its register line still open */
    private function cancelledOrder(): array
    {
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true,
            'settings.system_email_address' => 'staff@example.com', 'settings.mail_disable' => false]);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id]);
        $client = User::factory()->create()->client();
        $domain = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $com->id, 'status' => DomainStatus::PendingRegister, 'expires_at' => null]);
        $invoice = Invoice::query()->create(['client_id' => $client->id, 'currency_code' => 'EUR', 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()]);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER,
            'price' => '23.33', 'quantity' => 1, 'description' => 'Registration example.com']);
        DB::table('domains')->where('id', $domain->id)->update(['status' => DomainStatus::Cancelled->value, 'name_held_at' => null]);

        return [$domain->fresh(), $invoice->fresh(), $line->fresh()];
    }

    /**
     * REV-769-4. «Once per line, whatever its state»: the line is paid, staff are told, the payment turns out to be no
     * payment (recorded on the wrong invoice, a bank transfer that bounced: staff delete it) and they close the task. The
     * invoice is open again; the customer pays it. That second payment is the very case S6-DOMAINS-2 fixed (paid, nothing
     * sent), and it is dropped silently again, because a closed task of the first payment exists.
     */
    public function test_rev_769_4_a_second_payment_of_the_same_line_reaches_staff_too(): void
    {
        [$domain, $invoice, $line] = $this->cancelledOrder();

        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-rev-1');
        $first = ModuleAction::query()->where('domain_id', $domain->id)->sole();
        $this->assertSame(ModuleAction::STATUS_OPEN, $first->status);

        // Not a payment after all: staff delete it and close the task.
        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', $invoice->id)->sole());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $first->closeUnrenewed(null);
        $this->assertSame(ModuleAction::STATUS_RESOLVED, $first->fresh()->status);

        // The customer pays the open invoice.
        (new RecordPaymentService)->handle($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'tx-rev-2');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNull($line->fresh()->applied_at);
        Queue::assertNotPushed(FulfilDomainOrderJob::class);
        $this->assertSame(1, ModuleAction::query()->open()->where('domain_id', $domain->id)->where('invoice_item_id', $line->id)->count(),
            'paid again after staff closed the first task: money taken, nothing sent, and no open task tells staff');
    }

    /**
     * REV-769-5. The new task offers «Done by hand» (every register/transfer row does). On a domain that has ended it
     * marks the line applied and the row resolved, while the domain stays cancelled: the payment counts as used for a
     * domain nobody has, and nothing lists it any more. It should be refused until the domain is pending again (the
     * task's own text: «set the domain back to pending and Retry»).
     */
    public function test_rev_769_5_done_by_hand_never_uses_up_the_payment_of_a_domain_that_stays_cancelled(): void
    {
        [$domain, $invoice, $line] = $this->cancelledOrder();
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-rev-3');
        $task = ModuleAction::query()->where('domain_id', $domain->id)->sole();

        try {
            $task->markResolved(null);
        } catch (Throwable) {
            // a refusal is the right answer
        }

        $this->assertSame(DomainStatus::Cancelled, $domain->fresh()->status);
        $this->assertNull($line->fresh()->applied_at, 'the payment counts as used, for a domain that is still cancelled');
        $this->assertSame(ModuleAction::STATUS_OPEN, $task->fresh()->status, 'and the task that listed it is gone');
    }
}
