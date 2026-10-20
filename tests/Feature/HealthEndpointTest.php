<?php

namespace Tests\Feature;

use App\Models\CronStat;
use App\Models\Setting;
use App\Services\Ops\Health;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * GET /health (ROADMAP 5, 2026-10-20): 503 only when the instance does not work, the whole state in one word, the full
 * report only with a token, the checks at most once a minute, never an error page.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'monitor-token-for-tests-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function ran(string $key, string $when): void
    {
        Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => CronStat::class], ['value' => $when]);
    }

    private function working(): void
    {
        $this->ran('last_scheduler_run', now()->toDateTimeString());
        $this->ran('last_cron_run', now()->toDateTimeString());
    }

    public function test_a_critical_check_failing_is_503_in_one_word(): void
    {
        $this->ran('last_scheduler_run', now()->subHour()->toDateTimeString()); // the scheduler stopped

        $response = $this->get('/health')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('fail', $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame([], $response->headers->getCookies(), 'a session for a monitor');
    }

    public function test_working_is_200_and_the_body_still_says_what_needs_staff(): void
    {
        $this->working();
        $status = Health::report()['status'];
        $this->assertNotSame(Health::FAIL, $status, json_encode(Health::report()));
        $response = $this->get('/health')->assertOk();
        $this->assertSame($status, $response->getContent());
        $this->assertSame([], $response->headers->getCookies());

        // A queue stuck for an hour: staff must look (fail), the instance still answers (200, not «down»).
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp()]);
        Cache::flush();
        $this->assertSame('fail', $this->get('/health')->assertOk()->getContent());
        $this->head('/health')->assertOk();
    }

    public function test_the_full_report_only_with_the_token(): void
    {
        config(['services.health.token' => self::TOKEN]);
        $this->working();
        $word = Health::report()['status'];

        $full = json_decode((string) $this->withToken(self::TOKEN)->get('/health')->assertOk()->assertHeader('Content-Type', 'application/json')->getContent(), true);
        $this->assertArrayHasKey('scheduler', $full['checks']);
        $this->assertSame($word, $this->withToken('wrong')->get('/health')->getContent());
        $this->assertSame($word, $this->withToken('')->get('/health?token=' . self::TOKEN)->getContent());

        // A failing instance: the report still says why, with the 503.
        $this->ran('last_scheduler_run', now()->subHour()->toDateTimeString());
        Cache::flush();
        $failing = json_decode((string) $this->withToken(self::TOKEN)->get('/health')->assertStatus(503)->getContent(), true);
        $this->assertSame(Health::FAIL, $failing['checks']['scheduler']['status']);
    }

    public function test_an_unset_or_short_token_never_gives_the_report(): void
    {
        $this->working();
        $word = Health::report()['status'];
        foreach ([null, '', 'short-token'] as $token) {
            config(['services.health.token' => $token]);
            $this->assertSame($word, $this->withToken((string) ($token ?: 'x'))->get('/health')->getContent(), var_export($token, true));
        }
    }

    public function test_many_calls_run_the_checks_once_a_minute(): void
    {
        $this->get('/health');
        DB::enableQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $this->get('/health');
        }
        $checks = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $q) => str_contains($q, 'migrations') || str_contains($q, 'failed_jobs'));
        $this->assertSame([], array_values($checks), 'the checks ran again within the minute');
    }

    public function test_a_cache_that_fails_is_503_fail_not_an_error_page(): void
    {
        Cache::extend('broken', fn () => Cache::repository(new class implements Store
        {
            public function get($key)
            {
                throw new RuntimeException('cache unavailable');
            }

            public function many(array $keys)
            {
                throw new RuntimeException('cache unavailable');
            }

            public function put($key, $value, $seconds)
            {
                throw new RuntimeException('cache unavailable');
            }

            public function putMany(array $values, $seconds)
            {
                throw new RuntimeException('cache unavailable');
            }

            public function increment($key, $value = 1)
            {
                throw new RuntimeException('cache unavailable');
            }

            public function decrement($key, $value = 1)
            {
                throw new RuntimeException('cache unavailable');
            }

            public function forever($key, $value)
            {
                throw new RuntimeException('cache unavailable');
            }

            public function forget($key)
            {
                throw new RuntimeException('cache unavailable');
            }

            public function flush()
            {
                throw new RuntimeException('cache unavailable');
            }

            public function getPrefix()
            {
                return '';
            }

            public function touch($key, $seconds)
            {
                throw new RuntimeException('cache unavailable');
            }
        }));
        config(['cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'broken']]);
        Cache::forgetDriver('broken');

        $this->assertSame('fail', $this->get('/health')->assertStatus(503)->getContent());
    }
}
