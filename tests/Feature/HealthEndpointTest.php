<?php

namespace Tests\Feature;

use App\Models\CronStat;
use App\Models\Setting;
use App\Services\Ops\Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * GET /health (ROADMAP 5, 2026-10-20): ok / warn / fail for a monitor, the full report only with the token, the checks
 * at most once a minute, never an error page.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function ran(string $key, string $when): void
    {
        Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => CronStat::class], ['value' => $when]);
    }

    public function test_a_failing_check_is_503_and_said_in_one_word(): void
    {
        $this->ran('last_scheduler_run', now()->subHour()->toDateTimeString()); // the scheduler stopped

        $response = $this->get('/health')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('fail', $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame([], $response->headers->getCookies(), 'a session for a monitor');
    }

    public function test_ok_or_warn_is_200(): void
    {
        $this->ran('last_scheduler_run', now()->toDateTimeString());
        $this->ran('last_cron_run', now()->toDateTimeString());
        $status = Health::report()['status'];
        $this->assertNotSame(Health::FAIL, $status, json_encode(Health::report()));

        $this->assertSame($status, $this->get('/health')->assertOk()->getContent());
    }

    public function test_the_full_report_only_with_the_token(): void
    {
        config(['services.health.token' => 'monitor-token-for-tests']);
        $this->ran('last_scheduler_run', now()->toDateTimeString());

        $this->assertSame('{', substr((string) $this->withToken('monitor-token-for-tests')->get('/health')->getContent(), 0, 1));
        $this->assertArrayHasKey('scheduler', json_decode((string) $this->withToken('monitor-token-for-tests')->get('/health')->getContent(), true)['checks']);
        $this->assertStringNotContainsString('checks', (string) $this->withToken('wrong')->get('/health')->getContent());
        $this->assertStringNotContainsString('checks', (string) $this->get('/health?token=monitor-token-for-tests')->getContent());

        config(['services.health.token' => '']); // unset: never the report, an empty token included
        $this->assertStringNotContainsString('checks', (string) $this->withHeaders(['Authorization' => 'Bearer '])->get('/health')->getContent());
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

    public function test_a_report_that_cannot_be_read_is_a_failure_not_an_error_page(): void
    {
        Cache::shouldReceive('get')->andThrow(new RuntimeException('cache unavailable'));

        $this->assertSame('fail', $this->get('/health')->assertStatus(503)->getContent());
    }
}
