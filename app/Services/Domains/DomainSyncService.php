<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The registrar's truth onto a domain (docs/domains.md D-9), from Ekdosi through the spike: expiry, status,
 * nameservers, the registrar's id, lock and privacy. First a plan of what would change (the diff report of
 * `domains:sync --dry-run`), then the same plan written, audited, only when something changed.
 *
 * Forward only (D-9): the registrar never brings a lapsed domain back to active unless its expiry is ahead again; never
 * moves an expiry back (it is written on the domain for staff instead, as WHMCS's «notify only»); never touches staff's
 * ends or a deleted domain (a restore comes through its own action); a pending order takes no expiry. A failure is
 * written on the domain (sync_error) and rethrown.
 */
class DomainSyncService
{
    private const PENDING = [DomainStatus::PendingRegister, DomainStatus::PendingTransfer];

    private const LAPSED = [DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption];

    public function __construct(private readonly DomainRegistrarFactory $factory) {}

    /** It is synced: routed to an active connection with an API, not ended by staff, not deleted. */
    public function isSyncable(Domain $domain): bool
    {
        $connection = $domain->effectiveRegistrarConnection();

        return $connection instanceof DomainRegistrarConnection && $connection->isUsable()
            && !$domain->status->blocksSync() && $domain->status !== DomainStatus::Deleted;
    }

    /** Read the registrar for a domain (saved or not). */
    public function read(Domain $domain): DomainSyncResult
    {
        $connection = $domain->effectiveRegistrarConnection();
        if (!$connection instanceof DomainRegistrarConnection || !$connection->isUsable()) {
            throw new DomainRegistrarNotConfigured("{$domain->fqdn} has no registrar connection with an API.");
        }

        return $this->factory->for($connection)->syncDomain($domain, $this->factory->credentialsFor($connection));
    }

    /**
     * Read the registrar and plan; with $write, apply the plan.
     *
     * @return array<string, array{0: mixed, 1: mixed}> field => [now, registrar's]
     */
    public function sync(Domain $domain, bool $write = true): array
    {
        try {
            $result = $this->read($domain);
        } catch (Throwable $e) {
            if ($write) {
                $this->bookkeep($domain, mb_substr($e->getMessage(), 0, 1000));
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
     * The registrar's answer to a write (D4b: nameservers, lock, privacy, contacts) applied as a sync's would be.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function applyResult(Domain $domain, DomainSyncResult $result): array
    {
        $changes = $this->plan($domain, $result);
        $this->apply($domain, $result, $changes);

        return $changes;
    }

    /**
     * What the registrar's answer would change; `_warning` carries what is not applied for staff to look at.
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
        $now = $domain->expires_at?->toDateString();
        $pending = in_array($domain->status, self::PENDING, true) && $result->status !== DomainStatus::Active;
        if (!$pending && $result->expiresAt !== null) {
            if ($now !== null && $result->expiresAt < $now) {
                // Never back (review F2: a garbled 1970 would have ended a renewed domain in one run).
                $changes['_warning'] = [null, "the registrar's expiry {$result->expiresAt} is before ours ({$now}): not applied, check it"];
            } else {
                $set('expires_at', $now, $result->expiresAt);
            }
        }
        $set('registered_at', $domain->registered_at?->toDateString(), $result->registeredAt);
        $set('registrar_domain_id', $domain->registrar_domain_id, $result->registrarDomainId);
        // The registrar's handle per role (D4b): one it holds that we lack, or another it now has. A handle it does not
        // report is not cleared.
        foreach (Domain::ROLES as $role) {
            $set("{$role}_handle", $domain->{"{$role}_handle"}, $result->contactHandles[$role] ?? null);
        }
        // A domain that came in before its TLD (adopted, imported) is linked once the TLD is here.
        if ($domain->domain_tld_id === null) {
            $set('domain_tld_id', null, DomainTld::query()->where('tld', $domain->tld)->value('id'));
        }
        $set('transfer_lock', $domain->transfer_lock, $result->transferLock);
        $set('id_protection', $domain->id_protection, $result->whoisPrivacy);

        $status = $result->status;
        $expiry = $changes['expires_at'][1] ?? $now;
        if ($status === DomainStatus::Deleted && in_array($domain->status, self::PENDING, true)) {
            $status = null; // a tombstone of the name's past never ends a pending order
        }
        if ($status === DomainStatus::Active && in_array($domain->status, self::LAPSED, true)
            && ($expiry === null || $expiry < Carbon::today()->toDateString())) {
            $status = null; // still lapsed by its dates: the lifecycle decides (review F3)
        }
        if ($status !== null && !$domain->status->blocksSync() && $domain->status !== DomainStatus::Deleted) {
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
        $warning = $changes['_warning'][1] ?? null;
        unset($changes['_warning']);
        $meta = $domain->meta ?? [];
        if ($result->rawStatus !== null) {
            $meta['registrar_status'] = $result->rawStatus;
        }
        if ($result->details !== []) {
            $meta['registrar'] = $result->details; // what staff see without the registrar's panel (D4b)
        }
        if (isset($changes['nameservers'])) {
            // Both, so the domain's audit shows what the delegation was and what it became (review F11).
            $meta['nameservers'] = $result->nameservers;
            $meta['nameservers_before'] = $domain->nameservers->pluck('host')->all();
        }
        $updates = [];
        foreach ($changes as $field => [, $then]) {
            if ($field !== 'nameservers') {
                $updates[$field] = $then;
            }
        }
        if ($meta !== ($domain->meta ?? [])) {
            $updates['meta'] = $meta;
        }

        DB::transaction(function () use ($domain, $result, $changes, $updates, $warning) {
            if ($updates !== []) {
                $domain->forceFill($updates)->save(); // audited, only when something changed (review F12)
            }
            if (isset($changes['nameservers'])) {
                $domain->nameservers()->delete();
                foreach ($result->nameservers as $i => $host) {
                    $domain->nameservers()->create(['host' => $host, 'sort' => $i]);
                }
                $domain->unsetRelation('nameservers');
            }
            $this->bookkeep($domain, $warning);
        });
    }

    /** When it was read and what went wrong: bookkeeping, not a change of the domain (no audit, no updated_at). */
    private function bookkeep(Domain $domain, ?string $error): void
    {
        $at = Carbon::now();
        DB::table('domains')->where('id', $domain->id)->update(['last_synced_at' => $at, 'sync_error' => $error]);
        $domain->forceFill(['last_synced_at' => $at, 'sync_error' => $error])->syncOriginal();
    }
}
