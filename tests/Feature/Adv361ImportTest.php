<?php

namespace Tests\Feature;

use App\Services\Import\WhmcsSecondFactor;
use Tests\TestCase;

/**
 * Adversarial review of #361: the shape WHMCS's own `totp` module keeps a login's authenticator in.
 *
 * modules/security/totp keeps its settings as ['tokendata' => …], where tokendata is the ga4php library's record:
 * base64_encode(serialize([... 'tokenkey' => <the key in HEX>, 'tokentype' => 'TOTP', ...])) — not a base32 `secret`.
 * The user's `second_factor_config` is that settings array, encrypted (JSON or serialized). DecryptPassword gives the
 * settings back; the base32 secret the authenticator app holds is base32(hex2bin(tokenkey)).
 */
class Adv361ImportTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DP';

    private static function tokendata(): string
    {
        // ga4php's helperb322hex(): the base32 key as hex.
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(self::SECRET) as $c) {
            $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $hex = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $hex .= str_pad(dechex(bindec($byte)), 2, '0', STR_PAD_LEFT);
        }

        return base64_encode(serialize([
            'tokenkey' => $hex, 'tokentype' => 'TOTP', 'tokentimer' => 30, 'tokencounter' => 1,
            'tokenalgorithm' => 'SHA1', 'user' => '',
        ]));
    }

    private function read(string $plain): ?string
    {
        return (new WhmcsSecondFactor(fn () => $plain))->secret('totp', 'encrypted-by-whmcs');
    }

    public function test_whmcs_totp_tokendata_as_json_comes_over(): void
    {
        $this->assertSame(self::SECRET, $this->read(json_encode(['tokendata' => self::tokendata()])),
            'WHMCS totp keeps the key in tokendata (ga4php, hex): every real 2FA would be listed as missed');
    }

    public function test_whmcs_totp_tokendata_serialized_comes_over(): void
    {
        $this->assertSame(self::SECRET, $this->read(serialize(['tokendata' => self::tokendata()])));
    }
}
