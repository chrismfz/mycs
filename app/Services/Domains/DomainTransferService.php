<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Services\Domains\Concerns\GuardsRegistrarWrites;
use RuntimeException;

/**
 * THE one path to registrar transfers (Πυλώνας A / A3c, §6.3) — the View
 * «Μεταφορά στον registrar» (inbound) and «Κωδικός EPP» (outbound aid) go
 * through here; nothing else may call DomainRegistrar::transferIn()/
 * getEppCode().
 *
 * INBOUND is async by nature: this starts the transfer (REAL MONEY — gTLD
 * transfers charge a renewal year) and the row stays PendingTransfer; the
 * nightly sync drives the §6.3 machine (completed → Active + expiry, failed
 * (FAI) → sync_error ⚠ for the operator, empty → keep polling). The adopt
 * probe is UNCONDITIONAL — an in-progress or completed transfer (started here
 * OR at the registrar panel) is adopted, never re-paid. OUTBOUND stays
 * operator-gated v1: the EPP code retrieval is audit-logged WITHOUT the code
 * (it enables the domain's departure).
 */
class DomainTransferService
{
    use GuardsRegistrarWrites;

    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
    ) {}

    public function transferIn(Domain $domain, string $authCode): DomainRegistrarLog
    {
        $connection = $domain->effectiveRegistrarConnection();
        // NEVER the auth code in the request — a bearer credential for the name.
        $log = $this->writeLogger($domain, $connection, 'transfer_in', ['fqdn' => $domain->fqdn]);

        if ($domain->trashed()) {
            $this->refuseWrite($log, 'Το domain είναι διαγραμμένο — δεν μεταφέρεται.');
        }
        if ($domain->status !== DomainStatus::PendingTransfer) {
            $this->refuseWrite($log, "Το {$domain->fqdn} δεν είναι σε κατάσταση «Εκκρεμεί μεταφορά» — βάλτε το πρώτα σε αυτήν (νέα εισερχόμενη μεταφορά).");
        }
        $adapter = $this->resolveWriteAdapter($connection, $log, 'Ο registrar είναι «manual» — κάντε τη μεταφορά στο portal του registrar.');
        $domain->loadMissing(['contacts', 'nameservers']);
        $registrant = $domain->contacts->firstWhere('type', 'registrant');
        if ($registrant === null || trim((string) $registrant->email) === '') {
            $this->refuseWrite($log, "Το {$domain->fqdn} χρειάζεται επαφή registrant με email πριν τη μεταφορά (καρτέλα «Επαφές»).");
        }

        return $this->withRegistrarLock(
            $domain,
            'transfer',
            $log,
            "Άλλη μεταφορά του {$domain->fqdn} είναι ήδη σε εξέλιξη — ΜΗΝ ξαναζητήσετε· δείτε το ιστορικό API σε λίγο.",
            function () use ($domain, $connection, $adapter, $authCode, $log): DomainRegistrarLog {
                $credentials = $this->factory->credentialsFor($connection);

                // Adopt-on-retry, UNCONDITIONAL: probe the account FIRST — a
                // transfer may have started outside ekdosi (the panel) or via
                // a timed-out POST that charged. A live record → adopt, never
                // a second charged request. Tombstone/not-found → proceed.
                try {
                    $probe = $adapter->syncDomain($domain, $credentials);
                    if (! $probe->deadRecord && $probe->status !== DomainStatus::Deleted) {
                        $this->sync->apply($domain, $probe);
                        $this->sync->persistHandles($domain, $probe->contactHandles);

                        return $log(DomainRegistrarLog::STATUS_ADOPTED, [
                            'registrar_expiry' => $probe->expiresAt,
                            'raw_status' => $probe->rawStatus,
                        ], null);
                    }
                    // tombstone → the earlier request died; start fresh below
                } catch (DomainNotFoundAtRegistrar) {
                    // not in the account yet — start (or restart) the transfer
                } catch (\Throwable $e) {
                    $log(DomainRegistrarLog::STATUS_FAILED, null, 'Έλεγχος λογαριασμού (probe) απέτυχε: '.$e->getMessage());

                    throw new RuntimeException("Η μεταφορά του {$domain->fqdn} ΔΕΝ ξεκίνησε — ο έλεγχος του λογαριασμού απέτυχε: ".$e->getMessage());
                }

                try {
                    $result = $adapter->transferIn($domain, $authCode, $credentials);
                } catch (\Throwable $e) {
                    // The auth code is a bearer credential — SCRUB it from any
                    // registrar echo before the message reaches a log row, the
                    // operator notification, or an exception trace.
                    $message = str_replace([$authCode, trim($authCode)], '«κωδικός EPP»', $e->getMessage());
                    $log(DomainRegistrarLog::STATUS_FAILED, null, $message);

                    throw new RuntimeException($message, previous: null);
                }
                $this->sync->apply($domain, $result);
                $this->sync->persistHandles($domain, $result->contactHandles);

                return $log(DomainRegistrarLog::STATUS_OK, [
                    'registrar_domain_id' => $result->registrarDomainId,
                    'raw_status' => $result->rawStatus,
                ], null);
            },
        );
    }

    /**
     * The transfer-OUT aid: retrieve (never store) the EPP/auth code. The
     * audit row records only THAT it was retrieved — the code itself enables
     * the domain's departure and must never sit in a table.
     */
    public function eppCode(Domain $domain): string
    {
        $connection = $domain->effectiveRegistrarConnection();
        // The log comes FIRST — even a config-shaped refusal on the most
        // credential-shaped read leaves its audit row (A3d gate r1).
        $log = $this->writeLogger($domain, $connection, 'epp_code', ['fqdn' => $domain->fqdn]);
        if ($domain->trashed()) {
            $this->refuseWrite($log, 'Το domain είναι διαγραμμένο — δεν ανακτάται κωδικός EPP.');
        }
        $adapter = $this->resolveWriteAdapter($connection, $log, 'Ο registrar είναι «manual» — πάρτε τον κωδικό EPP από το portal του.');

        // The EPP code is the credential that transfers the name AWAY — the
        // cross-tenant guard here matters MORE than anywhere (worst flavor of
        // the shared-account class).

        try {
            $code = $adapter->getEppCode($domain, $this->factory->credentialsFor($connection));
        } catch (\Throwable $e) {
            $log(DomainRegistrarLog::STATUS_FAILED, null, $e->getMessage());

            throw $e;
        }
        if ($code === null) {
            // The retrieval DID reach the registrar — it must leave an audit
            // row like every other leg, even though no code came back.
            $this->refuseWrite($log, "Ο registrar δεν επέστρεψε κωδικό EPP για το {$domain->fqdn}.");
        }
        $log(DomainRegistrarLog::STATUS_OK, ['retrieved' => true], null); // deliberately NOT the code

        return $code;
    }
}
