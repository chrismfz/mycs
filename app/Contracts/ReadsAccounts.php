<?php

namespace App\Contracts;

use App\Models\Server;
use App\Services\Servers\ServerAccount;

/**
 * A server module's sync that reads the accounts (or guests, or mail domains) a server has, and what each is to us (owner
 * 2026-11-08: the rule for every module). After each read it reports them, as that read left them, to StrayAccounts, which
 * opens a «check this» task for each one ended in MyCS but still there, and each running one nobody mapped, and closes it
 * once a later read no longer finds that. Reads only: nothing is ever done on the server.
 */
interface ReadsAccounts
{
    /**
     * The server's accounts as its last read found them: only the ones still on the server (not the ones marked gone).
     *
     * @return iterable<ServerAccount>
     */
    public function accounts(Server $server): iterable;
}
