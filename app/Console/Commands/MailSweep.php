<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use Illuminate\Console\Command;

/**
 * Mails stuck on their way out (docs/mail.md): «sending» for SENDING_MINUTES (a worker stopped mid-send: it may have
 * gone out, so it is not sent again by itself) and «pending» for PENDING_HOURS (the queue never ran it, past every
 * retry's backoff) are marked failed with why, for staff to resend.
 */
class MailSweep extends Command
{
    public const SENDING_MINUTES = 30;

    public const PENDING_HOURS = 24;

    protected $signature = 'app:mail-sweep {--dry-run : Count only}';

    protected $description = 'Marks mails stuck in sending or pending as failed';

    public function handle(): int
    {
        $sending = EmailLog::query()->where('status', EmailLog::STATUS_SENDING)->where('sending_at', '<', now()->subMinutes(self::SENDING_MINUTES));
        $pending = EmailLog::query()->where('status', EmailLog::STATUS_PENDING)->where('created_at', '<', now()->subHours(self::PENDING_HOURS));
        if ($this->option('dry-run')) {
            $this->info("Stuck sending: {$sending->count()}, pending: {$pending->count()}");

            return self::SUCCESS;
        }
        $a = $sending->update(['status' => EmailLog::STATUS_FAILED, 'failed_at' => now(),
            'error' => 'The worker stopped while sending: it may have gone out. Check before resending.']);
        $b = $pending->update(['status' => EmailLog::STATUS_FAILED, 'failed_at' => now(),
            'error' => 'Never sent: the queue did not run it within ' . self::PENDING_HOURS . ' hours.']);
        $this->info("Marked failed: {$a} stuck sending, {$b} pending.");

        return self::SUCCESS;
    }
}
