<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\RelationManagers\SslVersionsRelationManager;
use App\Exceptions\ServerRefused;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show;
use App\Models\ClientUser;
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
use Illuminate\Support\Facades\DB;
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
 * The SSL vault (docs/ssl-vault.md): a key and CSR made here for the order (portal and admin), kept encrypted; the
 * certificate filed under its key's version; files to whoever sees the service, the key only to a login that manages
 * it (staff: their own permission), audited, rate-limited; a reissue keeps or rotates the key; a key kept elsewhere
 * attached only to its own CSR. Openprovider is faked, with real CSRs and certificates (a throwaway CA).
 */
class SslVaultTest extends TestCase
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

    public function test_a_key_made_in_the_portal_is_ordered_kept_encrypted_and_never_in_the_page(): void
    {
        $page = $this->portal()->set('sslDomain', 'shop.example.test')->call('sslGenerate')->assertSet('sslName', 'shop.example.test');
        $version = $this->order()->versions()->sole();
        $key = $version->privateKey();

        $this->assertSame(KeyType::Rsa2048, $key->type);
        $this->assertSame(['shop.example.test', 'www.shop.example.test'], $version->host_names);
        $this->assertNull($version->ordered_at);
        // Encrypted at rest, hidden from arrays, never in the Livewire snapshot or the HTML.
        $raw = (string) DB::table('ssl_versions')->value('private_key');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertSame($key->pem(), decrypt($raw, false));
        $this->assertArrayNotHasKey('private_key', $version->toArray());
        $body = explode("\n", $key->pem())[2]; // one whole base64 line; slashes unescaped below (adversarial note of #437)
        $this->assertStringNotContainsString($body, json_encode($page->snapshot, JSON_UNESCAPED_SLASHES));
        $this->assertStringNotContainsString($body, $page->html());

        $page->set('sslMethod', 'dns')->call('sslOrder')->assertHasNoErrors();
        $this->assertSame(trim($version->csr), trim($this->orders[777]['csr']));
        $this->assertNotNull($version->fresh()->ordered_at);
        $this->assertTrue(Audit::query()->where('event', 'ssl_key_generated')->where('auditable_id', $this->service->id)->exists());
    }

    public function test_the_certificate_is_filed_under_its_key_and_its_files_downloaded(): void
    {
        $version = $this->generateAndOrder('ec-p256');
        $certificate = $this->issue();
        $version->refresh();

        $this->assertSame($certificate->pem, $version->certificate);
        $this->assertSame(trim(self::$ca->pem), trim((string) $version->chain));
        $this->assertSame($certificate->notAfter->getTimestamp(), $version->expires_at->getTimestamp());
        $this->assertSame($version->id, app(SslVault::class)->current($this->order())->id);

        $this->customer($this->owner);
        $this->get($this->fileUrl($version, 'crt'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertDownload('shop.example.test-1.crt');
        $this->assertSame(trim($certificate->pem) . "\n" . trim(self::$ca->pem) . "\n", $this->get($this->fileUrl($version, 'fullchain'))->getContent());
        $this->assertSame($version->privateKey()->pem(), $this->get($this->fileUrl($version, 'key'))->assertOk()->getContent());
        $audit = Audit::query()->where('event', 'ssl_key_downloaded')->sole();
        $this->assertSame(['version' => 1, 'spki_sha256' => $version->spki_sha256, 'file' => 'key', 'via' => 'portal'], $audit->new_values);
        $this->assertSame($this->owner->id, (int) $audit->user_id);

        $pfx = $this->post(route('services.ssl.pfx', ['service' => $this->service, 'version' => $version]),
            ['password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertOk()->getContent();
        $this->assertTrue(openssl_pkcs12_read($pfx, $read, 'a-long-password'));
        $this->assertSame($version->spki_sha256, PrivateKey::parse($read['pkey'])->spkiSha256);
        $this->post(route('services.ssl.pfx', ['service' => $this->service, 'version' => $version]),
            ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');

        // Nothing written to the audits holds the key.
        $body = substr($version->privateKey()->pem(), 40, 40);
        $this->assertFalse(Audit::query()->get()->contains(fn (Audit $a) => str_contains(json_encode($a->toArray()), $body)));
    }

    public function test_only_a_login_that_manages_the_service_has_its_key(): void
    {
        $version = $this->generateAndOrder();
        $this->issue();

        // Another account: nothing at all.
        $this->customer(User::factory()->create());
        $this->get($this->fileUrl($version, 'crt'))->assertNotFound(); // as its service page: not theirs, not there
        $this->get($this->fileUrl($version, 'key'))->assertNotFound();

        // A login of the account that only sees its services: the certificate, never the key.
        $viewer = User::factory()->create();
        ClientUser::query()->create(['user_id' => $viewer->id, 'client_id' => $this->owner->client()->id, 'permissions' => ['products']]);
        $this->customer($viewer);
        $this->get($this->fileUrl($version, 'crt'))->assertOk();
        $this->get($this->fileUrl($version, 'key'))->assertForbidden();
        $this->post(route('services.ssl.pfx', ['service' => $this->service, 'version' => $version]), ['password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertForbidden();
        $this->assertFalse(Audit::query()->where('event', 'ssl_key_downloaded')->exists());
    }

    public function test_a_version_is_reached_only_through_its_own_service(): void
    {
        $version = $this->generateAndOrder();
        $mine = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id, 'status' => 'active']);

        $this->customer($this->owner);
        $this->get($this->fileUrl($version, 'key', $mine))->assertNotFound();
        $this->get($this->fileUrl($version, 'csr', $mine))->assertNotFound();
        $this->get(str_replace('/csr', '/pem', $this->fileUrl($version, 'csr')))->assertNotFound();
    }

    public function test_staff_logged_in_as_the_client_need_the_key_permission(): void
    {
        $version = $this->generateAndOrder();
        $plain = $this->staff(['admin.users.impersonate', 'admin.services.update']);
        $keys = $this->staff(['admin.users.impersonate', 'admin.services.update', SslVault::KEY_PERMISSION]);

        $this->asStaffInPortal($plain)->get($this->fileUrl($version, 'csr'))->assertOk();
        $this->asStaffInPortal($plain)->get($this->fileUrl($version, 'key'))->assertForbidden();
        $this->asStaffInPortal($keys)->get($this->fileUrl($version, 'key'))->assertOk();

        $audit = Audit::query()->where('event', 'ssl_key_downloaded')->sole();
        $this->assertSame((new Staff)->getMorphClass(), $audit->user_type); // the staff member, never the customer they look through
        $this->assertSame($keys->id, (int) $audit->user_id);
    }

    public function test_generation_and_key_downloads_are_rate_limited(): void
    {
        $page = $this->portal()->set('sslDomain', 'shop.example.test');
        for ($i = 0; $i < 6; $i++) {
            $page->call('sslGenerate');
        }
        $this->assertSame(5, $this->order()->versions()->count());

        $version = $this->order()->versions()->first();
        $this->customer($this->owner);
        for ($i = 0; $i < 20; $i++) {
            $this->get($this->fileUrl($version, 'key'))->assertOk();
        }
        $this->get($this->fileUrl($version, 'key'))->assertTooManyRequests();
    }

    public function test_only_the_names_the_order_takes_are_generated(): void
    {
        $page = $this->portal();
        foreach (['localhost', "shop.example.test\nDNS:evil.test", 'shop.example.test,DNS:evil.test', '192.0.2.10', ''] as $domain) {
            $page->set('sslDomain', $domain)->call('sslGenerate')->assertSet('sslName', null);
        }
        $this->assertSame(0, SslVersion::query()->count());

        $page->set('sslDomain', 'WWW.Shop.Example.Test')->call('sslGenerate')->assertSet('sslName', 'www.shop.example.test');
        $this->assertSame(['www.shop.example.test', 'shop.example.test'], SslVersion::query()->sole()->host_names);
        $this->assertSame(['*.example.test', 'example.test'], SslVault::namesFor('*.example.test'));
    }

    public function test_a_reissue_keeps_or_rotates_the_key_and_every_certificate_stays(): void
    {
        $first = $this->generateAndOrder();
        $firstCertificate = $this->issue();

        // Kept: an RSA key's CSR for the same names is the same CSR, so no second draft; the new certificate gets a
        // version of its own with the same key, the old one stays where it was.
        $this->portal()->set('sslReissueKey', 'keep')->call('sslGenerate')->assertSet('sslName', 'shop.example.test')
            ->set('sslMethod', 'dns')->call('sslReissue')->assertHasNoErrors();
        $this->assertSame(1, $this->order()->versions()->count());
        $second = $this->issue();
        $kept = $this->order()->versions()->where('number', 2)->sole();
        $this->assertSame($first->spki_sha256, $kept->spki_sha256);
        $this->assertSame($first->privateKey()->pem(), $kept->privateKey()->pem());
        $this->assertSame($second->pem, $kept->certificate);
        $this->assertSame($firstCertificate->pem, $first->fresh()->certificate);

        // Rotated: another key.
        $this->portal()->set('sslReissueKey', 'new')->set('sslKeyType', 'ec-p256')->call('sslGenerate')
            ->set('sslMethod', 'dns')->call('sslReissue')->assertHasNoErrors();
        $rotated = $this->order()->versions()->where('number', 3)->sole();
        $this->assertNotSame($first->spki_sha256, $rotated->spki_sha256);
        $this->assertSame(KeyType::EcP256->value, $rotated->key_type);
        $third = $this->issue();
        $this->assertSame($third->pem, $rotated->fresh()->certificate);
        $this->assertSame($rotated->id, app(SslVault::class)->current($this->order())->id);
        $this->assertSame(3, $this->order()->versions()->count());
    }

    public function test_a_pasted_csr_has_no_key_until_staff_attach_its_own(): void
    {
        $key = PrivateKey::generate(KeyType::Rsa2048);
        $csr = Csr::create($key, ['shop.example.test', 'www.shop.example.test']);
        $this->portal()->set('sslMode', 'paste')->set('sslCsr', $csr->pem)->call('sslCheck')->set('sslMethod', 'dns')->call('sslOrder')->assertHasNoErrors();
        $certificate = $this->issue();
        $version = $this->order()->versions()->sole();
        $this->assertSame(SslVersion::SOURCE_PASTED, $version->source);
        $this->assertFalse($version->hasKey());
        $this->assertSame($certificate->pem, $version->certificate);

        $this->customer($this->owner)->get($this->fileUrl($version, 'key'))->assertNotFound();

        $vault = app(SslVault::class);
        foreach ([PrivateKey::generate(KeyType::Rsa2048)->pem(), "-----BEGIN PRIVATE KEY-----\nnot a key\n-----END PRIVATE KEY-----"] as $wrong) {
            try {
                $vault->attachKey($version, $wrong);
                $this->fail('attached');
            } catch (ServerRefused $e) {
                $this->assertStringNotContainsString('not a key', $e->getMessage());
            }
        }
        $vault->attachKey($version, $key->pem());
        $this->assertSame($key->pem(), $version->fresh()->privateKey()->pem());
        $this->assertTrue(Audit::query()->where('event', 'ssl_key_attached')->exists());
    }

    public function test_a_certificate_issued_before_the_vault_is_filed_on_the_next_sync(): void
    {
        $key = PrivateKey::generate(KeyType::Rsa2048);
        $csr = Csr::create($key, ['shop.example.test']);
        $this->portal()->set('sslMode', 'paste')->set('sslCsr', $csr->pem)->call('sslCheck')->set('sslMethod', 'dns')->call('sslOrder');
        SslVersion::query()->delete(); // as before this change

        $certificate = $this->issue();

        $version = $this->order()->versions()->sole();
        $this->assertSame($certificate->pem, $version->certificate);
        $this->assertSame($key->spkiSha256, $version->spki_sha256);
        $this->assertSame(trim($csr->pem), trim((string) $version->csr));
    }

    public function test_admin_key_actions_need_their_own_permission_and_are_audited(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $version = $this->generateAndOrder();
        $this->issue();
        $page = fn (Staff $staff) => $this->livewireAs($staff)->test(SslVersionsRelationManager::class, ['ownerRecord' => $this->service->fresh(), 'pageClass' => EditService::class]);

        $plain = $this->staff(['admin.services.viewAny', 'admin.services.update']);
        $page($plain)->assertTableActionHidden('downloadKey', $version)->assertTableActionHidden('revealKey', $version)
            ->assertTableActionVisible('download-crt', $version);

        $keys = $this->staff(['admin.services.viewAny', 'admin.services.update', SslVault::KEY_PERMISSION]);
        $page($keys)->callTableAction('downloadKey', $version)->assertFileDownloaded('shop.example.test-1.key');
        $audit = Audit::query()->where('event', 'ssl_key_downloaded')->sole();
        $this->assertSame($keys->id, (int) $audit->user_id);
        $this->assertSame('admin', $audit->new_values['via']);
    }

    public function test_staff_generate_a_key_and_order_for_the_customer(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = $this->staff(['admin.services.viewAny', 'admin.services.update']);

        $this->livewireAs($staff)->test(SslVersionsRelationManager::class, ['ownerRecord' => $this->service->fresh(), 'pageClass' => EditService::class])
            ->callTableAction('sslGenerateOrder', data: ['domain' => 'shop.example.test', 'key_type' => 'ec-p256', 'method' => 'dns'])
            ->assertHasNoTableActionErrors();

        $version = $this->order()->versions()->sole();
        $this->assertSame(KeyType::EcP256->value, $version->key_type);
        $this->assertNotNull($version->ordered_at);
        $this->assertSame(trim((string) $version->csr), trim($this->orders[777]['csr']));
        $this->assertSame($staff->id, $version->creator_id);
    }

    /** Review 1: a CSR the customer made from the vault's draft key brings that key with it. */
    public function test_a_csr_made_from_a_kept_key_carries_that_key(): void
    {
        $this->portal()->set('sslDomain', 'shop.example.test')->call('sslGenerate');
        $draft = $this->order()->versions()->sole();
        $ownCsr = Csr::create($draft->privateKey(), ['shop.example.test']); // other names, same key
        $this->portal()->set('sslMode', 'paste')->set('sslCsr', $ownCsr->pem)->call('sslCheck')->set('sslMethod', 'dns')->call('sslOrder')->assertHasNoErrors();
        $this->issue();

        $current = app(SslVault::class)->current($this->order());
        $this->assertNotSame($draft->id, $current->id);
        $this->assertTrue($current->hasKey());
        $this->assertSame($draft->privateKey()->pem(), $current->privateKey()->pem());
    }

    /** Review 2: a «keep the key» RSA request is the same CSR: no second draft, nothing marked ordered that was not sent. */
    public function test_a_kept_rsa_key_makes_no_duplicate_draft(): void
    {
        $first = $this->generateAndOrder();
        $this->issue();
        $this->portal()->set('sslReissueKey', 'keep')->call('sslGenerate')->assertSet('sslCsr', trim($first->csr) . "\n");

        $this->artisan('ssl:sync')->assertSuccessful();

        $this->assertSame(1, $this->order()->versions()->count());
        $this->assertSame($first->id, app(SslVault::class)->current($this->order())->id);
    }

    /** Review 3: an order started over has no version in use. */
    public function test_after_starting_over_no_version_is_in_use(): void
    {
        $this->generateAndOrder();
        $this->issue();
        $this->order()->forceFill(['previous_order_ids' => [777], 'order_id' => null, 'certificate' => null, 'csr' => null])->save();

        $this->assertNull(app(SslVault::class)->current($this->order()));
    }

    /** Review 4: revealing a key in the admin is audited once, however often the modal renders. */
    public function test_a_revealed_key_is_audited_once(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $version = $this->generateAndOrder();
        $staff = $this->staff(['admin.services.viewAny', 'admin.services.update', SslVault::KEY_PERMISSION]);

        $page = $this->livewireAs($staff)->test(SslVersionsRelationManager::class, ['ownerRecord' => $this->service->fresh(), 'pageClass' => EditService::class])
            ->mountTableAction('revealKey', $version);
        $page->call('$refresh')->call('$refresh');

        $this->assertSame(1, Audit::query()->where('event', 'ssl_key_downloaded')->count());
        $this->assertStringNotContainsString(substr($version->privateKey()->pem(), 40, 40), json_encode($page->snapshot));
    }

    /** Review 7 and 8: no key made for an order or reissue that cannot happen (a suspended service, a pending renewal). */
    public function test_no_key_is_generated_for_an_order_that_cannot_happen(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = $this->staff(['admin.services.viewAny', 'admin.services.update']);
        $this->service->forceFill(['status' => 'suspended'])->save();
        $this->livewireAs($staff)->test(SslVersionsRelationManager::class, ['ownerRecord' => $this->service->fresh(), 'pageClass' => EditService::class])
            ->assertTableActionHidden('sslGenerateOrder');

        $this->service->forceFill(['status' => 'active'])->save();
        $this->generateAndOrder();
        $this->issue();
        $this->order()->forceFill(['renew_requested_at' => now(), 'renew_for' => now()])->save();
        $this->portal()->set('sslReissueKey', 'new')->call('sslGenerate');
        $this->assertSame(1, $this->order()->versions()->count());
    }
}
