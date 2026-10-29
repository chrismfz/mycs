<?php

namespace Tests\Feature;

use App\Services\Ops\Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #529 (pre-launch audit flow 8). Each test fails on the PR's head and describes the behaviour
 * expected once its finding (ADV-529-n) is fixed.
 */
class Adv529Test extends TestCase
{
    use RefreshDatabase;

    /**
     * ADV-529-1 (Medium): AUD8-4's own scenario, «with redis the worker is dead and /health says ok», is still ok. The
     * redis branch of Health::queue() fails only when redis cannot be reached; a reachable redis with 500 jobs waiting
     * for an hour (the worker dead) is reported OK, where the database branch fails past 600 s of waiting. The queue
     * contract has creationTimeOfOldestPendingJob() (RedisQueue implements it) to apply the same thresholds.
     */
    public function test_adv_529_1_a_redis_queue_with_a_dead_worker_is_not_ok(): void
    {
        Queue::extend('stuck', fn () => new class
        {
            public function connect(array $config)
            {
                return new class extends NullQueue
                {
                    public function size($queue = null)
                    {
                        return 500;
                    }

                    public function pendingSize($queue = null)
                    {
                        return 500;
                    }

                    public function creationTimeOfOldestPendingJob($queue = null)
                    {
                        return now()->subHour()->getTimestamp(); // nothing has taken a job for an hour
                    }
                };
            }
        });
        config(['queue.connections.stuck' => ['driver' => 'stuck'], 'queue.default' => 'stuck']);

        $queue = Health::report()['checks']['queue'];

        $this->assertNotSame(Health::OK, $queue['status'],
            '500 jobs waiting for an hour with no worker taking them, yet Health says: ' . $queue['detail']);
    }
}
