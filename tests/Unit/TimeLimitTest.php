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
}
