<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
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
