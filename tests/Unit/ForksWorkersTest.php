<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ForksWorkers;

/** The row-lock tests' fork helpers: a worker's end is read from its exit, and a hung one never hangs the run. */
class ForksWorkersTest extends TestCase
{
    use ForksWorkers;

    private string $dir;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs pcntl.');
        }
        $this->dir = sys_get_temp_dir() . '/mycs-forks-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function fork(callable $body): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $body();
        }

        return $pid;
    }

    public function test_a_worker_that_ends_well_or_not(): void
    {
        $good = $this->fork(fn () => self::endWorker($this->dir, 0, 0));
        $bad = $this->fork(fn () => self::endWorker($this->dir, 1, 1));

        $this->assertTrue($this->awaitWorker($good, $this->dir, 0));
        $this->assertFalse($this->awaitWorker($bad, $this->dir, 1));
    }

    public function test_a_hung_worker_is_killed_and_fails(): void
    {
        $hung = $this->fork(function () {
            sleep(30);
            self::endWorker($this->dir, 0, 0);
        });

        $started = microtime(true);
        $this->assertFalse($this->awaitWorker($hung, $this->dir, 0, seconds: 1));
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertStringContainsString('did not finish', (string) file_get_contents("{$this->dir}/error-0"));
    }

    /** The harnesses' finally: a worker still running is killed and reaped, with or without posix. */
    public function test_still_running_workers_are_reaped(): void
    {
        $done = $this->fork(fn () => self::endWorker($this->dir, 0, 0));
        $hung = $this->fork(function () {
            sleep(30);
            self::endWorker($this->dir, 1, 0);
        });
        $this->assertTrue($this->awaitWorker($done, $this->dir, 0)); // already reaped: skipped

        $started = microtime(true);
        self::reapWorkers([$done, $hung]);
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertSame(-1, pcntl_waitpid($hung, $status, WNOHANG)); // reaped, no zombie left
        $this->assertFileDoesNotExist("{$this->dir}/exit-1-0");
    }
}
