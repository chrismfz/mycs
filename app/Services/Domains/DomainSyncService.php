<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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

    /**
     * It is synced: routed to an active connection with an API, not ended by staff, not deleted; and not an order we
     * have not sent to its registrar yet (D8b, adversarial ADV-2: a transfer ordered, unpaid, of a name already in our
     * registrar account would otherwise turn Active for whoever ordered it). We send it after its payment (D8b-2), and
     * mark it then.
     */
    public function isSyncable(Domain $domain): bool
    {
        $connection = $domain->effectiveRegistrarConnection();
        $unsent = $domain->order_id !== null && in_array($domain->status, self::PENDING, true) && empty($domain->meta['sent_at']);

        return $connection instanceof DomainRegistrarConnection && $connection->isUsable() && !$unsent
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
    public function sync(Domain $domain, bool $write = true, bool $lock = true): array
    {
        // Under the lock the registrar writes take (D-17, adversarial F7): a sync read before a write must not land after
        // it. A write under way is not the domain's error; the next sync reads it.
        $held = $write && $lock ? Cache::lock("domain-registrar:{$domain->id}", 600) : null;
        if ($held !== null && !$held->get()) {
            throw new RuntimeException("A change to {$domain->fqdn} at the registrar is under way: synced later.");
        }
        try {
            try {
                $result = $this->read($domain);
                $verified = $this->verifiedHandles($domain, $result);
            } catch (Throwable $e) {
                if ($write) {
                    $this->bookkeep($domain, mb_substr($e->getMessage(), 0, 1000));
                    if ($e instanceof DomainNotFoundAtRegistrar) {
                        $this->notOurs($domain, $e);
                    }
                }

                throw $e;
            }
            $changes = $this->plan($domain, $result, $verified);
            if ($write) {
                $this->apply($domain, $result, $changes);
            }

            return $changes;
        } finally {
            $held?->release();
        }
    }

    /**
     * The registrar's handles that are the role's contact here (adversarial F2, F8): only for a role with a contact and
     * no handle, and only when the registrar's record for that handle has the contact's email. Anything else would pair
     * a contact here with another person there.
     *
     * @return array<string, string> role => handle
     */
    private function verifiedHandles(Domain $domain, DomainSyncResult $result): array
    {
        $verified = [];
        $connection = $domain->effectiveRegistrarConnection();
        foreach (Domain::ROLES as $role) {
            $theirs = $result->contactHandles[$role] ?? null;
            $contact = $domain->{"{$role}Contact"};
            if ($theirs === null || $contact === null || trim((string) $domain->{"{$role}_handle"}) !== '' || !$connection instanceof DomainRegistrarConnection) {
                continue;
            }
            $record = $this->factory->for($connection)->getContact($theirs, $this->factory->credentialsFor($connection));
            $email = mb_strtolower(trim((string) $contact->email));
            if ($record !== null && $email !== '' && mb_strtolower(trim((string) $record->email)) === $email) {
                $verified[$role] = $theirs;
            }
        }

        return $verified;
    }

    /**
     * The registrar's answer to a write (D4b: nameservers, lock, privacy, contacts) applied as a sync's would be.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function applyResult(Domain $domain, DomainSyncResult $result): array
    {
        if ($result->readNothing()) {
            return []; // not «synced now»: last_synced_at and sync_error stay as they are
        }
        $changes = $this->plan($domain, $result);
        $this->apply($domain, $result, $changes);

        return $changes;
    }

    /**
     * What the registrar's answer would change; `_warning` carries what is not applied for staff to look at.
     *
     * @param  array<string, string>  $verified  the registrar's handles checked to be the role's contact (role => handle)
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function plan(Domain $domain, DomainSyncResult $result, array $verified = []): array
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
        // Only when we have none (review 8): an imported date (D6) stays; the registrar's is in its details anyway.
        if ($domain->registered_at === null) {
            $set('registered_at', null, $result->registeredAt);
        }
        $set('registrar_domain_id', $domain->registrar_domain_id, $result->registrarDomainId);
        // When its grace and redemption end, as the registrar says while it is in them (D5a; never onto a pending order:
        // review 2); active again, they are gone (review 5).
        if (!$pending) {
            $set('grace_ends_on', $domain->grace_ends_on?->toDateString(), $result->graceEndsOn);
            $set('redemption_ends_on', $domain->redemption_ends_on?->toDateString(), $result->redemptionEndsOn);
        }
        if ($result->status === DomainStatus::Active) {
            foreach (['grace_ends_on', 'redemption_ends_on'] as $field) {
                if ($domain->{$field} !== null) {
                    $changes[$field] = [$domain->{$field}->toDateString(), null];
                }
            }
        }
        // A role's handle pairs it with its contact (ensureHandles reuses it): taken from the registrar only for a role
        // with a contact and no handle yet (reviews 1, 2: a contact-less role would keep the registrant's handle after
        // the registrant changes). Another handle at the registrar is shown to staff, never paired silently. The
        // registrar's handles are all in its details.
        foreach (Domain::ROLES as $role) {
            $theirs = $result->contactHandles[$role] ?? null;
            $ours = $domain->{"{$role}_handle"};
            if ($theirs === null || $domain->{"{$role}_contact_id"} === null) {
                continue;
            }
            if ($ours === null || $ours === '') {
                if (isset($verified[$role])) {
                    $set("{$role}_handle", null, $verified[$role]);
                } else {
                    $warning = "the registrar's {$role} is {$theirs}, not this domain's {$role} contact here: not linked, check it";
                    $changes['_warning'] = [null, isset($changes['_warning']) ? $changes['_warning'][1] . '; ' . $warning : $warning];
                }
            } elseif ($ours !== $theirs) {
                $warning = "the registrar's {$role} handle is {$theirs}, ours {$ours}: check the {$role} contact";
                $changes['_warning'] = [null, isset($changes['_warning']) ? $changes['_warning'][1] . '; ' . $warning : $warning];
            }
        }
        // A domain that came in before its TLD (adopted, imported) is linked once the TLD is here.
        if ($domain->domain_tld_id === null) {
            $set('domain_tld_id', null, DomainTld::query()->where('tld', $domain->tld)->value('id'));
        }
        $set('transfer_lock', $domain->transfer_lock, $result->transferLock);
        $set('id_protection', $domain->id_protection, $result->whoisPrivacy);

        $status = $result->status;
        $expiry = $changes['expires_at'][1] ?? $now;
        // A pending order is moved only by its own activation: never by the past of its name (a deleted or quarantined
        // record of the same name, review 2).
        if ($status !== DomainStatus::Active && $status !== null && in_array($domain->status, self::PENDING, true)) {
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
        unset($meta['not_ours_since']); // ours again: a «not ours» seen before was not a transfer away
        if ($result->rawStatus !== null) {
            $meta['registrar_status'] = $result->rawStatus;
        }
        if ($result->contactHandles !== []) {
            // Per role, for staff to see and link a role this domain has not linked yet (D4c); whatever else is reported.
            $meta['registrar_handles'] = $result->contactHandles;
        }
        if ($result->details !== []) {
            // What staff see without the registrar's panel (D4b), its handles per role included.
            $meta['registrar'] = $result->details + ($result->contactHandles !== []
                ? ['handles' => implode(', ', array_map(fn ($role, $handle) => "{$role} {$handle}", array_keys($result->contactHandles), $result->contactHandles))]
                : []);
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
        // An ordered domain out of pending (a transfer completed, a registration synced late): its order follows (code
        // review 6 of #180).
        if (isset($changes['status']) && $domain->order_id !== null && in_array(DomainStatus::tryFrom((string) $changes['status'][0]), self::PENDING, true)) {
            $domain->order?->refreshStatus();
        }
    }

    /** When it was read and what went wrong: bookkeeping, not a change of the domain (no audit, no updated_at). */
    /** Seen «not ours» this long, twice at least, before it is taken as transferred away (never on one answer). */
    public const AWAY_AFTER_SECONDS = 3600;

    /**
     * A domain of ours its registrar no longer holds (transferred to another: the .gr registry's 2201, the account not
     * listing it): first noted; seen so again an hour later, it is transferred away (D-18: kept with its history; billing
     * and sync stop, auto-renew off). Never on one answer (a wrong setting, a moment's failure); never a pending order
     * (its fulfilment decides) or one already ended; never a name that no longer exists (2303: gone, not moved). An open
     * renewal invoice is left to staff, noted.
     */
    private function notOurs(Domain $domain, DomainNotFoundAtRegistrar $e): void
    {
        $domain->refresh();
        if ($e->elsewhere === false || !in_array($domain->status, [DomainStatus::Active, ...self::LAPSED], true)) {
            return;
        }
        $meta = $domain->meta ?? [];
        $since = is_string($meta['not_ours_since'] ?? null) ? Carbon::parse($meta['not_ours_since']) : null;
        if ($since === null || $since->greaterThan(now())) {
            $meta['not_ours_since'] = now()->toIso8601String();
            $domain->forceFill(['meta' => $meta])->saveQuietly();

            return;
        }
        if ($since->diffInSeconds(now()) < self::AWAY_AFTER_SECONDS) {
            return;
        }
        unset($meta['not_ours_since']);
        $open = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_UNPAID))->pluck('invoice_id')->unique()->values()->all();
        $meta['transferred_away'] = array_filter(['at' => now()->toIso8601String(), 'seen_since' => $since->toIso8601String(),
            'registrar' => mb_substr($e->getMessage(), 0, 300), 'open_invoices' => $open ?: null]);
        $domain->forceFill(['status' => DomainStatus::TransferredAway, 'auto_renew' => false, 'meta' => $meta])->save(); // audited
    }

    private function bookkeep(Domain $domain, ?string $error): void
    {
        $at = Carbon::now();
        DB::table('domains')->where('id', $domain->id)->update(['last_synced_at' => $at, 'sync_error' => $error]);
        $domain->forceFill(['last_synced_at' => $at, 'sync_error' => $error])->syncOriginal();
    }
}
