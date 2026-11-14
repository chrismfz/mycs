<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\Token;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    /** @return array{kid: string, secret: string, public: string, claims: array<string, mixed>, token: string} */
    private static function fixture()
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/token/v1.json'), true);
    }

    public function test_the_fixture_is_what_issue_makes_and_verify_reads(): void
    {
        $f = self::fixture();
        $claims = array_diff_key($f['claims'], ['v' => 1, 'kid' => 1]);

        // ed25519 is deterministic: the clients' tests hold the same token (zonecloud internal/license).
        $this->assertSame($f['token'], Token::issue($claims, $f['secret'], $f['kid']));
        $this->assertSame($f['public'], Token::publicKey($f['secret']));
        $this->assertSame($f['claims'], Token::verify($f['token'], [$f['kid'] => $f['public']], $f['claims']['iat']));
        $this->assertSame($f['claims']['exp'], Token::expiry($f['claims']['iat'], $f['claims']['due'], 30), 'the fixture keeps the expiry rule');
    }

    public function test_a_changed_wrongly_signed_expired_or_unknown_token_is_refused(): void
    {
        $f = self::fixture();
        $keys = [$f['kid'] => $f['public']];
        $now = $f['claims']['iat'];
        [$kid, $payload, $signature] = explode('.', $f['token']);
        $other = Token::keypair();

        $forged = rtrim(strtr(base64_encode(str_replace('2027-03-31', '2099-01-31', (string) base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=');
        $this->assertNull(Token::verify("{$kid}.{$forged}.{$signature}", $keys, $now), 'a changed payload');
        $this->assertNull(Token::verify(Token::issue(['exp' => $now + 60], $other['secret'], $f['kid']), $keys, $now), 'another key');
        $this->assertNull(Token::verify($f['token'], ['other' => $f['public']], $now), 'an unknown kid');
        $this->assertNull(Token::verify($f['token'], $keys, $f['claims']['exp'] + 1), 'expired');
        $this->assertNull(Token::verify("{$kid}.{$payload}", $keys, $now));
        $this->assertNull(Token::verify("{$kid}.{$payload}.!!", $keys, $now));
        $this->assertNull(Token::verify('', $keys, $now));
        // Its kid inside must be the one it is checked with.
        $this->assertNull(Token::verify('test-2.' . $payload . '.' . $signature, ['test-2' => $f['public']], $now));
    }

    public function test_a_new_pair_signs_and_verifies(): void
    {
        $pair = Token::keypair();
        $token = Token::issue(['key' => 'K', 'exp' => 2_000_000_000], $pair['secret'], 'k1');

        $this->assertSame('K', Token::verify($token, ['k1' => $pair['public']], 1_900_000_000)['key']);
        $this->assertNull(Token::publicKey('not a key'));
    }

    public function test_a_bad_key_or_kid_does_not_issue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Token::issue([], base64_encode('short'), 'k1');
    }

    public function test_a_kid_with_a_dot_does_not_issue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Token::issue([], Token::keypair()['secret'], 'k.1');
    }

    public function test_the_expiry_is_the_due_date_or_the_maximum_whichever_first(): void
    {
        $now = gmmktime(12, 0, 0, 11, 15, 2026);

        $this->assertSame($now + 30 * 86400, Token::expiry($now, '2027-11-15', 30), 'a yearly license: 30 days, renewed at each check');
        $this->assertSame(gmmktime(23, 59, 59, 11, 20, 2026), Token::expiry($now, '2026-11-20', 30), 'due in 5 days: its end');
        $this->assertSame(gmmktime(23, 59, 59, 11, 10, 2026), Token::expiry($now, '2026-11-10', 30), 'overdue: already past');
        foreach ([null, '0000-00-00', '2026-02-30', 'tomorrow'] as $none) {
            $this->assertSame($now + 30 * 86400, Token::expiry($now, $none, 30), var_export($none, true));
        }
    }
}
