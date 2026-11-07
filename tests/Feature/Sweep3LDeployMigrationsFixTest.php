<?php

namespace Tests\Feature;

use App\Models\Builders\CacheableBuilder;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The fixes of sweep 3, lens l-deploy-migrations: what the fix changed beyond the verifier's tests still does its job.
 */
class Sweep3LDeployMigrationsFixTest extends TestCase
{
    use RefreshDatabase;

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function fileCache(): void
    {
        $this->dir = sys_get_temp_dir() . '/sweep3-ldm-fix-' . uniqid();
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->dir,
            'cache.stores.file.lock_path' => $this->dir,
        ]);
        Cache::forgetDriver('file');
    }

    /** L-DEPLOY-MIGRATIONS-1: the cached currency queries still see a change, on create, save and delete. */
    public function test_a_saved_or_deleted_currency_is_seen_by_the_cached_queries(): void
    {
        $this->fileCache();
        Currency::query()->where('code', 'ZZZ')->delete();
        $this->assertNull(Currency::query()->where('code', 'ZZZ')->first(), 'cached: none yet');

        Currency::create(['code' => 'ZZZ', 'name' => 'Test', 'prefix' => 'a', 'suffix' => '', 'format' => '1.000,00']);
        $this->assertSame('a', Currency::query()->where('code', 'ZZZ')->first()?->prefix, 'created: the cached «none» is gone');

        $currency = Currency::query()->where('code', 'ZZZ')->first();
        $currency->prefix = 'b';
        $currency->save();
        $this->assertSame('b', Currency::query()->where('code', 'ZZZ')->first()?->prefix, 'saved: the cached row is gone');
        $this->assertContains('ZZZ', Currency::query()->pluck('code')->all());

        $currency->delete();
        $this->assertNull(Currency::query()->where('code', 'ZZZ')->first(), 'deleted: the cached row is gone');
        $this->assertNotContains('ZZZ', Currency::query()->pluck('code')->all());
    }

    /** L-DEPLOY-MIGRATIONS-1: the query cache's flush leaves the rest of the cache (settings, marks, rate limits) alone. */
    public function test_the_query_cache_flush_keeps_other_cache_entries(): void
    {
        $this->fileCache();
        Cache::put('some-other-entry', 'kept', 600);

        CacheableBuilder::flushQueryCache();

        $this->assertSame('kept', Cache::get('some-other-entry'));
    }

    /** L-DEPLOY-MIGRATIONS-3: a second update.sh, run while one holds the lock, stops before it changes anything. */
    public function test_a_second_deploy_is_refused_while_one_holds_the_lock(): void
    {
        $this->dir = sys_get_temp_dir() . '/sweep3-ldm-lock-' . uniqid();
        File::makeDirectory($this->dir . '/storage/framework', 0700, true);
        File::put($this->dir . '/artisan', "<?php\n");
        File::put($this->dir . '/.env', "APP_ENV=testing\n");
        $handle = fopen($this->dir . '/storage/framework/deploy.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            exec('APP_DIR=' . escapeshellarg($this->dir) . ' PHP=/bin/false bash ' . escapeshellarg(base_path('deploy/update.sh')) . ' 2>&1', $out, $rc);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(1, $rc, implode("\n", $out));
        $this->assertStringContainsString('Another deploy is running', implode("\n", $out));
    }

    /** L-DEPLOY-MIGRATIONS-3: a worker that QUEUE_START_CMD leaves running never inherits the deploy's lock. */
    public function test_the_start_hook_runs_without_the_lock_descriptor(): void
    {
        $this->dir = sys_get_temp_dir() . '/sweep3-ldm-hook-' . uniqid();
        File::makeDirectory($this->dir, 0700, true);
        $script = File::get(base_path('deploy/update.sh'));
        $fStart = strpos($script, 'start_queue_worker() {');
        $fEnd = strpos($script, '# --- 1. pre-flight');
        $this->assertNotFalse($fStart);
        $this->assertNotFalse($fEnd);

        $out = $this->dir . '/out';
        $harness = "set -Eeuo pipefail\nlog() { :; }; warn() { :; }; ok() { :; }; fail() { :; }\n"
            . 'exec 9>>' . escapeshellarg($this->dir . '/deploy.lock') . "\n_stopped_by=hook\n"
            . 'QUEUE_START_CMD=' . escapeshellarg("sh -c 'test -e /proc/self/fd/9 && echo open || echo closed' > " . escapeshellarg($out)) . "\n"
            . substr($script, $fStart, $fEnd - $fStart) . "\nstart_queue_worker\n";
        File::put($this->dir . '/harness.sh', $harness);
        exec('bash ' . escapeshellarg($this->dir . '/harness.sh') . ' 2>&1', $lines, $rc);

        $this->assertSame(0, $rc, implode("\n", $lines));
        $this->assertSame('closed', trim((string) File::get($out)));
    }
}
