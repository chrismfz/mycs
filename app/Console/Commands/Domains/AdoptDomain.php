<?php

namespace App\Console\Commands\Domains;

use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\DomainAdopter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * A domain already at a registrar, onto an account (docs/domains.md §7): read from the registrar, never changed there.
 * Its expiry, status, nameservers and id come from the registrar; its contact roles are the account's primary contact
 * until staff choose others. Refused if the registrar does not have it: nothing is created then.
 */
class AdoptDomain extends Command
{
    protected $signature = 'domains:adopt {domain : the name} {--client= : the account id} {--connection= : the registrar connection id}
        {--currency= : the currency it is billed in (default: the account\'s last invoice, else the default currency)}';

    protected $description = 'Bring a domain that is already at a registrar onto an account (reads the registrar only)';

    public function handle(DomainAdopter $adopter): int
    {
        Config::set('audit.console', true); // its changes are audited, as CronJob's (D-9)
        $client = Client::query()->find((int) $this->option('client'));
        $connection = DomainRegistrarConnection::query()->find((int) $this->option('connection'));
        if ($client === null || $connection === null) {
            $this->error('Give an account (--client) and a registrar connection (--connection).');

            return self::FAILURE;
        }
        try {
            $domain = $adopter->adopt((string) $this->argument('domain'), $client, $connection, $this->option('currency') ?: null);
        } catch (DisplayException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$domain->fqdn}: account #{$domain->client_id}, expires {$domain->expires_at?->toDateString()}, {$domain->status->value}, "
            . $domain->nameservers()->pluck('host')->implode(', '));

        return self::SUCCESS;
    }
}
