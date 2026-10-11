<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies one registrar pull to one domain (Πυλώνας A / A2b) — shared by the
 * nightly `domains:sync` and the per-domain «Συγχρονισμός» action. The write
 * discipline mirrors the myDATA cache columns: expiry/status/NS/registrar-id
 * are REGISTRAR truth, last_synced_at/sync_error are the sync bookkeeping —
 * failures are RECORDED on the row (and rethrown to the caller for its own
 * reporting), never swallowed into silence.
 */
class DomainSyncService
{
    public function __construct(private readonly DomainRegistrarFactory $factory) {}

    /**
     * The connection whose adapter serves this domain — delegates to THE one
     * routing source (Domain::effectiveRegistrarConnection), so UI and sync
     * can never disagree about which registrar handles a domain.
     */
    public function connectionFor(Domain $domain): ?DomainRegistrarConnection
    {
        return $domain->effectiveRegistrarConnection();
    }

    /**
     * Can this domain be synced at all? Routed to a USABLE (active, non-off)
     * non-manual connection AND not in an OPERATOR-terminal status (cancelled /
     * transferred_away — see DomainStatus::blocksSync; a registrar-set Deleted
     * keeps syncing so a redemption restore is picked up).
     */
    public function isSyncable(Domain $domain): bool
    {
        $connection = $this->connectionFor($domain);

        return !$domain->trashed() // a tombstone is never rewritten (parity with the nightly command)
            && $connection !== null
            && $connection->isUsable()
            && $this->factory->for($connection)->key() !== 'manual'
            && !($domain->status instanceof DomainStatus && $domain->status->blocksSync());
    }

    /**
     * Persist registrar contact handles onto the domain's contact rows — ONE
     * home (register/transfer/import all reuse it): a handle we know must
     * never be re-created as a duplicate OP customer (orphan personal data).
     *
     * @param  array<string, string>  $handles  contact type → handle
     */
    public function persistHandles(Domain $domain, array $handles): void
    {
        if ($handles === []) {
            return;
        }
        $domain->loadMissing('contacts');
        foreach ($domain->contacts as $contact) {
            $handle = $handles[$contact->type] ?? null;
            if ($handle !== null && $contact->registrar_contact_handle !== $handle) {
                $contact->forceFill(['registrar_contact_handle' => $handle])->save();
            }
        }
    }

    /**
     * Pull + apply. Throws on failure AFTER stamping sync_error on the row.
     */
    public function sync(Domain $domain): DomainSyncResult
    {
        $connection = $this->connectionFor($domain);
        if ($connection === null) {
            throw new DomainRegistrarNotConfigured('Το domain δεν δρομολογείται σε καμία σύνδεση registrar (ούτε μέσω TLD).');
        }

        $adapter = $this->factory->for($connection);
        try {
            $result = $adapter->syncDomain($domain, $this->factory->credentialsFor($connection));
        } catch (\Throwable $e) {
            $domain->forceFill([
                'last_synced_at' => Carbon::now(),
                'sync_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }

        $this->apply($domain, $result);

        return $result;
    }

    /**
     * Apply one registrar-truth result to the row — PUBLIC because the A2c
     * registrar-first import reuses it verbatim (id adoption, frozen-status
     * guard, expired derivation, atomic NS snapshot): import and sync must
     * never disagree about how registrar truth lands.
     */
    public function apply(Domain $domain, DomainSyncResult $result): void
    {
        $updates = [
            'last_synced_at' => Carbon::now(),
            'sync_error' => null,
        ];
        // §6.3: a PENDING request whose registrar record turned tombstone
        // (e.g. Openprovider FAI) means the transfer/registration FAILED at
        // the registry — surface it on the row (the ⚠ in the View); the
        // status stays pending for the operator's next move. Gated on an
        // ACTUAL request attempt in the API history: a lingering tombstone
        // from the name's previous life must not stamp «η αίτηση απέτυχε»
        // on a pending row that never asked anything.
        if ($result->deadRecord
            && in_array($domain->status, [DomainStatus::PendingTransfer, DomainStatus::PendingRegister], true)
            && DomainRegistrarLog::query()
                ->where('domain_id', $domain->id)
                ->whereIn('action', ['register', 'transfer_in'])
                ->exists()) {
            $updates['sync_error'] = 'Η αίτηση (μεταφορά/καταχώρηση) απέτυχε στον registrar'
                . ($result->rawStatus !== null ? ' (κατάσταση ' . $result->rawStatus . ')' : '')
                . ' — χειριστείτε το από το domain.';
        }
        if ($result->expiresAt !== null) {
            $updates['expires_at'] = $result->expiresAt;
        }
        // A3d mirrors follow REGISTRAR truth wherever it's reported — a lock/
        // privacy toggle at the registrar panel must not leave the local
        // flags wrong forever (null = not reported → keep the local value).
        if ($result->transferLock !== null) {
            $updates['transfer_lock'] = $result->transferLock;
        }
        if ($result->whoisPrivacy !== null) {
            $updates['whois_privacy'] = $result->whoisPrivacy;
        }
        // The by-name resolve is authoritative for THIS fqdn — adopt the id it
        // reported even over a stale stored one (else every future run repeats
        // the failing by-id call); keep the old value in module_meta for audit.
        if ($result->registrarDomainId !== null && $result->registrarDomainId !== $domain->registrar_domain_id) {
            if ($domain->registrar_domain_id !== null && $domain->registrar_domain_id !== '') {
                $meta = $domain->module_meta ?? [];
                $meta['previous_registrar_domain_id'] = $domain->registrar_domain_id;
                $updates['module_meta'] = $meta;
            }
            $updates['registrar_domain_id'] = $result->registrarDomainId;
        }
        // The registrar may only PROMOTE our view — never overwrite an
        // OPERATOR-terminal status (cancelled/transferred_away; a registrar-set
        // Deleted may be promoted back, e.g. redemption restore → ACT).
        $frozen = $domain->status instanceof DomainStatus && $domain->status->blocksSync();
        $newStatus = $result->status;
        // OP's REQ/PEN covers BOTH create and transfer requests — a pending
        // TRANSFER row must never be demoted to «Εκκρεμεί καταχώρηση»: the
        // in-flight request IS the transfer (§6.3).
        if ($newStatus === DomainStatus::PendingRegister && $domain->status === DomainStatus::PendingTransfer) {
            $newStatus = null;
        }
        // Nor may a TOMBSTONE (DEL/FAI of the name's previous life) flip a
        // pending row to Deleted — that would hide the register/transfer
        // buttons and re-wedge every night; the sync_error above (when a
        // request was actually made) is the operator's signal instead.
        if ($newStatus === DomainStatus::Deleted
            && in_array($domain->status, [DomainStatus::PendingTransfer, DomainStatus::PendingRegister], true)) {
            $newStatus = null;
        }
        // Openprovider keeps reporting ACT past the expiry date — derive the
        // documented active→expired transition from the REGISTRAR expiry so a
        // lapsed domain never sits in the «Ενεργά» tab (docs §6.5; the full
        // grace/redemption windows land with A3). Also covers an UNMAPPED
        // registrar status on a locally-Active row — the lapse must still land.
        $effectiveExpiry = $result->expiresAt ?? $domain->expires_at?->toDateString();
        $wouldBeActive = $newStatus === DomainStatus::Active
            || ($newStatus === null && $domain->status === DomainStatus::Active);
        if ($wouldBeActive
            && $effectiveExpiry !== null
            && Carbon::parse($effectiveExpiry)->lt(Carbon::today())) {
            $newStatus = DomainStatus::Expired;
        }
        if ($newStatus !== null && !$frozen) {
            $updates['status'] = $newStatus;
        }
        if ($result->rawStatus !== null) {
            // Merge with any meta the id-adoption block above already staged.
            $meta = $updates['module_meta'] ?? ($domain->module_meta ?? []);
            $meta['registrar_status'] = $result->rawStatus;
            $updates['module_meta'] = $meta;
        }

        // One atomic apply: the row update + the NS snapshot replace commit (or
        // fail) together — a mid-apply crash can't wipe the delegation record.
        DB::transaction(function () use ($domain, $result, $updates): void {
            // forceFill: the sync bookkeeping columns are deliberately not
            // fillable (the InvoiceBalance cache-column discipline).
            $domain->forceFill($updates)->save();

            // NS delegation snapshot: replace only when the registrar reported
            // any (an empty answer must not wipe a manual record).
            if ($result->nameservers !== []) {
                $domain->nameservers()->delete();
                foreach ($result->nameservers as $i => $host) {
                    $domain->nameservers()->create([
                        'host' => $host,
                        'sort_order' => $i,
                    ]);
                }
            }
        });
    }
}
