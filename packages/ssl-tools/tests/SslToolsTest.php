<?php

namespace MyCS\SslTools\Tests;

use MyCS\SslTools\Certificate;
use MyCS\SslTools\Csr;
use MyCS\SslTools\KeyType;
use MyCS\SslTools\Pkcs12;
use MyCS\SslTools\PrivateKey;
use MyCS\SslTools\SslToolsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SslToolsTest extends TestCase
{
    /** A certificate for the CSR, signed by a throwaway CA (or by its own key). */
    private static function sign(Csr $csr, PrivateKey $by, ?string $caPem = null): Certificate
    {
        $config = tempnam(sys_get_temp_dir(), 'crt');
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = " . implode(',', array_map(fn ($n) => "DNS:{$n}", $csr->names)) . "\n");
        $x509 = openssl_csr_sign($csr->pem, $caPem, $by->key(), 90, ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'], 4242);
        unlink($config);
        openssl_x509_export($x509, $pem);

        return Certificate::parse($pem);
    }

    /** @return array<string, array{KeyType, string}> */
    public static function types(): array
    {
        return ['rsa' => [KeyType::Rsa2048, 'RSA 2048'], 'ec' => [KeyType::EcP256, 'EC prime256v1']];
    }

    #[DataProvider('types')]
    public function test_a_key_and_its_csr_carry_the_names_and_match(KeyType $type, string $description): void
    {
        $key = PrivateKey::generate($type);
        $this->assertSame($type, $key->type);
        $this->assertSame($description, $key->description);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $key->pem());

        $csr = Csr::create($key, ['example.gr', 'WWW.example.gr']);
        $this->assertSame('example.gr', $csr->commonName);
        $this->assertSame(['example.gr', 'www.example.gr'], $csr->names);
        $this->assertSame($type, $csr->keyType);
        $this->assertTrue($key->matches($csr));
        $this->assertFalse(PrivateKey::generate($type)->matches($csr));
        $this->assertStringNotContainsString('PRIVATE KEY', $csr->pem);
    }

    public function test_a_wildcard_csr(): void
    {
        $csr = Csr::create(PrivateKey::generate(KeyType::EcP256), ['*.example.gr', 'example.gr']);

        $this->assertSame('*.example.gr', $csr->commonName);
        $this->assertSame(['*.example.gr', 'example.gr'], $csr->names);
    }

    /** @return array<string, array{list<string>}> */
    public static function badNames(): array
    {
        return [
            'none' => [[]],
            'one label' => [['localhost']],
            'newline' => [["example.gr\nsubjectAltName = DNS:evil.com"]],
            'comma' => [['example.gr,DNS:evil.com']],
            'inner wildcard' => [['www.*.example.gr']],
            'space' => [['exa mple.gr']],
            'unicode' => [['παράδειγμα.gr']],
            'leading hyphen' => [['-example.gr']],
            'ip' => [['192.0.2.1']],
            'common name over 64' => [[str_repeat('a', 60) . '.example.gr']],
        ];
    }

    /** @param list<string> $names */
    #[DataProvider('badNames')]
    public function test_a_csr_takes_only_host_names(array $names): void
    {
        $this->expectException(SslToolsException::class);
        Csr::create(PrivateKey::generate(KeyType::EcP256), $names);
    }

    public function test_a_pasted_key_is_read_in_its_older_forms_and_kept_as_pkcs8(): void
    {
        $key = openssl_pkey_new(KeyType::Rsa2048->options());
        openssl_pkey_export($key, $pkcs8);
        $details = openssl_pkey_get_details($key);
        // The older «RSA PRIVATE KEY» form, as many servers keep it.
        $traditional = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split(base64_encode(self::rsaTraditional($pkcs8)), 64, "\n") . "-----END RSA PRIVATE KEY-----\n";

        $parsed = PrivateKey::parse("junk before\r\n" . str_replace("\n", "\r\n", $traditional) . "\r\njunk after");

        $this->assertSame(PrivateKey::parse($pkcs8)->spkiSha256, $parsed->spkiSha256);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $parsed->pem());
        $this->assertSame(2048, $details['bits']);
    }

    /** The PKCS#1 key inside a PKCS#8 RSA key (its OCTET STRING), for the test above. */
    private static function rsaTraditional(string $pkcs8): string
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pkcs8));
        // SEQUENCE { INTEGER 0, SEQUENCE { OID, NULL }, OCTET STRING { RSAPrivateKey } }: the octet string is last.
        $pos = strrpos(substr($der, 0, 40), "\x04\x82");
        $length = (ord($der[$pos + 2]) << 8) | ord($der[$pos + 3]);

        return substr($der, $pos + 4, $length);
    }

    public function test_an_encrypted_key_is_refused_and_no_error_quotes_a_key(): void
    {
        $key = openssl_pkey_new(KeyType::EcP256->options());
        openssl_pkey_export($key, $encrypted, 'secret-passphrase');

        foreach ([$encrypted, "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----", 'nothing', $encrypted . "\n" . $encrypted] as $text) {
            try {
                PrivateKey::parse($text);
                $this->fail('read');
            } catch (SslToolsException $e) {
                $this->assertStringNotContainsString('BEGIN', $e->getMessage());
                $this->assertStringNotContainsString('AAAA', $e->getMessage());
                $this->assertFalse(openssl_error_string(), 'OpenSSL errors left behind');
            }
        }
    }

    public function test_a_key_is_not_dumped_nor_serialized(): void
    {
        $key = PrivateKey::generate(KeyType::EcP256);
        $body = substr($key->pem(), 40, 30);

        $this->assertStringNotContainsString($body, print_r($key, true));
        $this->assertStringNotContainsString($body, (string) json_encode($key));
        $this->expectException(SslToolsException::class);
        serialize($key);
    }

    public function test_a_csr_signed_by_another_key_is_refused(): void
    {
        $csr = Csr::create(PrivateKey::generate(KeyType::Rsa2048), ['example.gr']);
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $csr->pem));
        $der[strlen($der) - 5] = chr(ord($der[strlen($der) - 5]) ^ 0xFF); // the signature's bytes: changed
        $tampered = "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE REQUEST-----\n";

        $this->expectException(SslToolsException::class);
        Csr::parse($tampered);
    }

    public function test_a_csr_from_openssl_req_with_new_header(): void
    {
        $csr = Csr::create(PrivateKey::generate(KeyType::Rsa2048), ['shop.example.gr']);
        $legacy = str_replace('CERTIFICATE REQUEST', 'NEW CERTIFICATE REQUEST', $csr->pem);

        $this->assertSame($csr->spkiSha256, Csr::parse("Your CSR:\n" . $legacy)->spkiSha256);
    }

    public function test_garbage_der_is_a_clean_refusal(): void
    {
        foreach (["\x30\x84\xFF\xFF\xFF\xFF", "\x30\x02\x30\x05", str_repeat("\x30\x80", 50), ''] as $der) {
            $pem = "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($der) ?: 'AA==', 64, "\n") . "-----END CERTIFICATE REQUEST-----\n";
            try {
                Csr::parse($pem);
                $this->fail('read');
            } catch (SslToolsException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_certificate_its_chain_and_a_pfx(): void
    {
        $caKey = PrivateKey::generate(KeyType::EcP256);
        $ca = self::sign(Csr::create($caKey, ['ca.example.test']), $caKey);
        $key = PrivateKey::generate(KeyType::Rsa2048);
        $csr = Csr::create($key, ['example.gr', 'www.example.gr']);
        $certificate = self::sign($csr, $caKey, $ca->pem);

        $this->assertSame('example.gr', $certificate->commonName);
        $this->assertSame(['example.gr', 'www.example.gr'], $certificate->names);
        $this->assertSame('ca.example.test', $certificate->issuer);
        $this->assertSame('1092', $certificate->serial);
        $this->assertTrue($certificate->matches($csr));
        $this->assertTrue($key->matches($certificate));
        $this->assertFalse($caKey->matches($certificate));
        $this->assertGreaterThan(time() + 80 * 86400, $certificate->notAfter->getTimestamp());

        $both = Certificate::parseAll($certificate->pem . "\n" . $ca->pem);
        $this->assertSame(['example.gr', 'ca.example.test'], array_map(fn (Certificate $c) => $c->commonName, $both));

        $pfx = Pkcs12::export($certificate, $key, [$ca], 'a-good-password');
        $this->assertTrue(openssl_pkcs12_read($pfx, $read, 'a-good-password'));
        $this->assertSame(PrivateKey::parse($read['pkey'])->spkiSha256, $key->spkiSha256);
        $this->assertCount(1, $read['extracerts']);
        $this->assertFalse(openssl_pkcs12_read($pfx, $read, 'wrong-password'));
        SslToolsException::clearOpensslErrors();
    }

    public function test_a_pfx_needs_its_own_key_and_a_password(): void
    {
        $key = PrivateKey::generate(KeyType::EcP256);
        $certificate = self::sign(Csr::create($key, ['example.gr']), $key);

        foreach ([[PrivateKey::generate(KeyType::EcP256), 'a-good-password'], [$key, 'short']] as [$with, $password]) {
            try {
                Pkcs12::export($certificate, $with, [], $password);
                $this->fail('exported');
            } catch (SslToolsException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
