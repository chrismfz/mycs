<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * deploy/update.sh refuses dev packages and debug output on production (security audit SEC-13), before it touches
 * anything: run in an empty checkout that only has its .env, it stops at once. (Only refusals are run: past the
 * guard the script would go on to deploy.)
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
        exec('cd ' . escapeshellarg($this->dir) . ' && env -u COMPOSER_DEV -u ALLOW_DEV_IN_PRODUCTION ' . $prefix . ' bash deploy/update.sh --help-not-a-ref 2>&1 < /dev/null', $out, $code);

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
}
