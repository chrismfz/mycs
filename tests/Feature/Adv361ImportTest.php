<?php

namespace Tests\Feature;

use App\Services\Import\WhmcsSecondFactor;
use Tests\TestCase;

/**
 * Review of #361, against WHMCS 8's real data (a test login, checked on 2026-10-23): `second_factor_config` is
 * `<iv hex>.<ciphertext hex>`, AES-256-CBC with the first 32 characters of sha256(cc_encryption_hash), its JSON
 * `{"secret": <base32>, "backupcode": <sha1 hex>}`. WHMCS's DecryptPassword cannot read it (garbage, «Malformed UTF-8»).
 * The ga4php `tokendata` the first review expected is not what WHMCS 8 stores.
 */
class Adv361ImportTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const HASH = 'a-whmcs-cc-encryption-hash-for-the-test';

    /** As WHMCS 8 stores it. */
    private static function stored(string $plain, string $hash = self::HASH): string
    {
        $iv = random_bytes(16);

        return bin2hex($iv) . '.' . bin2hex((string) openssl_encrypt($plain, 'aes-256-cbc', substr(hash('sha256', $hash), 0, 32), OPENSSL_RAW_DATA, $iv));
    }

    private function read(string $stored, string $hash = self::HASH): ?string
    {
        return (new WhmcsSecondFactor(fn (string $config) => WhmcsSecondFactor::decryptWith($hash, $config)))->secret('totp', $stored);
    }

    public function test_whmcs_8s_own_scheme_comes_over(): void
    {
        $this->assertSame(self::SECRET, $this->read(self::stored(json_encode(['secret' => self::SECRET, 'backupcode' => sha1('c8a7')]))));
    }

    public function test_another_installations_key_reads_nothing(): void
    {
        $this->assertNull($this->read(self::stored(json_encode(['secret' => self::SECRET])), 'another-hash'));
    }

    public function test_the_older_scheme_or_garbage_reads_nothing(): void
    {
        $this->assertNull($this->read(base64_encode('old-whmcs-encrypt-output')));
        $this->assertNull($this->read('zz.zz'));
        $this->assertNull($this->read(self::stored('not json')));
        $this->assertNull($this->read(self::stored(json_encode(['secret' => 'not base32!']))));
    }
}
