<?php

namespace Tests\Feature;

use App\Services\Ops\Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The PHP and production settings check (security audit SEC-13): debug output and dev packages in production. */
class HealthRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_outside_production_it_is_quiet(): void
    {
        config(['app.debug' => true]);

        $this->assertSame(Health::OK, Health::report()['checks']['runtime']['status']);
    }

    public function test_debug_in_production_fails(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        $check = Health::report()['checks']['runtime'];

        $this->assertSame(Health::FAIL, $check['status']);
        $this->assertStringContainsString('APP_DEBUG', $check['detail']);
    }

    public function test_dev_packages_in_production_warn(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);

        $check = Health::report()['checks']['runtime'];

        // The test run itself has them (composer with dev).
        $this->assertSame(Health::WARN, $check['status']);
        $this->assertContains('barryvdh/laravel-debugbar', $check['data']['dev_packages']);
    }
}
