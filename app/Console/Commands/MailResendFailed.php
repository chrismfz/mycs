<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use App\Services\Mail\MailAudit;
use Illuminate\Console\Command;

/**
 * After a mail outage (docs/mail.md): the customer mails that failed since a day, not resent yet, sent again (batch),
 * up to a limit. By hand only, never scheduled: a mass resend is staff's decision.
 */
class MailResendFailed extends Command
{
    protected $signature = 'app:mail-resend-failed {--days=7 : Failed in the last days} {--limit=50 : At most} {--dry-run : List only}';

    protected $description = 'Resends customer mails that failed and were not resent yet';

    public function handle(): int
    {
        $failed = EmailLog::query()->where('status', EmailLog::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            // Customer mails only (not staff's system ones, nor rows from before the audit), that never went out:
            // not one that may have (left mid-send) nor a refused To, both with sending_at (review R5).
            ->whereNotNull('template_key')->where('template_key', '!=', 'system')->whereNull('sending_at')
            ->whereNotExists(fn ($query) => $query->from('email_logs as resends')->whereColumn('resends.resent_from_id', 'email_logs.id'))
            ->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();

        foreach ($failed as $log) {
            $this->line("#{$log->id} {$log->to}: {$log->subject}");
            if (!$this->option('dry-run')) {
                MailAudit::resend($log, $log->to, trigger: EmailLog::TRIGGER_BATCH);
            }
        }
        $this->info(($this->option('dry-run') ? 'Would resend ' : 'Resent ') . $failed->count() . '.');

        return self::SUCCESS;
    }
}
