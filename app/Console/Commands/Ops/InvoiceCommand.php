<?php

namespace App\Console\Commands\Ops;

use App\Models\Invoice;
use App\Services\Ops\Dossier;
use Illuminate\Console\Command;

/** Everything about an invoice, read-only (docs/ops.md). */
class InvoiceCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:invoice {id : Invoice number, or its id (#12 for the id)} {--json : JSON output}';

    protected $description = 'Read-only dossier of an invoice: lines, transactions, mails, audit';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        // «#12» is the id; else the number, or the id when no invoice has that number. Both, and different: asked (review 8).
        if (str_starts_with($id, '#')) {
            $invoice = Invoice::query()->find((int) substr($id, 1));
        } else {
            $byNumber = Invoice::query()->where('number', $id)->first();
            $byId = ctype_digit($id) ? Invoice::query()->find((int) $id) : null;
            if ($byNumber && $byId && !$byNumber->is($byId)) {
                $this->error("{$id} is the number of #{$byNumber->id} and the id of another: ask «#{$id}» for the id, or by its number.");

                return self::FAILURE;
            }
            $invoice = $byNumber ?? $byId;
        }
        if ($invoice === null) {
            $this->error("No invoice {$id}");

            return self::FAILURE;
        }
        $this->report(Dossier::invoice($invoice));

        return self::SUCCESS;
    }
}
