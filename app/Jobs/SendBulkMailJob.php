<?php

namespace App\Jobs;

use App\Models\BulkMail;
use App\Services\Mail\BulkMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A mass mail, a batch a minute (settings.bulk_mail_per_minute, 60 by default): the next batch is the next job. */
class SendBulkMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1; // a recipient is claimed before it is mailed: never twice, so never retried blindly

    public int $timeout = 300;

    public function __construct(public int $bulkMailId) {}

    public function handle(BulkMailer $mailer): void
    {
        $mail = BulkMail::query()->find($this->bulkMailId);
        if ($mail === null || $mail->status !== BulkMail::STATUS_SENDING) {
            return;
        }
        if ($mailer->sendBatch($mail, (int) config('settings.bulk_mail_per_minute', 60)) > 0) {
            self::dispatch($mail->id)->delay(now()->addMinute());
        }
    }
}
