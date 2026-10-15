<?php

namespace App\Console\Commands\Ops;

use App\Models\Client;
use App\Models\Contact;
use App\Services\Ops\Dossier;
use Illuminate\Console\Command;

/** Everything about an account, read-only (docs/ops.md). */
class ClientCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:client {id : Account id, or a contact\'s email} {--json : JSON output}';

    protected $description = 'Read-only dossier of an account: services, invoices, credit, mails, audit';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $client = ctype_digit($id) ? Client::query()->find((int) $id)
            : Client::query()->find(Contact::query()->where('email', $id)->value('primary_of_client_id') ?? Contact::query()->where('email', $id)->value('client_id'));
        if ($client === null) {
            $this->error("No account {$id}");

            return self::FAILURE;
        }
        $this->report(Dossier::client($client));

        return self::SUCCESS;
    }
}
