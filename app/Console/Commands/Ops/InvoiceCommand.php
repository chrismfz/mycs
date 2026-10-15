<?php

namespace App\Console\Commands\Ops;

use App\Models\Invoice;
use App\Services\Ops\Dossier;
use Illuminate\Console\Command;

/** Everything about an invoice, read-only (docs/ops.md). */
class InvoiceCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:invoice {id : Invoice id, or its number} {--json : JSON output}';

    protected $description = 'Read-only dossier of an invoice: lines, transactions, mails, audit';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $invoice = Invoice::query()->where('number', $id)->first() ?? (ctype_digit($id) ? Invoice::query()->find((int) $id) : null);
        if ($invoice === null) {
            $this->error("No invoice {$id}");

            return self::FAILURE;
        }
        $this->report(Dossier::invoice($invoice));

        return self::SUCCESS;
    }
}
