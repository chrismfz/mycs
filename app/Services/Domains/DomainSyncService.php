<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\ModuleAction;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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
                        $this->registrationGone($domain, $e);
                    }
                }

                throw $e;
            }
            $changes = $this->plan($domain, $result, $verified);
            if ($write) {
                // As stored now, under the lock: not the caller's copy, maybe read before another sync (code review 3 of #293).
                $before = DB::table('domains')->where('id', $domain->id)->first(['status', 'registrar_domain_id']);
                $this->apply($domain, $result, $changes);
                if ($before !== null) {
                    $this->transferOutcome($domain, DomainStatus::tryFrom((string) $before->status), $before->registrar_domain_id, $result);
                }
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
            try {
                $record = $this->factory->for($connection)->getContact($theirs, $this->factory->credentialsFor($connection));
            } catch (Throwable) {
                continue; // only an aid to link it: one not read is not linked, the sync goes on (review 3 of #211)
            }
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
            if ($now !== null && $result->expiresAt < $now && $this->movesBack($domain, $result->expiresAt, $now)) {
                $set('expires_at', $now, $result->expiresAt); // the day the name goes there (CNIC: #297)
            } elseif ($now !== null && $result->expiresAt < $now) {
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
        // Never a tombstone's id onto a pending order (code review 2 of #293): its own request's id is read again next time.
        if (!($result->deadRecord && in_array($domain->status, self::PENDING, true))) {
            $set('registrar_domain_id', $domain->registrar_domain_id, $result->registrarDomainId);
        }
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
        unset($meta['not_ours']); // ours again: a «not ours» seen before was not a transfer away
        // The connection that read it as ours: only that one can later say it left (ADV-3 of #203).
        $meta['read_by'] = $domain->effectiveRegistrarConnection()?->id;
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

    /**
     * A transfer's end, as its registrar shows it (mail check of 2026-10-21, ROADMAP 6γ), for one sent from here only
     * (meta.sent_at: never one imported, or never sent: reviews of #293):
     * - done (pending → active): the customer is told, when its order is still paid (one refunded while it was sent is
     *   staff's: their row stays open); staff's rows are never closed from here;
     * - failed: its own record, by the id stored before this sync (never a tombstone found by name), dead (Openprovider's
     *   FAI). It stays pending; a Pending Module Action (which mails staff) and the customer told what to check, once per
     *   request id: Retry sends it again (DomainOrderFulfilment), and a later request that fails is told again.
     */
    private function transferOutcome(Domain $domain, ?DomainStatus $was, ?string $storedId, DomainSyncResult $result): void
    {
        if ($was !== DomainStatus::PendingTransfer) {
            return;
        }
        $domain->refresh();
        $meta = $domain->meta ?? [];
        if (empty($meta['sent_at'])) {
            return;
        }
        try {
            if ($domain->status === DomainStatus::Active) {
                unset($meta['transfer_failed']);
                $domain->forceFill(['meta' => $meta])->saveQuietly();
                $line = DomainOrderFulfilment::lineOf($domain);
                if ($line === null || DomainOrderFulfilment::paid($domain, $line)) {
                    NotificationHelper::domainNotification($domain->client, $domain, 'domain_transferred', [
                        'fqdn' => $domain->fqdn,
                        'expires' => $domain->expires_at?->format('d/m/Y'),
                        'nameservers' => $domain->nameservers()->pluck('host')->implode(', '),
                    ]);
                }

                return;
            }
            $ours = $storedId !== null && $storedId !== '' && $result->registrarDomainId === $storedId;
            if ($domain->status !== DomainStatus::PendingTransfer || !$result->deadRecord || !$ours
                || ($meta['transfer_failed']['id'] ?? null) === $storedId) {
                return;
            }
            DB::transaction(function () use ($domain, $meta, $storedId, $result) {
                $meta['transfer_failed'] = ['at' => now()->toIso8601String(), 'id' => $storedId, 'status' => $result->rawStatus];
                $domain->forceFill(['meta' => $meta])->save();
                ModuleAction::domainFailed($domain, DomainOrderFulfilment::lineOf($domain), $domain->effectiveRegistrarConnection()?->registrar,
                    new RuntimeException("The registrar reports the transfer of {$domain->fqdn} failed (status {$result->rawStatus}): a wrong EPP code, a lock or a refusal at the losing registrar. Ask the customer, then Retry to send it again, or cancel and refund it."),
                    ModuleAction::DOMAIN_TRANSFER);
            });
            NotificationHelper::domainNotification($domain->client, $domain, 'domain_transfer_failed', ['fqdn' => $domain->fqdn, 'reason' => null]);
        } catch (Throwable $e) {
            report($e); // the sync is done: what is told is not its result
        }
    }

    /** Back to its registrar's expiry by at most this much (a garbled date never takes a year off: review F2). */
    public const MAX_BACK_DAYS = 400;

    /** Its registrar's expiry is when the name goes there (DomainRegistrarCapabilities::expiryIsWhenItGoes), not far back. */
    private function movesBack(Domain $domain, string $theirs, string $ours): bool
    {
        $connection = $domain->effectiveRegistrarConnection();
        try {
            $goes = $connection !== null && $this->factory->for($connection)->capabilities()->expiryIsWhenItGoes;
        } catch (Throwable) {
            return false;
        }

        return $goes && $theirs >= '2000-01-01' && Carbon::parse($theirs)->diffInDays(Carbon::parse($ours)) <= self::MAX_BACK_DAYS;
    }

    /** Seen «not ours» this long apart (twice at least, the same connection and mode) before it is marked transferred away. */
    public const AWAY_AFTER_SECONDS = 3600;

    /**
     * A whole connection saying «not ours» within a day is a setting, not transfers: none is marked when more than
     * AWAY_BREAKER_SHARE of its domains read within that day said so (owner 2026-10-18: half; a reseller leaving with 20 or 600 of 1,400 is
     * marked by itself, 200 of 200 is a wrong setting), and at least AWAY_BREAKER (a small connection's few never stop).
     */
    public const AWAY_BREAKER = 10;

    public const AWAY_BREAKER_SHARE = 0.5;

    /**
     * Its registrar says it is not ours (moved to another registrar: the .gr registry's 2201, the account no longer listing
     * it). As WHMCS's sync (owner 2026-10-18), it is marked transferred away by itself, safely (reviews of #203):
     * - seen so twice, an hour apart at least, by the same production connection and mode, the one that last read it as
     *   ours (another connection, sandbox, or a note older than a day: never);
     * - not when a large share of that connection's domains said so within a day (AWAY_BREAKER, AWAY_BREAKER_SHARE): a
     *   wrong setting (credentials, a TLD routed elsewhere) hits them all, transfers a few: staff decide (the badge);
     * - an active one only (a lapsed one missing is more likely gone: noted); never a pending order, one ended, or a name
     *   that no longer exists (2303) or a contradicting setting (elsewhere false). Read as ours again, the note goes.
     * Marked: auto-renew off, sync and billing stop; staff can resume it; its open invoices are cancelled a day later.
     */
    private function notOurs(Domain $domain, DomainNotFoundAtRegistrar $e): void
    {
        $domain->refresh();
        if ($e->elsewhere === false || !in_array($domain->status, [DomainStatus::Active, ...self::LAPSED], true)) {
            return;
        }
        $connection = $domain->effectiveRegistrarConnection();
        $meta = $domain->meta ?? [];
        $was = is_array($meta['not_ours'] ?? null) ? $meta['not_ours'] : [];
        // The same connection and mode, and a note of the last day (ADV-6: one answer weeks ago is no first sighting).
        $same = ($was['connection_id'] ?? null) === $connection?->id && ($was['mode'] ?? null) === $connection?->mode
            && is_string($was['last'] ?? null) && Carbon::parse($was['last'])->greaterThanOrEqualTo(now()->subDay());
        $since = $same && is_string($was['since'] ?? null) ? Carbon::parse($was['since']) : now();
        if ($since->greaterThan(now())) {
            $since = now(); // a clock moved back
        }
        $meta['not_ours'] = ['since' => $since->toIso8601String(), 'last' => now()->toIso8601String(), 'connection_id' => $connection?->id,
            'mode' => $connection?->mode, 'elsewhere' => $e->elsewhere, 'said' => mb_substr($e->getMessage(), 0, 300)];
        $domain->forceFill(['meta' => $meta])->saveQuietly();
        // Active only: a lapsed one missing is more likely gone than moved (ADV-8): noted, staff decide.
        if ($domain->status !== DomainStatus::Active || $connection === null || $connection->mode !== 'production'
            || $since->diffInSeconds(now()) < self::AWAY_AFTER_SECONDS) {
            return;
        }
        // Said by a connection other than the one that read it as ours (a TLD routed elsewhere): noted only (ADV-3).
        if (isset($meta['read_by']) && $meta['read_by'] !== $connection->id) {
            return;
        }
        $live = Domain::query()->whereNotIn('status', DomainStatus::terminalValues());
        // Among those of the connection read within the day (review 1 of #204: a batch reads 200 of 1,400; half of all
        // would never be reached by a wrong setting, half of those read is).
        $all = (clone $live)->where(fn ($q) => $q->where('registrar_connection_id', $connection->id)
            ->orWhere(fn ($t) => $t->whereNull('registrar_connection_id')->whereHas('domainTld', fn ($tld) => $tld->where('registrar_connection_id', $connection->id))))
            ->where('last_synced_at', '>=', now()->subDay())->count();
        $saying = (clone $live)->where('meta->not_ours->connection_id', $connection->id)
            ->where('meta->not_ours->last', '>=', now()->subDay()->toIso8601String())->count();
        if ($saying >= self::AWAY_BREAKER && $saying > $all * self::AWAY_BREAKER_SHARE) {
            return; // a large share of one connection at once: a setting, not transfers; staff's to look at
        }
        $audit = Config::get('audit.console');
        Config::set('audit.console', true); // audited from a queue worker too (review 6 of #203)
        try {
            app(DomainManagementService::class)->markTransferredAway($domain, null);
        } finally {
            Config::set('audit.console', $audit);
        }
    }

    /**
     * A registration sent and still pending whose name the registry no longer has (2303): refused (the .gr registry's
     * «Προς Έγκριση», the EETT saying no) or withdrawn. Told to staff as its order's Pending Module Action, to refund or
     * register again (G5b-3 of grepp); never one recalled here (it is cancelled already), never a wrong «Registrar id»
     * (code review 3 of #205: the registry gave its record). No longer sent (meta.registration_gone keeps when and why):
     * not read again, so neither opened again each sync nor after staff Close it (review 1, 4); Retry registers it again
     * (review 2).
     */
    private function registrationGone(Domain $domain, DomainNotFoundAtRegistrar $e): void
    {
        $domain->refresh();
        $meta = $domain->meta ?? [];
        // A sandbox's «no such name» says nothing of a real registration (ADV-5 of #205).
        if ($e->resultCode !== 2303 || $domain->status !== DomainStatus::PendingRegister || empty($meta['sent_at'])
            || !empty($meta['recalled_at']) || $domain->effectiveRegistrarConnection()?->mode !== 'production') {
            return;
        }
        $meta['registration_gone'] = ['at' => now()->toIso8601String(), 'sent_at' => $meta['sent_at'], 'said' => mb_substr($e->getMessage(), 0, 300)];
        unset($meta['sent_at'], $meta['not_ours']);
        $domain->forceFill(['meta' => $meta])->save();
        ModuleAction::domainFailed($domain, DomainOrderFulfilment::lineOf($domain), $domain->effectiveRegistrarConnection()?->registrar,
            new RuntimeException(__('domains.order.registration_gone', ['domain' => $domain->fqdn, 'said' => $e->getMessage()])), ModuleAction::DOMAIN_REGISTER);
    }

    /** When it was read and what went wrong: bookkeeping, not a change of the domain (no audit, no updated_at). */
    private function bookkeep(Domain $domain, ?string $error): void
    {
        $at = Carbon::now();
        DB::table('domains')->where('id', $domain->id)->update(['last_synced_at' => $at, 'sync_error' => $error]);
        $domain->forceFill(['last_synced_at' => $at, 'sync_error' => $error])->syncOriginal();
    }
}
