<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\RelationManagers\SslVersionsRelationManager;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show;
use App\Models\DomainRegistrarConnection;
use App\Models\Server;
use App\Models\Service;
use App\Models\SslOrder;
use App\Models\SslVersion;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ssl\SslVault;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\SslTools\Certificate;
use MyCS\SslTools\Csr;
use MyCS\SslTools\KeyType;
use MyCS\SslTools\PrivateKey;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial tests for the SSL vault (#437, docs/ssl-vault.md), written without the author's context. A test that
 * fails names a defect; the few that pass confirm a defence holds. Setup copied from SslVaultTest (Openprovider faked,
 * a throwaway CA).
 */
class SslVaultAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Service $service;

    /** @var array<int, array<string, mixed>> orders at Openprovider */
    private array $orders = [];

    private int $nextId = 777;

    private static ?PrivateKey $caKey = null;

    private static ?Certificate $ca = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $connection->storeCredentials(['username' => 'op-user', 'password' => 'op-secret'], ['password']);
        $server = Server::query()->create(['name' => 'SSL', 'extension' => 'OpenproviderSsl', 'type' => 'server', 'enabled' => true]);
        $server->settings()->create(['key' => 'connection_id', 'value' => (string) $connection->id]);
        $this->owner = User::factory()->create();
        $this->owner->client()->ensurePrimaryContact()->forceFill(['country' => 'GR', 'address1' => 'Odos 12', 'city' => 'Athens', 'postcode' => '10000', 'phone' => '+30.2100000000'])->save();
        $product = $this->createProduct();
        $product->plan->update(['billing_unit' => 'year', 'billing_period' => 1]);
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'product_id', 'value' => '9']);
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addYear()]);
        Http::fake(fn (Request $request) => $this->openprovider($request));
        ExtensionHelper::createServer($this->service->fresh());
        if (self::$ca === null) {
            self::$caKey = PrivateKey::generate(KeyType::EcP256);
            self::$ca = $this->sign(Csr::create(self::$caKey, ['ca.example.test']), self::$caKey, null);
        }
    }

    private function openprovider(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $ok = fn (array $data = []) => Http::response(['code' => 0, 'desc' => '', 'data' => $data]);

        return match (true) {
            $path === '/v1/auth/login' => $ok(['token' => 'tok']),
            $path === '/v1/customers' => $ok(['handle' => 'XX123-GR']),
            $path === '/v1/ssl/csr/decode' => (function () use ($request, $ok) {
                $csr = Csr::parse((string) $request['csr']);

                return $ok(['subject' => ['common_name' => $csr->commonName], 'subject_alternative_name' => $csr->names]);
            })(),
            $path === '/v1/ssl/products/9' => $ok(['id' => 9, 'is_wildcard_supported' => true]),
            $path === '/v1/ssl/approver-emails' => $ok(['results' => ['admin@shop.example.test']]),
            $path === '/v1/ssl/orders' && $request->method() === 'POST' => (function () use ($request, $ok) {
                $id = $this->nextId++;
                $this->orders[$id] = ['id' => $id, 'common_name' => 'shop.example.test', 'product_id' => 9, 'status' => 'REQ', 'csr' => $request['csr'],
                    'order_date' => now('Europe/Amsterdam')->format('Y-m-d H:i:s'), 'expiration_date' => '', 'order_ends_at' => now()->addYear()->format('Y-m-d H:i:s'),
                    'reissue_at' => '', 'certificate' => '', 'intermediate_certificate' => '', 'domain_validation_methods' => [], 'additional_data' => []];

                return $ok(['id' => $id]);
            })(),
            (bool) preg_match('#^/v1/ssl/orders/(\d+)/reissue$#', $path, $m) => (function () use ($m, $request, $ok) {
                $this->orders[(int) $m[1]]['csr'] = $request['csr'];

                return $ok(['id' => (int) $m[1]]);
            })(),
            (bool) preg_match('#^/v1/ssl/orders/(\d+)$#', $path, $m) => $ok($this->orders[(int) $m[1]]),
            default => $ok(),
        };
    }

    /** A certificate for the CSR, signed by the CA (or by itself). */
    private function sign(Csr $csr, PrivateKey $by, ?Certificate $ca): Certificate
    {
        $config = tempnam(sys_get_temp_dir(), 'crt');
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = " . implode(',', array_map(fn ($n) => "DNS:{$n}", $csr->names)) . "\n");
        $x509 = openssl_csr_sign($csr->pem, $ca?->pem, $by->key(), 199, ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'], random_int(1000, 999999));
        unlink($config);
        openssl_x509_export($x509, $pem);

        return Certificate::parse($pem);
    }

    /** Openprovider issues the order's current CSR; the sync reads it. */
    private function issue(): Certificate
    {
        $certificate = $this->sign(Csr::parse($this->orders[777]['csr']), self::$caKey, self::$ca);
        $this->orders[777] = array_merge($this->orders[777], ['status' => 'ACT', 'certificate' => $certificate->pem, 'intermediate_certificate' => self::$ca->pem,
            'expiration_date' => $certificate->notAfter->format('Y-m-d H:i:s')]);
        $this->artisan('ssl:sync')->assertSuccessful();

        return $certificate;
    }

    private function order(): SslOrder
    {
        return SslOrder::query()->where('service_id', $this->service->id)->sole();
    }

    private function portal(?User $as = null)
    {
        return $this->livewireAs($as ?? $this->owner)->test(Show::class, ['service' => $this->service->fresh()]);
    }

    /** In the client area: a key made here for the domain, then the order asked for with DNS validation. */
    private function generateAndOrder(string $type = 'rsa2048'): SslVersion
    {
        $this->portal()->set('sslDomain', 'shop.example.test')->set('sslKeyType', $type)->call('sslGenerate')
            ->assertSet('sslName', 'shop.example.test')->set('sslMethod', 'dns')->call('sslOrder')->assertHasNoErrors();

        return $this->order()->versions()->sole();
    }

    private function fileUrl(SslVersion $version, string $file, ?Service $service = null): string
    {
        return route('services.ssl.file', ['service' => $service ?? $this->service, 'version' => $version, 'file' => $file]);
    }

    /** A customer's login in the client area, with its session (the client area's middleware asks for one). */
    private function customer(User $user): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user));
    }

    /** Staff in «Login as client»: no customer login of their own (the app keeps the last request's), the customer's id in the session. */
    private function asStaffInPortal(Staff $staff): static
    {
        Auth::guard('web')->forgetUser();

        return $this->actingAs($staff)->withSession(['impersonating' => $this->owner->id]);
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->withPermissions($permissions)->create();
    }

    /**
     * ADV-1: «Keep the current key» with an RSA key makes a CSR byte-identical to the one in use (PKCS#1 v1.5
     * signatures are deterministic, same names). The customer generates it and walks away, never reissuing: the next
     * sync's markOrdered matches the order's (old) CSR to the newest version with that CSR, the draft, and marks it
     * ordered. A draft never ordered then shows as «replaced» and takes the next certificate of the key.
     */
    public function test_adv_1_a_kept_rsa_key_never_reissued_stays_a_draft(): void
    {
        $first = $this->generateAndOrder('rsa2048');
        $this->issue();

        $this->portal()->set('sslReissueKey', 'keep')->call('sslGenerate')->assertSet('sslName', 'shop.example.test');
        $draft = $this->order()->versions()->where('number', 2)->sole();
        $this->assertNull($draft->ordered_at);
        // (identical CSR: the root of it)
        $this->assertSame(trim((string) $first->csr), trim((string) $draft->csr));

        $this->artisan('ssl:sync')->assertSuccessful(); // the hourly sync, nothing reissued

        $this->assertNull($draft->fresh()->ordered_at, 'a CSR generated and never sent to Openprovider was marked ordered');
    }

    /**
     * ADV-2: a domain whose name is longer than 64 characters passes namesFor (253 allowed) but OpenSSL refuses it as
     * the subject's commonName (ub-common-name 64) with a PHP warning, which Laravel turns into an ErrorException.
     * Csr::create catches only its own failures, generate() only SslToolsException: the customer's click is a 500
     * (and reported), not a «domain not valid» refusal. In the admin the same is told as «Openprovider did not answer».
     */
    public function test_adv_2_a_domain_too_long_for_a_common_name_is_refused_not_a_crash(): void
    {
        $long = str_repeat('a', 60) . '.example.test';
        $this->assertSame([$long, "www.{$long}"], SslVault::namesFor($long));

        $this->portal()->set('sslDomain', $long)->call('sslGenerate')->assertSet('sslName', null);
        $this->assertSame(0, SslVersion::query()->count());
    }

    /**
     * ADV-3: «Attach existing key» takes the PEM through a Filament form, i.e. Livewire state. Refused (here: a real key,
     * not this version's), the action halts with the pasted key still in mountedActions data, and the snapshot sent back
     * to the browser carries the private key, against «never in the state of Livewire».
     */
    public function test_adv_3_a_key_pasted_to_attach_is_not_kept_in_the_livewire_snapshot(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $key = PrivateKey::generate(KeyType::Rsa2048);
        $this->portal()->set('sslMode', 'paste')->set('sslCsr', Csr::create($key, ['shop.example.test', 'www.shop.example.test'])->pem)
            ->call('sslCheck')->set('sslMethod', 'dns')->call('sslOrder')->assertHasNoErrors();
        $this->issue();
        $version = $this->order()->versions()->sole();
        $staff = $this->staff(['admin.services.viewAny', 'admin.services.update', SslVault::KEY_PERMISSION]);

        $other = PrivateKey::generate(KeyType::Rsa2048)->pem();
        $page = $this->livewireAs($staff)->test(SslVersionsRelationManager::class, ['ownerRecord' => $this->service->fresh(), 'pageClass' => EditService::class])
            ->callTableAction('attachKey', $version, data: ['key' => $other]);
        $this->assertFalse($version->fresh()->hasKey());

        $body = explode("\n", $other)[3]; // one line of base64: no newline or escaping in the way
        $this->assertStringNotContainsString($body, json_encode($page->snapshot, JSON_UNESCAPED_SLASHES), 'the pasted private key stays in the Livewire snapshot');
    }

    /**
     * ADV-4 (defence): the admin's vault table, rendered for staff who may see keys, neither shows a key nor writes
     * a «downloaded» audit until «Reveal key» is opened (the modal's content is not rendered with the table).
     */
    public function test_adv_4_the_admin_table_shows_and_audits_no_key_until_revealed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $version = $this->generateAndOrder();
        $this->issue();
        $staff = $this->staff(['admin.services.viewAny', 'admin.services.update', SslVault::KEY_PERMISSION]);
        $body = explode("\n", $version->privateKey()->pem())[3];

        $page = $this->livewireAs($staff)->test(SslVersionsRelationManager::class, ['ownerRecord' => $this->service->fresh(), 'pageClass' => EditService::class])
            ->mountTableAction('viewPem', $version);
        $this->assertStringNotContainsString($body, $page->html());
        $this->assertStringNotContainsString($body, json_encode($page->snapshot, JSON_UNESCAPED_SLASHES));
        $this->assertFalse(Audit::query()->where('event', 'ssl_key_downloaded')->exists());
    }

    /**
     * ADV-5 (defence): another account's version id, asked through the attacker's own SSL service (one they manage),
     * gives nothing: not its key, CSR or .pfx, and writes no audit.
     */
    public function test_adv_5_another_accounts_version_through_my_own_service_is_not_found(): void
    {
        $victim = $this->generateAndOrder();
        $this->issue();
        $attacker = User::factory()->create();
        $theirs = Service::factory()->create(['client_id' => $attacker->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'server_id' => $this->service->server_id, 'status' => 'active']);

        $this->customer($attacker);
        foreach (['key', 'csr', 'crt'] as $file) {
            $this->get($this->fileUrl($victim, $file, $theirs))->assertNotFound();
        }
        $this->post(route('services.ssl.pfx', ['service' => $theirs, 'version' => $victim]), ['password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertNotFound();
        $this->assertFalse(Audit::query()->where('event', 'ssl_key_downloaded')->exists());
    }
}
