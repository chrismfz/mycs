<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\LocalKey;
use Nixpal\Licensing\Tests\Oracle\ZlicReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalKeyTest extends TestCase
{
    const SECRET = 'TestProductSecret';

    const CHECKDATE = '20261008';

    /** Active verify.php tags from tests/fixtures/zlic, with a concrete md5hash. */
    private static function activeFixture()
    {
        $path = __DIR__ . '/fixtures/zlic/verify_responses.json';
        $tags = json_decode(file_get_contents($path), true)['active_devnode'];
        $tags['md5hash'] = md5(self::SECRET . '1760000000abcdef');

        return $tags;
    }

    public static function provideResults()
    {
        $active = self::activeFixture();

        $unicode = $active;
        $unicode['registeredname'] = 'Δοκιμαστικός Πελάτης';
        $unicode['companyname'] = 'Test "Quoted" & Co / Ltd';

        $long = $active;
        $long['validdomain'] = implode(',', array_fill(0, 40, 'very-long-hostname.example.com'));

        return [
            'fixture active_devnode' => [$active],
            'unicode, quotes and slashes' => [$unicode],
            'long payload (many wrapped lines)' => [$long],
            'minimal' => [['status' => 'Active']],
        ];
    }

    #[DataProvider('provideResults')]
    public function test_attach_is_byte_identical_to_zlic(array $results)
    {
        $expected = ZlicReference::activeResults($results, self::SECRET, self::CHECKDATE);
        unset($expected['remotecheck']);

        $actual = LocalKey::attach($results, self::SECRET, self::CHECKDATE);

        $this->assertSame($expected, $actual);
        $this->assertSame(json_encode($expected), json_encode($actual));
    }

    #[DataProvider('provideResults')]
    public function test_decode_round_trip_matches_zlic_decoder(array $results)
    {
        $key = LocalKey::attach($results, self::SECRET, self::CHECKDATE)['localkey'];

        $payload = LocalKey::decode($key, self::SECRET);

        $this->assertNotNull($payload);
        $this->assertSame(ZlicReference::decode($key, self::SECRET), $payload);
        $this->assertSame(self::CHECKDATE, $payload['checkdate']);
        $this->assertSame($results['status'], $payload['status']);
        $this->assertArrayNotHasKey('localkey', $payload);
    }

    public function test_key_is_wrapped_at80_columns()
    {
        $key = LocalKey::attach(self::activeFixture(), self::SECRET, self::CHECKDATE)['localkey'];
        $lines = explode("\n", $key);

        $this->assertGreaterThan(1, count($lines));
        foreach (array_slice($lines, 0, -1) as $line) {
            $this->assertSame(LocalKey::LINE_WIDTH, strlen($line));
        }
        $this->assertLessThanOrEqual(LocalKey::LINE_WIDTH, strlen(end($lines)));
    }

    public function test_decode_accepts_unwrapped_and_crlf_keys()
    {
        $key = LocalKey::attach(self::activeFixture(), self::SECRET, self::CHECKDATE)['localkey'];

        $this->assertNotNull(LocalKey::decode(str_replace("\n", '', $key), self::SECRET));
        $this->assertNotNull(LocalKey::decode(str_replace("\n", "\r\n", $key), self::SECRET));
    }

    public function test_decode_rejects_wrong_secret()
    {
        $key = LocalKey::attach(self::activeFixture(), self::SECRET, self::CHECKDATE)['localkey'];

        $this->assertNull(LocalKey::decode($key, 'OtherProductSecret'));
    }

    public function test_decode_rejects_tampered_payload()
    {
        $key = str_replace("\n", '', LocalKey::attach(self::activeFixture(), self::SECRET, self::CHECKDATE)['localkey']);
        $i = intdiv(strlen($key), 2);
        $tampered = substr_replace($key, $key[$i] === 'A' ? 'B' : 'A', $i, 1);

        $this->assertNull(LocalKey::decode($tampered, self::SECRET));
    }

    public function test_decode_rejects_forged_checkdate()
    {
        // Re-sign the outer hash correctly but keep the date hash of another day:
        // only someone holding the secret can move checkdate forward.
        $payload = self::activeFixture() + ['checkdate' => '20991231'];
        $body = strrev(md5(self::CHECKDATE . self::SECRET) . base64_encode(json_encode($payload)));
        $forged = $body . md5($body . self::SECRET);

        $this->assertNull(LocalKey::decode($forged, self::SECRET));
    }

    public static function provideGarbage()
    {
        return [[''], ['short'], [str_repeat('a', 64)], [str_repeat('a', 200)]];
    }

    #[DataProvider('provideGarbage')]
    public function test_decode_rejects_garbage($key)
    {
        $this->assertNull(LocalKey::decode($key, self::SECRET));
    }
}
