<?php

namespace App\Console\Commands\Ops;

use App\Models\Client;
use App\Models\Contact;
use App\Models\User;
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
        if (ctype_digit($id)) {
            $client = Client::query()->find((int) $id);
        } else {
            // A primary contact's email first, then a login's, then any contact's; more than one account: named (review 7).
            $ids = Contact::query()->where('email', $id)->whereNotNull('primary_of_client_id')->pluck('primary_of_client_id')->all()
                ?: (array) User::query()->where('email', $id)->first()?->clients()->pluck('clients.id')->all()
                ?: Contact::query()->where('email', $id)->pluck('client_id')->all();
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (count($ids) > 1) {
                $this->error("{$id} is on accounts " . implode(', ', $ids) . ': ask by id');

                return self::FAILURE;
            }
            $client = $ids === [] ? null : Client::query()->find($ids[0]);
        }
        if ($client === null) {
            $this->error("No account {$id}");

            return self::FAILURE;
        }
        $this->report(Dossier::client($client));

        return self::SUCCESS;
    }
}
