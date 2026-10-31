<?php

namespace App\Services\Simulation;

use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
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

    /** @param  DomainRegistrar|null  $real  the registrar it stands for: its key and what it can do (a .gr renews by two years, a CNIC expiry is when it goes) */
    public function __construct(private readonly ?DomainRegistrar $real = null) {}

    public function key(): string
    {
        return $this->real?->key() ?? 'sim';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return $this->real?->capabilities() ?? DomainRegistrarCapabilities::none();
    }

    /** What the registrar says of a domain: as this app has it (the simulation does not change a registrar's mind). */
    private static function as(Domain $domain, ?string $expiresAt): DomainSyncResult
    {
        return new DomainSyncResult(expiresAt: $expiresAt, status: $domain->status, rawStatus: $domain->status->value);
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

        return self::as($domain, $domain->expires_at?->toDateString());
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

        return new DomainSyncResult(expiresAt: $domain->expires_at?->copy()->addYears($years)->toDateString(), status: DomainStatus::Active, rawStatus: 'active');
    }

    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        self::$calls[] = "register {$domain->fqdn} {$years}y";

        return new DomainSyncResult(expiresAt: now()->addYears($years)->toDateString(), status: DomainStatus::Active, rawStatus: 'active');
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
