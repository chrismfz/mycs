<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * deploy/update.sh refuses dev packages and debug output on production (security audit SEC-13), before it touches
 * anything: run in an empty checkout that only has its .env, it stops at once. Past the guard, a fake git stops it.
 */
class DeployProductionGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/mycs-deploy-guard-' . uniqid();
        File::makeDirectory($this->dir . '/deploy', 0700, true);
        File::copy(base_path('deploy/update.sh'), $this->dir . '/deploy/update.sh');
        File::put($this->dir . '/artisan', '');
        // The parser the guard asks (phpdotenv): the app's own vendor.
        symlink(base_path('vendor'), $this->dir . '/vendor');
        // Past the guard the script's first step is git: a git that only says so stops it there.
        File::makeDirectory($this->dir . '/bin');
        File::put($this->dir . '/bin/git', "#!/bin/sh\necho PAST-THE-GUARD\nexit 97\n");
        chmod($this->dir . '/bin/git', 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return array{int, string} */
    private function deploy(string $env, array $vars = []): array
    {
        File::put($this->dir . '/.env', $env);
        $prefix = implode(' ', array_map(fn ($k, $v) => $k . '=' . escapeshellarg($v), array_keys($vars), $vars));
        exec('cd ' . escapeshellarg($this->dir) . ' && PATH=' . escapeshellarg($this->dir . '/bin:' . getenv('PATH')) . ' env -u COMPOSER_DEV -u ALLOW_DEV_IN_PRODUCTION -u APP_DIR -u MYCS_UPDATE_COPY ' . $prefix . ' bash deploy/update.sh --help-not-a-ref 2>&1 < /dev/null', $out, $code);

        return [$code, implode("\n", $out)];
    }

    public function test_dev_packages_on_production_are_refused(): void
    {
        [$code, $out] = $this->deploy("APP_ENV=production\nAPP_DEBUG=false\nDEPLOY_COMPOSER_DEV=1\n");

        $this->assertSame(1, $code);
        $this->assertStringContainsString('dev packages would be installed', $out);
    }

    public function test_debug_on_production_is_refused(): void
    {
        [$code, $out] = $this->deploy("APP_ENV=production\nAPP_DEBUG=\"true\"\n");

        $this->assertSame(1, $code);
        $this->assertStringContainsString('APP_DEBUG=true', $out);
    }

    /** As Laravel reads .env, not as grep does (review of #392). */
    public function test_debug_is_refused_however_the_env_writes_it(): void
    {
        foreach ([
            "APP_ENV='production'\nAPP_DEBUG='true'\n",
            "APP_ENV=production # prod\nAPP_DEBUG=true # x\n",
            "APP_ENV=production \r\nAPP_DEBUG=true\r\n",
            "export APP_ENV=production\nexport APP_DEBUG=yes\n",
            "APP_ENV=\"Production\"\nAPP_DEBUG=(true)\n",
        ] as $env) {
            [$code, $out] = $this->deploy($env);
            $this->assertSame(1, $code, json_encode($env) . ': ' . $out);
            $this->assertStringContainsString('APP_DEBUG=true', $out);
        }
    }

    public function test_a_correct_production_env_passes_the_guard(): void
    {
        foreach (["APP_ENV=production\nAPP_DEBUG=false\n", "APP_ENV=production\n", "APP_ENV=production\nAPP_DEBUG=0 # off\n"] as $env) {
            [$code, $out] = $this->deploy($env);
            $this->assertSame(97, $code, json_encode($env) . ': ' . $out);
            $this->assertStringContainsString('PAST-THE-GUARD', $out);
        }
    }

    /** PHP's warnings on stdout do not move the values; a PHP that cannot read .env is refused (review of #392). */
    public function test_php_noise_or_failure_never_lets_debug_through(): void
    {
        File::put($this->dir . '/bin/noisy-php', "#!/bin/sh\necho 'Warning: PHP Startup: something'\nexec " . PHP_BINARY . " \"\$@\"\n");
        chmod($this->dir . '/bin/noisy-php', 0700);
        [$code, $out] = $this->deploy("APP_ENV=production\nAPP_DEBUG=true\n", ['PHP' => $this->dir . '/bin/noisy-php']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('APP_DEBUG=true', $out);

        [$code, $out] = $this->deploy("APP_ENV=production\nAPP_DEBUG=false\n", ['PHP' => 'false']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Cannot read .env', $out);
    }

    public function test_a_space_inside_quotes_is_debug_on_as_for_laravel(): void
    {
        [$code, $out] = $this->deploy("APP_ENV=production\nAPP_DEBUG=\" false\"\n");

        $this->assertSame(1, $code, $out);
    }
}
