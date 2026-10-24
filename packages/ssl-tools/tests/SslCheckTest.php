<?php

namespace MyCS\SslTools\Tests;

use DateTimeImmutable;
use MyCS\SslTools\Certificate;
use MyCS\SslTools\CheckResult;
use MyCS\SslTools\Csr;
use MyCS\SslTools\KeyType;
use MyCS\SslTools\PrivateKey;
use MyCS\SslTools\SslCheck;
use PHPUnit\Framework\TestCase;

class SslCheckTest extends TestCase
{
    private static PrivateKey $rootKey;

    private static Certificate $root;

    private static PrivateKey $interKey;

    private static Certificate $inter;

    private static string $caFile;

    private PrivateKey $key;

    private Csr $csr;

    private Certificate $leaf;

    public static function setUpBeforeClass(): void
    {
        self::$rootKey = PrivateKey::generate(KeyType::EcP256);
        self::$root = self::sign(Csr::create(self::$rootKey, ['root.example.test']), self::$rootKey, null, ca: true);
        self::$interKey = PrivateKey::generate(KeyType::EcP256);
        self::$inter = self::sign(Csr::create(self::$interKey, ['inter.example.test']), self::$rootKey, self::$root, ca: true);
        self::$caFile = (string) tempnam(sys_get_temp_dir(), 'roots');
        file_put_contents(self::$caFile, self::$root->pem);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$caFile);
    }

    protected function setUp(): void
    {
        $this->key = PrivateKey::generate(KeyType::Rsa2048);
        $this->csr = Csr::create($this->key, ['example.gr', 'www.example.gr']);
        $this->leaf = self::sign($this->csr, self::$interKey, self::$inter);
    }

    private static function sign(Csr $csr, PrivateKey $by, ?Certificate $issuer, bool $ca = false, int $days = 90): Certificate
    {
        $config = tempnam(sys_get_temp_dir(), 'crt');
        $ext = $ca ? "basicConstraints = critical, CA:TRUE\nkeyUsage = critical, keyCertSign, cRLSign\n"
            : "basicConstraints = CA:FALSE\nkeyUsage = digitalSignature, keyEncipherment\nextendedKeyUsage = serverAuth\nsubjectAltName = "
                . implode(',', array_map(fn ($n) => "DNS:{$n}", $csr->names)) . "\n";
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\n{$ext}");
        $x509 = openssl_csr_sign($csr->pem, $issuer?->pem, $by->key(), $days, ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        unlink($config);
        openssl_x509_export($x509, $pem);

        return Certificate::parse($pem);
    }

    /**
     * @param  list<CheckResult>  $results
     * @return array<string, string> code => status
     */
    private static function codes(array $results): array
    {
        return array_column(array_map(fn (CheckResult $r) => $r->toArray(), $results), 'status', 'code');
    }

    private static function find(array $results, string $code): CheckResult
    {
        foreach ($results as $result) {
            if ($result->code === $code) {
                return $result;
            }
        }
        self::fail("no {$code}");
    }

    public function test_all_three_that_belong_together(): void
    {
        $results = SslCheck::run($this->key->pem(), $this->csr->pem, $this->leaf->pem . self::$inter->pem, self::$caFile);

        $this->assertSame([
            'key.read' => 'info', 'csr.read' => 'info', 'certificate.read' => 'info',
            'match.key_csr' => 'ok', 'match.key_certificate' => 'ok', 'match.csr_certificate' => 'ok', 'names.covered' => 'ok',
            'chain.trusted' => 'ok', 'dates.valid' => 'ok',
        ], self::codes($results));
        $this->assertSame('example.gr → inter.example.test', self::find($results, 'chain.trusted')->values['path']);
        $this->assertSame('example.gr, www.example.gr', self::find($results, 'certificate.read')->values['names']);
        $this->assertSame('inter.example.test', self::find($results, 'certificate.read')->values['issuer']);
    }

    public function test_a_bundle_in_any_order_with_an_extra_certificate(): void
    {
        $stranger = self::sign(Csr::create(PrivateKey::generate(KeyType::EcP256), ['other.example.test']), self::$rootKey, self::$root, ca: true);

        $results = SslCheck::run(null, null, $this->leaf->pem . $stranger->pem . self::$root->pem . self::$inter->pem, self::$caFile);

        $codes = self::codes($results);
        $this->assertSame('ok', $codes['chain.trusted']);
        $this->assertSame('example.gr → inter.example.test → root.example.test', self::find($results, 'chain.trusted')->values['path']);
        $this->assertSame('other.example.test', self::find($results, 'chain.extra')->values['names']);
    }

    public function test_a_missing_intermediate_and_an_untrusted_root(): void
    {
        $missing = SslCheck::run(null, null, $this->leaf->pem, self::$caFile);
        $this->assertSame('fail', self::codes($missing)['chain.incomplete']);
        $this->assertSame('inter.example.test', self::find($missing, 'chain.incomplete')->values['missing']);

        $selfKey = PrivateKey::generate(KeyType::EcP256);
        $self = self::sign(Csr::create($selfKey, ['example.gr']), $selfKey, null);
        $this->assertSame('fail', self::codes(SslCheck::run(null, null, $self->pem, self::$caFile))['chain.untrusted_root']);
    }

    public function test_what_does_not_belong_together(): void
    {
        $otherCsr = Csr::create(PrivateKey::generate(KeyType::Rsa2048), ['shop.example.gr']);
        $codes = self::codes(SslCheck::run(PrivateKey::generate(KeyType::EcP256)->pem(), $otherCsr->pem, $this->leaf->pem, self::$caFile));

        $this->assertSame('fail', $codes['match.key_csr']);
        $this->assertSame('fail', $codes['match.key_certificate']);
        $this->assertSame('fail', $codes['match.csr_certificate']);
        $this->assertSame('fail', $codes['names.covered']);
    }

    public function test_dates(): void
    {
        $soon = $this->leaf->notAfter->modify('-10 days');
        $this->assertSame('warn', self::codes(SslCheck::run(null, null, $this->leaf->pem, self::$caFile, $soon))['dates.soon']);
        $this->assertSame('fail', self::codes(SslCheck::run(null, null, $this->leaf->pem, self::$caFile, $this->leaf->notAfter->modify('+1 day')))['dates.expired']);
        $this->assertSame('fail', self::codes(SslCheck::run(null, null, $this->leaf->pem, self::$caFile, new DateTimeImmutable('2001-01-01')))['dates.not_yet']);
    }

    public function test_bad_input_is_told_and_no_result_holds_the_key(): void
    {
        $results = SslCheck::run($this->key->pem(), "-----BEGIN CERTIFICATE REQUEST-----\nAAAA\n-----END CERTIFICATE REQUEST-----", 'no certificate here', self::$caFile);

        $codes = self::codes($results);
        $this->assertSame('info', $codes['key.read']);
        $this->assertSame('fail', $codes['csr.invalid']);
        $this->assertSame('fail', $codes['certificate.invalid']);
        $line = explode("\n", $this->key->pem())[2];
        $this->assertStringNotContainsString($line, json_encode(array_map(fn (CheckResult $r) => $r->toArray(), $results), JSON_UNESCAPED_SLASHES));
        $this->assertSame([], SslCheck::run(null, '  ', null));
    }
}
