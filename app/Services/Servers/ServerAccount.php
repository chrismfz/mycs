<?php

namespace App\Services\Servers;

/**
 * One account (guest, mail domain) of a server as a module's read reports it to StrayAccounts (ReadsAccounts).
 *
 * - remoteId: what the server calls it, unique on that server (a cPanel username, a VMID, a mail domain);
 * - label: what staff read in the task (its domain, its name);
 * - running: not suspended or stopped on the server;
 * - serviceId: the service it is mapped to, if any (even one that ended);
 * - internal: never a customer's, so never «not mapped»: marked internal, a template, a reseller's own customer's.
 */
final readonly class ServerAccount
{
    public function __construct(
        public string $remoteId,
        public string $label,
        public bool $running,
        public ?int $serviceId,
        public bool $internal = false,
    ) {}
}
