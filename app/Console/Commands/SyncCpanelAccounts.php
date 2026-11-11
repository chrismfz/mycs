<?php

namespace App\Console\Commands;

use App\Models\CpanelAccount;
use App\Models\Server;
use App\Services\Cpanel\AccountSync;
use App\Support\Redact;
use Illuminate\Console\Command;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Throwable;

/** Every WHM server's accounts read again (Hosting Accounts); a server that fails keeps what it had. */
class SyncCpanelAccounts extends Command
{
    protected $signature = 'cpanel:sync {--server= : one server (its id)} {--domains : list the services whose domain is not their account\'s in WHM}';

    protected $description = 'Read the accounts of every cPanel server (read-only)';

    public function handle(): int
    {
        $failed = false;
        $servers = AccountSync::servers()->when($this->option('server') !== null, fn ($c) => $c->where('id', (int) $this->option('server')));
        if ($this->option('server') !== null && $servers->isEmpty()) {
            $this->error('No enabled cPanel server with that id.'); // review of #492: never a silent success

            return self::FAILURE;
        }
        foreach ($servers as $server) {
            /** @var Server $server */
            try {
                $result = AccountSync::sync($server);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$server->name}: " . Redact::text($e->getMessage()));
                $failed = true;

                continue;
            }
            $this->line("{$server->name}: {$result['seen']} account(s), {$result['new']} new, {$result['gone']} gone");
            if ($this->option('domains')) {
                $this->domains($server);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The services of this server whose domain is not the primary domain of WHM's account by their username, compared as
     * the module compares them (CPanel::normalDomain): the module refuses to suspend, unsuspend, terminate or open such
     * an account (sweep 7, S7-PROVISIONING-1), so staff correct the service's domain or username first. Ids only.
     */
    private function domains(Server $server): void
    {
        // By the sync's own mapping (the account's one live service, by its username).
        $ids = CpanelAccount::query()->where('server_id', $server->id)->whereNull('gone_at')->whereNotNull('service_id')->whereNotNull('domain')
            ->with('service:id,domain')->orderBy('service_id')->get()
            ->filter(fn (CpanelAccount $account) => filled($account->service?->domain)
                && CPanel::normalDomain((string) $account->service->domain) !== CPanel::normalDomain((string) $account->domain))
            ->pluck('service_id');
        $this->line("{$server->name}: " . ($ids->isEmpty() ? 'every service\'s domain is its account\'s'
            : $ids->count() . ' service(s) with another domain than their account\'s in WHM: #' . $ids->implode(', #')));
    }
}
