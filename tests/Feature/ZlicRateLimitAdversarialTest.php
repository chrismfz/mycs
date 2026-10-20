<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Http\Controllers\ZlicController;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Licensing\ZlicLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of #254 (zlic.php rate limit). Findings A1…A5 fail on the PR as it stands; the «holds_» tests are
 * attacks that the PR stops. Documentation addresses only (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32).
 */
class ZlicRateLimitAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const REMOTE_CHECK_FAILED = ['status' => 'Invalid', 'description' => 'Remote Check Failed'];

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Request::setTrustedProxies([], 0); // static on Symfony's Request: no leftovers from another test
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        ExtensionHelper::createServer($service);
        $this->key = License::query()->sole()->license_key;
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    private function zlic(string $ip, array $params, array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers)->post('/zlic.php', $params);
    }

    /** @return array<string, mixed> */
    private function check(string $key, string $ip, string $psk = 'TestSecret', array $headers = []): array
    {
        return (array) json_decode((string) $this->zlic($ip, ['license' => $key, 'license_PSK' => $psk], $headers)->getContent(), true);
    }

    private function junk(int $i): string
    {
        return 'ZCAgent-' . str_pad(dechex($i), 16, '0', STR_PAD_LEFT);
    }

    /** Burns the failure budget of an address with unknown keys. */
    private function burn(string $ip, int $count = ZlicController::FAILURES_PER_HOUR): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->check($this->junk($i), $ip);
        }
    }

    private function isLimited(array $answer): bool
    {
        return ($answer['description'] ?? null) === 'Remote Check Failed';
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings (failing)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * A1 (Medium): an IPv6 /64 is one caller, counted as 2^64. The counters are keyed by the full address, so a key
     * search from a single host with an ordinary /64 (every VPS, every home line) rotates the interface id and is never
     * limited. #251 counts the contact form per /64 for exactly this reason.
     */
    public function test_a1_an_ipv6_64_is_one_address_for_the_failure_limit(): void
    {
        for ($i = 1; $i <= ZlicController::FAILURES_PER_HOUR; $i++) {
            $this->assertSame('Invalid', $this->check($this->junk($i), '2001:db8:0:1::' . dechex($i))['status']);
        }

        $answer = $this->check($this->junk(999), '2001:db8:0:1::ffff');
        $this->assertTrue($this->isLimited($answer), 'the 31st wrong answer from the same /64 asked the licenses: ' . json_encode($answer));
    }

    /**
     * A2 (Medium, revenue): «Remote Check Failed» is a soft failure on demand. A customer whose license is suspended (not
     * paid) or answered «IP Address Invalid» (a copy on another machine) sends 30 unknown keys from the same address, and
     * the client's next real check gets «Remote Check Failed», which clients treat as «keep the local key» instead of the
     * signed «Suspended». Repeating it every hour keeps the software running for the whole local-key grace period.
     * Before #254 that answer only came from a real outage (or maintenance).
     */
    public function test_a2_a_suspended_client_cannot_turn_its_suspension_into_a_soft_failure(): void
    {
        $this->assertSame('Active', $this->check($this->key, '198.51.100.7')['status']); // binds it
        License::query()->update(['status' => License::STATUS_SUSPENDED]);
        $this->assertSame('Suspended', $this->check($this->key, '198.51.100.7')['status']);

        $this->burn('198.51.100.7'); // its own operator, the same address

        $answer = $this->check($this->key, '198.51.100.7');
        $this->assertSame('Suspended', $answer['status'] ?? null, 'a suspended license was answered as an outage: ' . json_encode($answer));
    }

    /**
     * A3 (Medium, availability): one address is many customers. A shared host, a NAT or a CGNAT where someone else (an
     * attacker, or just a sibling license that is broken: moved, wrong PSK, crash-looping) sends 30 wrong answers an
     * hour turns a healthy, bound license's checks into «Remote Check Failed» for the rest of the hour, re-armed every
     * hour. The docblock's promise «a client that works is never held back» holds only against its own checks.
     */
    public function test_a3_a_neighbour_on_the_same_address_does_not_lock_out_a_bound_license(): void
    {
        $this->assertSame('Active', $this->check($this->key, '198.51.100.7')['status']); // binds it

        $this->burn('198.51.100.7'); // someone else behind the same address

        $answer = $this->check($this->key, '198.51.100.7');
        $this->assertSame('Active', $answer['status'] ?? null, 'a working, bound license was locked out by its neighbour: ' . json_encode($answer));
    }

    /**
     * A4 (Medium, contract): the limiter runs outside the try. When the cache cannot be read or written (a full disk or
     * wrong permissions on the file store, Redis down) every check is an HTML 500 instead of zlic.php's JSON; before
     * #254 licensing did not depend on the cache at all, and the controller's own rule is «what zlic.php said when it
     * could not check». The hit after the answer is outside the try too: a bind already written is then answered 500.
     */
    public function test_a4_a_cache_failure_is_still_a_json_answer(): void
    {
        // Author (#254 fixes): the counters moved from the cache (RateLimiter) to their own table (ZlicLimiter, review F2);
        // the same failure, at its new place.
        $this->mock(ZlicLimiter::class, function ($mock) {
            $mock->shouldReceive('marked', 'hit', 'giveBack', 'mark')->andThrow(new RuntimeException('cache unavailable'));
        });

        $response = $this->zlic('198.51.100.7', ['license' => $this->key, 'license_PSK' => 'TestSecret']);

        $this->assertSame(200, $response->getStatusCode(), 'a cache failure took zlic.php down');
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertIsArray(json_decode((string) $response->getContent(), true));
    }

    /**
     * A5 (Low/Medium): check-then-act. The failure is counted only after the licenses were asked, so every request in
     * flight passes the check: a search that sends its guesses in parallel gets as many answers as it has connections
     * (and up to the minute's 120 before the first one lands, each minute that the hour counter has not yet caught up),
     * not 30 an hour. Simulated here by starting each next request while the previous one is reading the licenses.
     */
    public function test_a5_parallel_wrong_answers_do_not_get_past_the_hourly_limit(): void
    {
        $inFlight = ZlicController::FAILURES_PER_HOUR + 10;
        $started = 0;
        $answers = [];
        DB::listen(function ($query) use (&$started, &$answers, $inFlight) {
            if ($started < $inFlight && str_contains($query->sql, 'licenses') && str_contains($query->sql, 'license_key')) {
                $n = ++$started;
                $answers[] = $this->check($this->junk(1000 + $n), '203.0.113.9');
            }
        });

        $answers[] = $this->check($this->junk(0), '203.0.113.9');

        $asked = count(array_filter($answers, fn (array $a) => !$this->isLimited($a)));
        $this->assertLessThanOrEqual(ZlicController::FAILURES_PER_HOUR, $asked, "{$asked} wrong answers from one address in one hour");
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Holds (passing)
    // ---------------------------------------------------------------------------------------------------------------

    /** Without trusted proxies a rotating X-Forwarded-For / X-Real-IP / Forwarded is ignored: one address, one counter. */
    public function test_holds_forwarded_headers_do_not_rotate_the_address(): void
    {
        for ($i = 0; $i < ZlicController::FAILURES_PER_HOUR; $i++) {
            $this->check($this->junk($i), '203.0.113.9', 'TestSecret', [
                'X-Forwarded-For' => "198.51.100.{$i}", 'X-Real-IP' => "198.51.100.{$i}", 'Forwarded' => "for=198.51.100.{$i}", 'Client-IP' => "198.51.100.{$i}",
            ]);
        }

        $this->assertTrue($this->isLimited($this->check($this->key, '203.0.113.9', 'TestSecret', ['X-Forwarded-For' => '198.51.100.200'])));
    }

    /** Behind a listed proxy, values the caller prepends to X-Forwarded-For are not the address: the proxy's last hop is. */
    public function test_holds_a_listed_proxy_counts_the_hop_it_saw_not_what_the_caller_prepended(): void
    {
        config(['settings.trusted_proxies' => ['192.0.2.10']]);
        for ($i = 0; $i < ZlicController::FAILURES_PER_HOUR; $i++) {
            $this->check($this->junk($i), '192.0.2.10', 'TestSecret', ['X-Forwarded-For' => "198.51.100.{$i}, 203.0.113.9"]);
        }

        $this->assertTrue($this->isLimited($this->check($this->key, '192.0.2.10', 'TestSecret', ['X-Forwarded-For' => '198.51.100.250, 203.0.113.9'])));
        // Another client behind the same proxy is its own address.
        $this->assertSame('Active', $this->check($this->key, '192.0.2.10', 'TestSecret', ['X-Forwarded-For' => '203.0.113.77'])['status']);
    }

    /** GET and POST, array parameters and missing ones all count on the same address. */
    public function test_holds_get_post_and_parameter_tricks_share_one_counter(): void
    {
        for ($i = 0; $i < ZlicController::FAILURES_PER_HOUR; $i++) {
            match ($i % 4) {
                0 => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/zlic.php?license=' . $this->junk($i) . '&license_PSK=TestSecret'),
                1 => $this->zlic('203.0.113.9', ['license' => [$this->key], 'license_PSK' => 'TestSecret']),
                2 => $this->zlic('203.0.113.9', ['license' => $this->key]),
                default => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/zlic.php?license=' . $this->junk($i) . '&license_PSK=TestSecret&_=' . $i),
            };
        }

        $answer = json_decode((string) $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/zlic.php?license=' . $this->key . '&license_PSK=TestSecret')->getContent(), true);
        $this->assertTrue($this->isLimited($answer));
    }

    /** The limited answer is exactly the outage answer: 200, JSON, same body, no cookie and no limiter headers. */
    public function test_holds_the_limited_answer_is_the_outage_answer_byte_for_byte(): void
    {
        $this->burn('203.0.113.9');

        $response = $this->zlic('203.0.113.9', ['license' => $this->key, 'license_PSK' => 'TestSecret']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(json_encode(self::REMOTE_CHECK_FAILED), $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Retry-After'));
        $this->assertNull($response->headers->get('X-RateLimit-Limit'));
    }

    /** A limited check writes nothing: no bind, no access log, no daily count, no failure, no last access. */
    public function test_holds_the_limited_path_writes_nothing(): void
    {
        $this->burn('203.0.113.9');
        $logs = DB::table('license_access_logs')->count();
        $before = License::query()->sole()->getAttributes();

        $this->assertTrue($this->isLimited($this->check($this->key, '203.0.113.9')));
        $this->assertTrue($this->isLimited($this->check($this->key, '203.0.113.9', 'Wrong')));

        $this->assertSame($logs, DB::table('license_access_logs')->count());
        $this->assertSame(0, DB::table('license_check_days')->count());
        $this->assertSame($before, License::query()->sole()->getAttributes(), 'the limited check changed the license');
    }

    /**
     * Counters are per exact address (203.0.113.9 is not 203.0.113.90) and expire: an hour, a minute.
     * Author (#254 fixes): the minute's limit is gone (a caller not yet known meets the hour's two first; a known one is
     * never limited, A2/A3); its part here now holds that a bound license checking far past any client's rate is answered.
     */
    public function test_holds_counters_are_per_address_and_expire(): void
    {
        $this->burn('203.0.113.9');
        $this->assertTrue($this->isLimited($this->check($this->key, '203.0.113.9')));
        $this->assertSame('Active', $this->check($this->key, '203.0.113.90')['status']); // binds to .90

        $this->travel(3601)->seconds();
        $this->assertSame('IP Address Invalid', $this->check($this->key, '203.0.113.9')['message'] ?? null, 'the hour never ended');

        for ($i = 0; $i < 150; $i++) {
            $this->check($this->key, '203.0.113.90');
        }
        $this->assertSame('Active', $this->check($this->key, '203.0.113.90')['status'], 'a known caller was limited');
    }
}
