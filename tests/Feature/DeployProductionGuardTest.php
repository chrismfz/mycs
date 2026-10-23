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
        // The parser the guard asks (phpdotenv): the app's own vendor.
        symlink(base_path('vendor'), $this->dir . '/vendor');
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
        exec('cd ' . escapeshellarg($this->dir) . ' && env -u COMPOSER_DEV -u ALLOW_DEV_IN_PRODUCTION -u APP_DIR -u MYCS_UPDATE_COPY ' . $prefix . ' bash deploy/update.sh --help-not-a-ref 2>&1 < /dev/null', $out, $code);

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
}
