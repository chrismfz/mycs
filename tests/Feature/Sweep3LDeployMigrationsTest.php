<?php

namespace Tests\Feature;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sweep 3, lens l-deploy-migrations (adversarial verifier). Each test fails on main for the reason it names.
 */
class Sweep3LDeployMigrationsTest extends TestCase
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

    /**
     * L-DEPLOY-MIGRATIONS-1: production runs CACHE_STORE=file (docs/HANDOFF.md), whose lock_path is the cache path.
     * Saving a currency calls Cache::flush(), which deletes the lock files too: a held domain-registrar lock is gone.
     */
    public function test_saving_a_currency_does_not_release_a_held_cache_lock(): void
    {
        $this->dir = sys_get_temp_dir() . '/sweep3-ldm-cache-' . uniqid();
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->dir,
            'cache.stores.file.lock_path' => $this->dir,
        ]);
        Cache::forgetDriver('file');

        $held = Cache::lock('domain-registrar:1', 600);
        $this->assertTrue($held->get());
        $this->assertFalse(Cache::lock('domain-registrar:1', 600)->get(), 'the file lock holds before the save');

        $currency = Currency::query()->first() ?? Currency::create(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '', 'suffix' => ' €', 'format' => '1.000,00']);
        $currency->prefix = ($currency->prefix ?? '') . 'x';
        $currency->save();

        // A second process asking for the same lock must be refused while the first still holds it.
        $this->assertFalse(Cache::lock('domain-registrar:1', 600)->get(), 'Currency::saved → Cache::flush() released a held lock');
        $held->release();
    }

    /**
     * L-DEPLOY-MIGRATIONS-2: on the queue:restart path step 10 runs `up` before the second queue:restart, so a worker
     * that its supervisor started during maintenance (old release) can reserve a job in between.
     */
    public function test_step_10_signals_the_workers_before_the_app_goes_up(): void
    {
        $this->dir = sys_get_temp_dir() . '/sweep3-ldm-up-' . uniqid();
        File::makeDirectory($this->dir, 0700, true);
        $script = File::get(base_path('deploy/update.sh'));

        $fStart = strpos($script, 'start_queue_worker() {');
        $fEnd = strpos($script, '# --- 1. pre-flight');
        $sStart = strpos($script, '# --- 10. back up');
        $sEnd = strpos($script, 'touch storage/framework/console-relay.restart');
        foreach ([$fStart, $fEnd, $sStart, $sEnd] as $p) {
            $this->assertNotFalse($p);
        }

        $log = $this->dir . '/calls.log';
        File::put($this->dir . '/fake-artisan', "#!/bin/sh\necho \"\$*\" >> " . escapeshellarg($log) . "\nexit 0\n");
        chmod($this->dir . '/fake-artisan', 0700);
        $harness = "set -Eeuo pipefail\nlog() { :; }; warn() { :; }; ok() { :; }; fail() { :; }\n"
            . 'ART=' . escapeshellarg($this->dir . '/fake-artisan') . "\n_stopped_by=restart\n_stay_down=0\n_had_workers=1\n_LEFT_DOWN=deploy-left-down\n"
            . substr($script, $fStart, $fEnd - $fStart) . "\n"
            . substr($script, $sStart, $sEnd - $sStart) . "\n";
        File::put($this->dir . '/harness.sh', $harness);
        exec('bash ' . escapeshellarg($this->dir . '/harness.sh') . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        $calls = array_values(array_filter(array_map('trim', file($log))));
        $this->assertContains('up', $calls);
        $this->assertContains('queue:restart', $calls);
        $this->assertLessThan(array_search('up', $calls, true), array_search('queue:restart', $calls, true),
            'queue:restart must come before `up`, calls were: ' . implode(', ', $calls));
    }

    /**
     * L-DEPLOY-MIGRATIONS-3: nothing stops two deploys running at once (no flock, migrate without --isolated).
     */
    public function test_update_sh_holds_a_lock_against_a_second_run(): void
    {
        $script = File::get(base_path('deploy/update.sh'));

        $this->assertMatchesRegularExpression('/\bflock\b/', $script, 'update.sh takes no lock against a concurrent deploy');
        $this->assertMatchesRegularExpression('/migrate --force[^\n]*--isolated/', $script, 'migrate runs without --isolated');
    }

    /**
     * L-DEPLOY-MIGRATIONS-4: the manual rollback never tells the queue workers to exit, so a worker paused in
     * maintenance resumes after `up` with the newer release's code over the restored older schema (--max-time=3600).
     */
    public function test_manual_rollback_restarts_the_queue_workers(): void
    {
        $doc = File::get(base_path('docs/install.md'));
        $start = strpos($doc, '## Αν κάτι πάει στραβά');
        $this->assertNotFalse($start);
        $section = substr($doc, $start);
        $block = preg_match('/```bash\n(.*?)```/s', $section, $m) ? $m[1] : '';
        $this->assertStringContainsString('artisan down', $block);

        $this->assertStringContainsString('queue:restart', $block, 'the rollback block never restarts the queue workers');
    }
}
