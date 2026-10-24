<?php

namespace Tests\Feature;

use App\Admin\Pages\SslChecker;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show;
use App\Models\DomainRegistrarConnection;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use MyCS\SslTools\Certificate;
use MyCS\SslTools\CheckResult;
use MyCS\SslTools\Csr;
use MyCS\SslTools\KeyType;
use MyCS\SslTools\PrivateKey;
use MyCS\SslTools\SslCheck;
use Tests\TestCase;

/**
 * Adversarial review of #438 (SSL verification tools): false verdicts from SslCheck, and private keys left in the
 * Livewire state of the portal's decode box and the staff SSL Checker. Openprovider is faked as in SslVaultTest.
 */
class SslCheckAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Service $service;

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
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $data = match ($path) {
                '/v1/auth/login' => ['token' => 'tok'],
                '/v1/customers' => ['handle' => 'XX123-GR'],
                '/v1/ssl/products/9' => ['id' => 9, 'is_wildcard_supported' => true],
                default => [],
            };

            return Http::response(['code' => 0, 'desc' => '', 'data' => $data]);
        });
        ExtensionHelper::createServer($this->service->fresh());
    }

    private function portal()
    {
        return $this->livewireAs($this->owner)->test(Show::class, ['service' => $this->service->fresh()]);
    }

    /** A certificate for the CSR: a CA (CA:TRUE) or a TLS server leaf with the CSR's names; self-signed without an issuer. */
    private static function sign(Csr $csr, PrivateKey $by, ?Certificate $issuer, bool $ca = false, int $days = 90): Certificate
    {
        $config = (string) tempnam(sys_get_temp_dir(), 'adv');
        $ext = $ca ? "basicConstraints = critical, CA:TRUE\nkeyUsage = critical, keyCertSign, cRLSign\n"
            : "basicConstraints = CA:FALSE\nkeyUsage = digitalSignature, keyEncipherment\nextendedKeyUsage = serverAuth\nsubjectAltName = "
                . implode(',', array_map(fn ($n) => "DNS:{$n}", $csr->names)) . "\n";
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\n{$ext}");
        $x509 = openssl_csr_sign($csr->pem, $issuer?->pem, $by->key(), $days, ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        unlink($config);
        openssl_x509_export($x509, $pem);

        return Certificate::parse($pem);
    }

    /** @param list<CheckResult> $results @return array<string, string> code => status */
    private static function codes(array $results): array
    {
        return array_column(array_map(fn (CheckResult $r) => $r->toArray(), $results), 'status', 'code');
    }

    /** @return array{0: PrivateKey, 1: Certificate, 2: PrivateKey, 3: Certificate, 4: string} root key, root, intermediate key, intermediate, CA file */
    private static function ca(): array
    {
        $rootKey = PrivateKey::generate(KeyType::EcP256);
        $root = self::sign(Csr::create($rootKey, ['root.adv.test']), $rootKey, null, ca: true);
        $interKey = PrivateKey::generate(KeyType::EcP256);
        $inter = self::sign(Csr::create($interKey, ['inter.adv.test']), $rootKey, $root, ca: true);
        $caFile = (string) tempnam(sys_get_temp_dir(), 'advroots');
        file_put_contents($caFile, $root->pem);

        return [$rootKey, $root, $interKey, $inter, $caFile];
    }

    /**
     * ADV-1: the portal's decode box keeps a pasted key when validation fails first. A key padded past 65536
     * characters fails `max:65536` before the «PRIVATE KEY» refusal runs, so the key stays in `sslDecodeText`, in the
     * Livewire snapshot and in the textarea, against "refused and cleared from the page at once".
     */
    public function test_adv_1_decode_keeps_a_key_when_validation_fails(): void
    {
        $key = PrivateKey::generate(KeyType::EcP256);
        $page = $this->portal()->set('sslDecodeText', $key->pem() . str_repeat("\n", 70000))->call('sslDecode');

        $this->assertStringNotContainsString('PRIVATE KEY', (string) $page->get('sslDecodeText'), 'a refused key must not stay in the page');
    }

    /**
     * ADV-2: rate-limited, the decode box returns before the key check and keeps the pasted key in its state (and the
     * HTML), though it is never decoded. The «never a key» clearing must come before every early return.
     */
    public function test_adv_2_decode_keeps_a_key_when_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('service-ssl-verify:' . $this->service->id, 3600);
        }
        $key = PrivateKey::generate(KeyType::EcP256);
        $page = $this->portal()->set('sslDecodeText', $key->pem())->call('sslDecode');

        $this->assertStringNotContainsString(explode("\n", $key->pem())[2], json_encode($page->snapshot, JSON_UNESCAPED_SLASHES));
    }

    /**
     * ADV-3: the staff SSL Checker clears only its key box. A combined PEM (certificate and key in one file, as haproxy
     * or many panels keep it) pasted in the certificate box is checked fine, and its private key stays in the page's
     * public `certificate` property: snapshot and HTML, against "the key is cleared from the page's state".
     */
    public function test_adv_3_checker_keeps_a_key_pasted_in_the_certificate_box(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        [, , $interKey, $inter] = self::ca();
        $key = PrivateKey::generate(KeyType::EcP256);
        $leaf = self::sign(Csr::create($key, ['shop.adv.test']), $interKey, $inter);
        $staff = Staff::factory()->withPermissions([SslChecker::PERMISSION])->create();

        $page = $this->livewireAs($staff)->test(SslChecker::class)
            ->set('certificate', $leaf->pem . $inter->pem . $key->pem())->call('check');

        $this->assertArrayHasKey('certificate.read', array_column($page->get('results'), 'status', 'code'));
        $this->assertStringNotContainsString(explode("\n", $key->pem())[2], json_encode($page->snapshot, JSON_UNESCAPED_SLASHES));
    }

    /**
     * ADV-4: a CSR with no commonName (only subjectAltNames, valid and accepted by CAs) gets a false "names not
     * covered": its empty CN ('') is merged into the requested names and is never in the certificate.
     */
    public function test_adv_4_csr_without_common_name_is_not_reported_uncovered(): void
    {
        [, , $interKey, $inter, $caFile] = self::ca();
        $key = PrivateKey::generate(KeyType::EcP256);
        $config = (string) tempnam(sys_get_temp_dir(), 'adv');
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = DNS:shop.adv.test,DNS:www.shop.adv.test\n");
        $dn = ['organizationName' => 'Shop'];
        $privateKey = $key->key();
        $request = openssl_csr_new($dn, $privateKey, ['config' => $config, 'req_extensions' => 'ext', 'digest_alg' => 'sha256']);
        unlink($config);
        openssl_csr_export($request, $pem);
        $csr = Csr::parse($pem);
        $this->assertSame('', $csr->commonName);
        $leaf = self::sign($csr, $interKey, $inter);

        $codes = self::codes(SslCheck::run($key->pem(), $csr->pem, $leaf->pem . $inter->pem, $caFile));
        @unlink($caFile);

        $this->assertSame('ok', $codes['match.csr_certificate']);
        $this->assertSame('ok', $codes['names.covered'], 'every name the CSR asks for is in the certificate');
    }

    /**
     * ADV-5: an expired certificate with its complete chain is reported as "chain incomplete, missing <the root>":
     * openssl_x509_checkpurpose fails on the date, and chain() then blames the path. Support would chase a CA bundle
     * that is already right.
     */
    public function test_adv_5_expired_certificate_is_not_reported_as_incomplete_chain(): void
    {
        [, , $interKey, $inter, $caFile] = self::ca();
        $key = PrivateKey::generate(KeyType::EcP256);
        $csr = Csr::create($key, ['shop.adv.test']);
        $fresh = self::sign($csr, $interKey, $inter);
        $expired = self::sign($csr, $interKey, $inter, days: 0); // notAfter = now
        sleep(2);

        $this->assertSame('ok', self::codes(SslCheck::run(null, null, $fresh->pem . $inter->pem, $caFile))['chain.trusted'], 'the same chain, valid');
        $codes = self::codes(SslCheck::run(null, null, $expired->pem . $inter->pem, $caFile));
        @unlink($caFile);

        $this->assertSame('fail', $codes['dates.expired']);
        $this->assertArrayNotHasKey('chain.incomplete', $codes, 'the chain is complete: only the date is wrong');
    }

    /**
     * ADV-6 (defence holds): a forged intermediate with the real one's DN, signed by another key, is not on the path
     * and gives no "trusted".
     */
    public function test_adv_6_forged_intermediate_with_the_right_dn_is_not_trusted(): void
    {
        [, , $interKey, $inter, $caFile] = self::ca();
        $key = PrivateKey::generate(KeyType::EcP256);
        $leaf = self::sign(Csr::create($key, ['shop.adv.test']), $interKey, $inter);
        $forgedKey = PrivateKey::generate(KeyType::EcP256);
        $forged = self::sign(Csr::create($forgedKey, ['inter.adv.test']), $forgedKey, null, ca: true); // the same DN, another key
        $this->assertSame($inter->subjectDn, $forged->subjectDn);

        $codes = self::codes(SslCheck::run(null, null, $leaf->pem . $forged->pem, $caFile));
        @unlink($caFile);

        $this->assertArrayNotHasKey('chain.trusted', $codes);
        $this->assertSame('fail', $codes['chain.incomplete']);
        $this->assertSame('warn', $codes['chain.extra']);
    }
}
