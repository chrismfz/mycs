<?php

namespace App\Jobs;

use App\Models\BulkMail;
use App\Services\Mail\BulkMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A mass mail, a batch a minute (settings.bulk_mail_per_minute, 60 by default): the next batch is the next job; one chain only. */
class SendBulkMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1; // a recipient is claimed before it is mailed: never twice, so never retried blindly

    public int $timeout = 240; // under the queue's retry_after (300)

    /** @param  int|null  $seq  the batch it is (its chain's); null: whichever is next (staff, tests) */
    public function __construct(public int $bulkMailId, public ?int $seq = null) {}

    public function handle(BulkMailer $mailer): void
    {
        // Its batch taken at once, by its number: a second chain (the cron's resume while this one still waited in the
        // queue) finds it taken and ends, so the pace stays one batch a minute (reviews of #294).
        $seq = $this->seq ?? (int) BulkMail::query()->whereKey($this->bulkMailId)->value('batch_seq');
        if (BulkMail::query()->whereKey($this->bulkMailId)->where('status', BulkMail::STATUS_SENDING)->where('batch_seq', $seq)
            ->update(['batch_seq' => $seq + 1, 'batch_at' => now()]) !== 1) {
            return;
        }
        $mail = BulkMail::query()->findOrFail($this->bulkMailId);
        if ($mailer->sendBatch($mail, (int) config('settings.bulk_mail_per_minute', 60)) > 0) {
            self::dispatch($mail->id, $seq + 1)->delay(now()->addMinute());
        }
    }
}
