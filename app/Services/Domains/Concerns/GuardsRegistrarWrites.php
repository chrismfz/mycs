<?php

namespace App\Services\Domains\Concerns;

use App\Contracts\DomainRegistrar;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRenewalInProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The ONE registrar-write skeleton (Πυλώνας A / A3d, δεσμευτικό από το A3c
 * review): audit logger, log-then-throw refusal, usable/non-manual adapter
 * resolution, the cross-tenant claimedElsewhere sweep and the per-domain
 * lock-with-safe-release. Renewal/registration/transfer/management all build
 * on these — the A3c copy-drift (three hand-copied variants, three dropped
 * guards) must be structurally impossible for the next write.
 *
 * Host services provide `$this->factory` (DomainRegistrarFactory).
 */
trait GuardsRegistrarWrites
{
    /**
     * The audit logger for one attempt — every leg (ok/adopted/failed,
     * refusals included) goes through it. `$request` must NEVER carry a
     * credential (auth codes, EPP codes, passwords).
     */
    private function writeLogger(Domain $domain, ?DomainRegistrarConnection $connection, string $action, array $request, ?int $invoiceId = null): callable
    {
        return fn (string $status, ?array $response, ?string $error) => DomainRegistrarLog::create([
            'domain_id' => $domain->id,
            'registrar_connection_id' => $connection?->id,
            'invoice_id' => $invoiceId,
            'action' => $action,
            'status' => $status,
            'request' => $request,
            'response' => $response,
            'error' => $error,
        ]);
    }

    /** Log-then-throw refusal — a refused attempt always leaves its audit row. */
    private function refuseWrite(callable $log, string $message): never
    {
        $log(DomainRegistrarLog::STATUS_FAILED, null, $message);

        throw new RuntimeException($message);
    }

    /**
     * Usable, non-manual adapter or a LOGGED typed refusal — the two
     * config-shaped failure modes every write shares.
     */
    private function resolveWriteAdapter(?DomainRegistrarConnection $connection, callable $log, string $manualAdvice): DomainRegistrar
    {
        if ($connection === null || !$connection->isUsable()) {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Καμία ενεργή σύνδεση registrar.');

            throw new DomainRegistrarNotConfigured('Το domain δεν δρομολογείται σε ενεργή σύνδεση registrar.');
        }
        $adapter = $this->factory->for($connection);
        if ($adapter->key() === 'manual') {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Ο registrar είναι «manual».');

            throw new DomainRegistrarNotConfigured($manualAdvice);
        }

        return $adapter;
    }

    /**
     * ONE write at a time per (operation × domain): refusal is the typed
     * DomainRenewalInProgress («ΜΗΝ ξαναζητήσετε» advice at the call sites),
     * and a lock-release hiccup NEVER replaces the body's real outcome.
     *
     * @template T
     *
     * @param  \Closure(): T  $body
     * @return T
     */
    private function withRegistrarLock(Domain $domain, string $operation, callable $log, string $busyMessage, \Closure $body): mixed
    {
        $lock = Cache::lock("domains:{$operation}:" . $domain->id, 300);
        if (!$lock->get()) {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Κλειδωμένο — άλλη ενέργεια σε εξέλιξη.');

            throw new DomainRenewalInProgress($busyMessage);
        }

        try {
            return $body();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                Log::warning("domains.{$operation}.lock_release_failed", [
                    'domain_id' => $domain->id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
