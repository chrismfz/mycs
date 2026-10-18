<?php

namespace Tests\Unit;

use App\Support\TimeLimit;
use PHPUnit\Framework\TestCase;

/** A registrar call's time limit: raised where there is one, never put on an unlimited CLI process (the suite, a worker). */
class TimeLimitTest extends TestCase
{
    private string $before;

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = (string) ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        set_time_limit((int) $this->before);
        parent::tearDown();
    }

    public function test_an_unlimited_process_stays_unlimited(): void
    {
        set_time_limit(0);

        TimeLimit::atLeast(600);

        $this->assertSame('0', ini_get('max_execution_time'));
    }

    public function test_a_limited_request_gets_the_time(): void
    {
        set_time_limit(30);

        TimeLimit::atLeast(600);

        $this->assertSame('600', ini_get('max_execution_time'));
    }

    public function test_a_longer_limit_is_not_cut(): void
    {
        set_time_limit(3600);

        TimeLimit::atLeast(600);

        $this->assertSame('3600', ini_get('max_execution_time'));
    }

    public function test_the_app_sets_no_time_limit_but_through_the_helper(): void
    {
        // A bare set_time_limit() in a service killed the suite minutes later (review 4: guard the call sites).
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (['app', 'extensions', 'packages'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/') || str_ends_with($path, 'app/Support/TimeLimit.php')) {
                    continue;
                }
                if (preg_match('/\bset_time_limit\s*\(|max_execution_time/', (string) file_get_contents($path)) === 1) {
                    $found[] = substr($path, strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $found);
    }
}
