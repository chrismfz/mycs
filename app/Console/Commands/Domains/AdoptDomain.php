<?php

namespace App\Console\Commands\Domains;

use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\DomainSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A domain already at a registrar, onto an account (docs/domains.md §7): read from the registrar, never changed there.
 * Its expiry, status, nameservers and id come from the registrar; its contact roles are the account's primary contact
 * until staff choose others. Refused if the registrar does not have it: nothing is created then.
 */
class AdoptDomain extends Command
{
    protected $signature = 'domains:adopt {domain : the name} {--client= : the account id} {--connection= : the registrar connection id}';

    protected $description = 'Bring a domain that is already at a registrar onto an account (reads the registrar only)';

    public function handle(DomainSyncService $sync): int
    {
        Config::set('audit.console', true); // its changes are audited, as CronJob's (D-9)
        $client = Client::query()->find((int) $this->option('client'));
        $connection = DomainRegistrarConnection::query()->find((int) $this->option('connection'));
        if ($client === null || $connection === null || !$connection->isUsable()) {
            $this->error('Give an account (--client) and an active registrar connection with an API (--connection).');

            return self::FAILURE;
        }
        $fqdn = Domain::normalise((string) $this->argument('domain'));
        [$sld, $tld] = explode('.', $fqdn, 2) + [1 => ''];
        if (Domain::query()->where('live_fqdn', $fqdn)->exists()) {
            $this->error("{$fqdn} is already here.");

            return self::FAILURE;
        }
        $client->ensurePrimaryContact();
        $contact = $client->fresh()->primaryContact;

        try {
            $domain = DB::transaction(function () use ($client, $connection, $fqdn, $sld, $tld, $contact, $sync) {
                $domain = Domain::query()->create([
                    'client_id' => $client->id,
                    'domain_tld_id' => DomainTld::query()->where('tld', $tld)->value('id'),
                    // Its own connection: it is where the domain is, whatever its TLD's says.
                    'registrar_connection_id' => $connection->id,
                    'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn,
                    'status' => DomainStatus::Active,
                    'currency_code' => (string) config('settings.default_currency', 'USD'),
                    'registrant_contact_id' => $contact?->id,
                    'admin_contact_id' => $contact?->id,
                ]);
                $sync->sync($domain, true); // the registrar's truth, or nothing at all

                return $domain->fresh();
            });
        } catch (Throwable $e) {
            $this->error("{$fqdn}: " . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$domain->fqdn}: account #{$client->id}, expires {$domain->expires_at?->toDateString()}, {$domain->status->value}, "
            . $domain->nameservers()->pluck('host')->implode(', '));

        return self::SUCCESS;
    }
}
