<?php

namespace App\Console\Commands;

use App\Helpers\NotificationHelper;
use App\Services\Money\MoneyCheck;
use App\Services\Money\PaymentsLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The daily consistency check of the money (docs/money-trail.md §5, MT-3). Read-only. What it finds goes to the
 * «payments» log every run, and to staff by email when it differs from what they were last told (or a week later,
 * when it is still there): a finding staff already know about is not mailed every day.
 */
class MoneyCheckCommand extends Command
{
    protected $signature = 'mycs:money-check
        {--rule=* : Only these rules (see MoneyCheck::RULES)}
        {--no-mail : Do not email staff, whatever it finds}';

    protected $description = 'Check that the money adds up (invoices, payments, the journal, credit, the gateway log); read-only';

    private const MAILED_KEY = 'money-check:mailed';

    private const REMIND_DAYS = 7;

    public function handle(): int
    {
        $rules = $this->option('rule') ?: null;
        if ($rules !== null && array_diff($rules, MoneyCheck::RULES) !== []) {
            $this->error('Unknown rule(s): ' . implode(', ', array_diff($rules, MoneyCheck::RULES)) . '. Rules: ' . implode(', ', MoneyCheck::RULES));

            return self::INVALID;
        }

        $findings = (new MoneyCheck)->run($rules);
        if ($findings === []) {
            $this->info('The money adds up: nothing found.');
            PaymentsLog::info('money-check: nothing found.');
            if ($rules === null) {
                Cache::forget(self::MAILED_KEY);
            }

            return self::SUCCESS;
        }

        $this->table(['Rule', 'About', 'Detail'], array_map(fn (array $finding) => array_values($finding), $findings));
        $this->warn(count($findings) . ' finding(s).');
        PaymentsLog::warning('money-check: ' . count($findings) . ' finding(s).', ['findings' => $findings]);

        if (!$this->option('no-mail') && $rules === null) {
            $this->mailStaff($findings);
        }

        return self::FAILURE;
    }

    /** @param  list<array{rule: string, subject: string, detail: string}>  $findings */
    private function mailStaff(array $findings): void
    {
        $fingerprint = hash('sha256', json_encode($findings));
        $mailed = Cache::get(self::MAILED_KEY);
        if (is_array($mailed) && ($mailed['fingerprint'] ?? null) === $fingerprint
            && now()->timestamp - (int) ($mailed['at'] ?? 0) < self::REMIND_DAYS * 86400) {
            return; // staff were told these, less than a week ago
        }

        $lines = e(implode("\n", array_map(fn (array $finding) => "{$finding['rule']}: {$finding['subject']}: {$finding['detail']}", $findings)));
        NotificationHelper::sendSystemEmailNotification('Money check: ' . count($findings) . ' finding(s)', <<<HTML
            The daily money check found what does not add up (nothing was changed; docs/money-trail.md §5):<br>
            <pre>{$lines}</pre>
            HTML);
        Cache::forever(self::MAILED_KEY, ['fingerprint' => $fingerprint, 'at' => now()->timestamp]);
    }
}
