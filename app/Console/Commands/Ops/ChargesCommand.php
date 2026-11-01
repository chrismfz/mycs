<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\Charges;
use App\Services\Ops\Lookup;
use Illuminate\Console\Command;
use RuntimeException;

/** The recent automatic charges of saved methods, read-only (docs/ops.md). */
class ChargesCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:charges {--status= : started, succeeded, failed, unknown, action_required, or taken_not_recorded} {--client= : Account id or email}
        {--invoice= : Invoice number, id, or #id} {--days=7 : Days back, at most 90} {--limit=30 : At most 200} {--json : JSON output}';

    protected $description = 'Read-only list of the automatic charges of saved methods, newest first, with what needs staff';

    public function handle(): int
    {
        try {
            $this->report(Charges::recent(
                filled($this->option('status')) ? (string) $this->option('status') : null,
                filled($this->option('client')) ? Lookup::client((string) $this->option('client')) : null,
                filled($this->option('invoice')) ? Lookup::invoice((string) $this->option('invoice')) : null,
                max(1, min(90, (int) $this->option('days'))),
                max(1, min(200, (int) $this->option('limit'))),
            ));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
