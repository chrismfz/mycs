<?php

namespace App\Services\Simulation;

use App\Contracts\DomainRegistrar;
use App\Models\Domain;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\TldPricing;

/**
 * A registrar that answers «done» and remembers what it was asked (`mycs:simulate`): a renewal moves the expiry a year
 * on, as the real one's answer would. It asks nobody.
 */
class SimRegistrar implements DomainRegistrar
{
    /** @var list<string> */
    public static array $calls = [];

    public function key(): string
    {
        return 'sim';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return DomainRegistrarCapabilities::none();
    }

    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        return true;
    }

    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        throw new DomainRegistrarNotConfigured('Not simulated.');
    }

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$calls[] = "sync {$domain->fqdn}";

        return new DomainSyncResult(expiresAt: $domain->expires_at?->toDateString());
    }

    public function getTldPricing(string $tld, DomainRegistrarCredentials $credentials): TldPricing
    {
        throw new DomainRegistrarNotConfigured('Not simulated.');
    }

    public function listDomains(DomainRegistrarCredentials $credentials, int $offset, int $limit): RegistrarDomainPage
    {
        throw new DomainRegistrarNotConfigured('Not simulated.');
    }

    public function getContact(string $handle, DomainRegistrarCredentials $credentials): ?RegistrarContact
    {
        return null;
    }

    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$calls[] = "renew {$domain->fqdn} {$years}y";

        return new DomainSyncResult(expiresAt: $domain->expires_at?->copy()->addYears($years)->toDateString());
    }

    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$calls[] = "register {$domain->fqdn} {$years}y";

        return new DomainSyncResult(expiresAt: now()->addYears($years)->toDateString());
    }

    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$calls[] = "transfer {$domain->fqdn}";

        return new DomainSyncResult;
    }

    public function getEppCode(Domain $domain, DomainRegistrarCredentials $credentials): ?string
    {
        return null;
    }

    public function updateDomain(Domain $domain, DomainChanges $changes, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$calls[] = "update {$domain->fqdn}";

        return new DomainSyncResult;
    }

    public function restore(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$calls[] = "restore {$domain->fqdn}";

        return new DomainSyncResult;
    }
}
