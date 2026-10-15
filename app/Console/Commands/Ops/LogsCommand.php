<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\LogTail;
use Illuminate\Console\Command;

/** The latest log entries, secrets masked (docs/ops.md). */
class LogsCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:logs {--limit=20} {--level=error : Lowest level shown} {--channel=laravel : Log file prefix (laravel, payments)} {--json : JSON output}';

    protected $description = 'Read-only tail of the app log, newest first, secrets masked';

    public function handle(): int
    {
        $this->report(LogTail::entries(max(1, (int) $this->option('limit')), (string) $this->option('level'), (string) $this->option('channel')));

        return self::SUCCESS;
    }
}
