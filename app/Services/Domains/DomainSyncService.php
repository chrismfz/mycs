<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The registrar's truth onto a domain (docs/domains.md D-9), from Ekdosi through the spike: expiry, status,
 * nameservers, the registrar's id, lock and privacy. First a plan of what would change (the diff report of
 * `domains:sync --dry-run`), then the same plan written, with the audit of each change. The registrar only moves a
 * status forward; staff's ends (cancelled, transferred away) are never overwritten; a pending order takes no expiry.
 * A failure is written on the domain (sync_error) and rethrown.
 */
class DomainSyncService
{
    private const PENDING = [DomainStatus::PendingRegister, DomainStatus::PendingTransfer];

    public function __construct(private readonly DomainRegistrarFactory $factory) {}

    /** It can be synced: routed to an active connection with an API, and not ended by staff. */
    public function isSyncable(Domain $domain): bool
    {
        $connection = $domain->effectiveRegistrarConnection();

        return $connection instanceof DomainRegistrarConnection && $connection->isUsable() && !$domain->status->blocksSync();
    }

    /**
     * Read the registrar and plan; with $write, apply the plan.
     *
     * @return array<string, array{0: mixed, 1: mixed}> field => [now, registrar's]
     */
    public function sync(Domain $domain, bool $write = true): array
    {
        $connection = $domain->effectiveRegistrarConnection();
        if (!$connection instanceof DomainRegistrarConnection || !$connection->isUsable()) {
            throw new DomainRegistrarNotConfigured("{$domain->fqdn} has no registrar connection with an API.");
        }
        try {
            $result = $this->factory->for($connection)->syncDomain($domain, $this->factory->credentialsFor($connection));
        } catch (Throwable $e) {
            if ($write) {
                Domain::query()->whereKey($domain->id)->update(['last_synced_at' => now(), 'sync_error' => mb_substr($e->getMessage(), 0, 1000)]);
            }

            throw $e;
        }
        $changes = $this->plan($domain, $result);
        if ($write) {
            $this->apply($domain, $result, $changes);
        }

        return $changes;
    }

    /**
     * What the registrar's answer would change.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function plan(Domain $domain, DomainSyncResult $result): array
    {
        $changes = [];
        $set = function (string $field, mixed $now, mixed $then) use (&$changes) {
            if ($then !== null && $now !== $then) {
                $changes[$field] = [$now, $then];
            }
        };
        $pending = in_array($domain->status, self::PENDING, true) && $result->status !== DomainStatus::Active;
        if (!$pending) {
            $set('expires_at', $domain->expires_at?->toDateString(), $result->expiresAt);
        }
        $set('registrar_domain_id', $domain->registrar_domain_id, $result->registrarDomainId);
        $set('transfer_lock', $domain->transfer_lock, $result->transferLock);
        $set('id_protection', $domain->id_protection, $result->whoisPrivacy);

        $status = $result->status;
        // A tombstone of the name's past (DEL/FAI) never ends a pending order; a pending order stays what it is.
        if ($status === DomainStatus::Deleted && in_array($domain->status, self::PENDING, true)) {
            $status = null;
        }
        if ($status !== null && !$domain->status->blocksSync()) {
            $set('status', $domain->status->value, $status->value);
        }

        $nameservers = $domain->nameservers->pluck('host')->all();
        if ($result->nameservers !== [] && $result->nameservers !== $nameservers) {
            $changes['nameservers'] = [implode(', ', $nameservers), implode(', ', $result->nameservers)];
        }

        return $changes;
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes */
    private function apply(Domain $domain, DomainSyncResult $result, array $changes): void
    {
        DB::transaction(function () use ($domain, $result, $changes) {
            $updates = ['last_synced_at' => Carbon::now(), 'sync_error' => null];
            foreach ($changes as $field => [, $then]) {
                if ($field !== 'nameservers') {
                    $updates[$field] = $then;
                }
            }
            if ($result->rawStatus !== null) {
                $updates['meta'] = array_merge($domain->meta ?? [], ['registrar_status' => $result->rawStatus]);
            }
            $domain->forceFill($updates)->save(); // audited

            // The delegation as the registrar holds it, replaced together with the row.
            if (isset($changes['nameservers'])) {
                $domain->nameservers()->delete();
                foreach ($result->nameservers as $i => $host) {
                    $domain->nameservers()->create(['host' => $host, 'sort' => $i]);
                }
                $domain->unsetRelation('nameservers');
            }
        });
    }
}
