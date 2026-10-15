<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\Dossier;
use App\Services\Ops\Lookup;
use Illuminate\Console\Command;
use RuntimeException;

/** Everything about an invoice, read-only (docs/ops.md). */
class InvoiceCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:invoice {id : Invoice number, or its id (#12 for the id)} {--json : JSON output}';

    protected $description = 'Read-only dossier of an invoice: lines, transactions, mails, audit';

    public function handle(): int
    {
        try {
            $found = Lookup::invoice((string) $this->argument('id'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->report(Dossier::invoice($found));

        return self::SUCCESS;
    }
}
