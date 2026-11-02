<?php

namespace App\Support;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a transaction decided, done once it is committed: its events (below) and its server jobs (job()).
 *
 * Events that tell the world something happened (mails, Discord, extensions) fire once it is committed (audit sweep
 * Σ4, EVT-1): rolled back, they never fire; outside a transaction, at once. Not afterResponse(): the cron and the
 * queue workers have no response, so they held them to the end of the process or never sent them.
 *
 * A listener that fails is reported and goes no further (EVT-2): the after-commit callbacks queued behind it (a paid
 * invoice's renewal among them) still run.
 */
class AfterCommit
{
    /**
     * A server job its transaction decided on (an account to make, unsuspend or upgrade once paid) goes with that
     * transaction, never lost between its commit and the queue (predeploy S4). On a database queue of this connection
     * (what MyCS runs) it is written in the transaction: committed with it or rolled back with it. A job unique per
     * service, or another queue, is pushed once committed: a push that fails there releases the job's lock (dispatched
     * within the callback, not by ->afterCommit(), whose lock would be held for hours) and throws to whoever committed.
     * A paid invoice keeps its marker then, and OwedCreates makes an account owed again.
     */
    public static function job(object $job): void
    {
        if (DB::transactionLevel() > 0 && !$job instanceof ShouldBeUnique && self::queuedInThisDatabase($job)) {
            dispatch($job)->beforeCommit();

            return;
        }
        DB::afterCommit(fn () => dispatch($job)->beforeCommit());
    }

    private static function queuedInThisDatabase(object $job): bool
    {
        $name = $job->connection ?? config('queue.default');
        $queue = config("queue.connections.{$name}");

        return is_array($queue) && ($queue['driver'] ?? null) === 'database'
            && (($queue['connection'] ?? null) ?: DB::getDefaultConnection()) === DB::connection()->getName();
    }

    /**
     * @param  Model|null  $about  the model the event is about: deleted before the commit (created and dropped in one
     *                             transaction), the event does not fire
     */
    public static function event(object $event, ?Model $about = null): void
    {
        DB::afterCommit(function () use ($event, $about) {
            if ($about !== null && !$about->exists) {
                return;
            }
            try {
                event($event);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
