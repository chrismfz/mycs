<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Support\Domains\DomainChanges;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarTransactions;
use App\Support\Redact;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A paid renewal transferred to another registrar instead of renewed at its own (owner 2026-10-21: the WHMCS hook
 * DomainMigration, CNIC → Openprovider, as a setting of any connection: DomainRegistrarConnection::renewTransferTarget).
 * Its transfer adds the year paid for. Called by DomainRenewalService under the domain's lock, after it read the domain
 * at its registrar: active there, the period not covered, one year lacking, no charge unconfirmed.
 *
 * As the hook (its log 2026-02 … 10: 189 sent, 26 refused by the target): unlocked, its EPP code read (never stored), the
 * transfer asked of the target with the nameservers it has. Unlike the hook, the domain moves to the target only once the
 * target took the transfer: refused, it stays where it is, as it was (its contact handles too), and staff decide.
 * The contact handles are the source's: the target never gets them (emptied for its call, put back when it refuses).
 */
class RenewAsTransfer
{
    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
    ) {}

    /** Returns one of DomainRenewalService's outcomes: APPLIED (sent), FAILED (staff's). */
    public function run(Domain $domain, InvoiceItem $line, DomainRegistrarConnection $from, DomainRegistrarConnection $to, DomainSyncResult $now, ?Staff $staff): string
    {
        $code = '';
        // Staff's, and logged for the line as refused: the cron never starts it again on its own, only staff's Retry
        // (RAT-ADV-7: else every run unlocked and locked it at the source again). Never its EPP code in what is stored.
        $fail = function (string $why, bool $log = true) use ($domain, $line, $from, $to, $staff, &$code): string {
            $why = Redact::values(Redact::text($why), [$code]);
            if ($log) {
                DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $to->id, 'staff_id' => $staff?->id,
                    'invoice_id' => $line->invoice_id, 'action' => 'transfer', 'status' => DomainRegistrarLog::STATUS_FAILED,
                    'request' => ['years' => 1, 'line' => $line->id, 'renewal' => true, 'from' => $from->id, 'sent' => false],
                    'response' => ['refused' => true], 'error' => mb_substr($why, 0, 2000)]);
            }
            ModuleAction::domainFailed($domain, $line, $from->registrar, new RuntimeException($why));

            return DomainRenewalService::FAILED;
        };
        if (!$to->isUsable()) {
            return $fail("Its renewals are transferred to «{$to->label}», which is not usable (inactive or not configured): fix it, or renew it here by hand.");
        }
        try {
            $source = $this->factory->for($from);
            $sourceCredentials = $this->factory->credentialsFor($from);
            $target = $this->factory->for($to);
            $targetCredentials = $this->factory->credentialsFor($to);
        } catch (Throwable $e) {
            return $fail('The registrars could not be reached: ' . $e->getMessage());
        }
        if (!$target->capabilities()->supportsTransfer) {
            return $fail("«{$to->label}» takes no transfers through its API: renew it here by hand, or transfer it by hand.");
        }
        $nameservers = $domain->nameservers()->pluck('host')->all();
        if ($nameservers === []) {
            return $fail("{$domain->fqdn} has no nameservers here: the transfer would get the target's defaults. Set them, then Retry.");
        }

        // 1. Unlocked at its registrar (the hook's step 3): unless it is known unlocked (not read: unlocked all the same,
        // code review 6: a lock found days later by the registry is a failed transfer).
        $unlocked = false;
        if ($now->transferLock !== false) {
            try {
                $this->sync->applyResult($domain, $source->updateDomain($domain, new DomainChanges(transferLock: false), $sourceCredentials));
                $this->log($domain, $from, $staff, $line, 'unlock', DomainRegistrarLog::STATUS_OK);
                $unlocked = true;
            } catch (Throwable $e) {
                $this->log($domain, $from, $staff, $line, 'unlock', DomainRegistrarLog::STATUS_FAILED, $e->getMessage());

                return $fail("Its transfer to «{$to->label}» needs it unlocked, and the unlock failed: {$e->getMessage()}");
            }
        }
        // 2. Its EPP code (the hook's step 5): none, nothing changes (the hook's markPendingWithoutEpp = false).
        try {
            $code = trim((string) $source->getEppCode($domain, $sourceCredentials));
        } catch (Throwable $e) {
            $code = '';
            $why = $e->getMessage();
        }
        if ($code === '') {
            $this->relock($domain, $source, $sourceCredentials, $unlocked);

            return $fail("Its transfer to «{$to->label}» needs its EPP code, which its registrar did not give" . (isset($why) ? ": {$why}" : '') . '. Nothing was changed.');
        }

        // 3. The source's handles put aside (the target makes its own), then the target's call, logged pending before it
        // is asked (D-17); a failure putting them aside asked nothing (code review 8).
        try {
            $prior = $this->clearHandles($domain, $from, $to, $line);
        } catch (Throwable $e) {
            $this->relock($domain, $source, $sourceCredentials, $unlocked);

            return $fail('Its contacts could not be put aside for the transfer: ' . $e->getMessage());
        }
        $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $to->id,
            'staff_id' => $staff?->id, 'invoice_id' => $line->invoice_id, 'action' => 'transfer', 'status' => DomainRegistrarLog::STATUS_PENDING,
            'request' => ['years' => 1, 'line' => $line->id, 'renewal' => true, 'from' => $from->id, 'nameservers' => $nameservers]]);
        $transactions = app(RegistrarTransactions::class);
        $mark = $transactions->mark();
        try {
            $result = $target->transferIn($domain->refresh(), $code, $targetCredentials);
        } catch (RegistrarRefused|RegistrarNotAsked|DomainRegistrarNotConfigured $e) {
            // Refused: nothing moved. Back as it was (its handles), locked again; staff decide (renew here, or Retry).
            $message = Redact::values(Redact::text($e->getMessage()), [$code]);
            $log->update(['status' => DomainRegistrarLog::STATUS_FAILED, 'error' => mb_substr($message, 0, 2000),
                'response' => ['refused' => $e instanceof RegistrarRefused], 'transactions' => $transactions->since($mark)]);
            $this->restoreHandles($domain, $prior);
            $this->relock($domain, $source, $sourceCredentials, $unlocked);

            return $fail("«{$to->label}» refused the transfer that replaces its renewal: {$message}. It is still at «{$from->label}», as it was: renew it there by hand, or fix it and Retry.", log: false);
        } catch (Throwable $e) {
            // No clear answer: it may have started. Still the source's here, with its handles (the target's kept apart for
            // staff: RAT-ADV-3); pending, never asked again blindly.
            $message = Redact::values(Redact::text($e->getMessage()), [$code]);
            $log->update(['error' => mb_substr($message, 0, 2000), 'transactions' => $transactions->since($mark)]);
            $this->restoreHandles($domain, $prior, keepTarget: true);

            return $fail("The transfer to «{$to->label}» that replaces its renewal got no clear answer ({$message}): check «{$to->label}» before anything else; it may be under way.", log: false);
        }

        // 4. Taken by the target: the domain is the target's, pending its transfer; the paid line applied.
        DB::transaction(function () use ($domain, $line, $log, $result, $to, $transactions, $mark) {
            $log->update(['status' => DomainRegistrarLog::STATUS_OK, 'response' => ['status' => $result->rawStatus, 'id' => $result->registrarDomainId],
                'transactions' => $transactions->since($mark)]);
            $fresh = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $meta = $fresh->meta ?? [];
            $meta['sent_at'] = now()->toIso8601String();
            $meta['renew_transfer']['sent_at'] = now()->toIso8601String();
            unset($meta['transfer_failed']);
            $fresh->forceFill(['registrar_connection_id' => $to->id, 'status' => DomainStatus::PendingTransfer, 'meta' => $meta,
                'registrar_domain_id' => $result->registrarDomainId ?? $fresh->registrar_domain_id])->save();
            $line->forceFill(['applied_at' => now()])->save();
            ModuleAction::domainSucceeded($fresh, $line);
        });

        return DomainRenewalService::APPLIED;
    }

    /**
     * Its transfer reported failed by the target (DomainSyncService::transferOutcome): back at its source as it was (its
     * connection, handles, record id), Active; its line not applied, renewed at the source by the next run (meta
     * renew_here_line), never transferred again on its own; staff told (RAT-ADV-4, 5; code review 3).
     */
    public static function revert(Domain $domain, string $status): void
    {
        DB::transaction(function () use ($domain, $status) {
            $fresh = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $meta = $fresh->meta ?? [];
            $record = $meta['renew_transfer'] ?? null;
            if (!is_array($record) || !isset($record['from'], $record['line'])) {
                return;
            }
            $prior = (array) ($record['prior'] ?? []);
            $columns = ['registrar_connection_id' => $record['from'], 'status' => DomainStatus::Active,
                'registrar_domain_id' => $prior['registrar_domain_id'] ?? null];
            foreach ((array) ($prior['handles'] ?? []) as $role => $handle) {
                $columns["{$role}_handle"] = $handle;
            }
            unset($meta['renew_transfer'], $meta['sent_at'], $meta['transfer_failed'], $meta['contact_handles'], $meta['linked_handles']);
            foreach (['contact_handles', 'linked_handles'] as $key) {
                if (($prior[$key] ?? null) !== null) {
                    $meta[$key] = $prior[$key];
                }
            }
            $meta['renew_here_line'] = (int) $record['line'];
            $meta['renew_transfer_failed'] = ['at' => now()->toIso8601String(), 'to' => $record['to'] ?? null, 'status' => $status, 'line' => (int) $record['line']];
            $fresh->forceFill($columns + ['meta' => $meta])->save();
            $line = InvoiceItem::query()->lockForUpdate()->find($record['line']);
            $line?->forceFill(['applied_at' => null])->save();
            ModuleAction::domainFailed($fresh, $line, $fresh->effectiveRegistrarConnection()?->registrar, new RuntimeException(
                "The transfer of {$fresh->fqdn} that replaced its paid renewal failed at the target (status {$status}). It is back at its old registrar, as it was: the next run renews it there (Retry to do it now)."));
        });
    }

    /**
     * The source's contact handles kept in meta.renew_transfer (its undo, and staff's record) and emptied, so the target
     * makes its own; returns them.
     *
     * @return array<string, mixed>
     */
    private function clearHandles(Domain $domain, DomainRegistrarConnection $from, DomainRegistrarConnection $to, InvoiceItem $line): array
    {
        return DB::transaction(function () use ($domain, $from, $to, $line) {
            $fresh = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $meta = $fresh->meta ?? [];
            // A prior already kept (a run before it whose answer was not known) is the source's: never replaced by another's.
            if (is_array($meta['renew_transfer']['prior'] ?? null)) {
                throw new RuntimeException('a transfer replacing its renewal is already on record (meta.renew_transfer): check it first');
            }
            $prior = ['handles' => [], 'contact_handles' => $meta['contact_handles'] ?? null, 'linked_handles' => $meta['linked_handles'] ?? null,
                'registrar_domain_id' => $fresh->registrar_domain_id];
            $clear = [];
            foreach (Domain::ROLES as $role) {
                $prior['handles'][$role] = $fresh->{"{$role}_handle"};
                $clear["{$role}_handle"] = null;
            }
            unset($meta['contact_handles'], $meta['linked_handles']);
            $meta['renew_transfer'] = ['line' => $line->id, 'from' => $from->id, 'to' => $to->id, 'at' => now()->toIso8601String(), 'prior' => $prior];
            $fresh->forceFill($clear + ['meta' => $meta])->save();

            return $prior;
        });
    }

    /**
     * The source's handles back. With keepTarget (no clear answer), what the target made meanwhile is kept apart in
     * meta.renew_transfer_unknown for staff, never left on a domain still at its source (RAT-ADV-3).
     *
     * @param  array<string, mixed>  $prior
     */
    private function restoreHandles(Domain $domain, array $prior, bool $keepTarget = false): void
    {
        DB::transaction(function () use ($domain, $prior, $keepTarget) {
            $fresh = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $meta = $fresh->meta ?? [];
            if ($keepTarget) {
                $made = [];
                foreach (Domain::ROLES as $role) {
                    $made[$role] = $fresh->{"{$role}_handle"};
                }
                $meta['renew_transfer_unknown'] = ($meta['renew_transfer'] ?? []) + ['target_handles' => $made, 'target_contact_handles' => $meta['contact_handles'] ?? null];
            }
            unset($meta['renew_transfer'], $meta['contact_handles'], $meta['linked_handles']);
            foreach (['contact_handles', 'linked_handles'] as $key) {
                if ($prior[$key] !== null) {
                    $meta[$key] = $prior[$key];
                }
            }
            $handles = [];
            foreach ((array) $prior['handles'] as $role => $handle) {
                $handles["{$role}_handle"] = $handle;
            }
            $fresh->forceFill($handles + ['meta' => $meta])->save();
        });
    }

    /** Locked again where it was unlocked for a transfer that did not happen; best-effort (the next sync shows it). */
    private function relock(Domain $domain, mixed $source, mixed $credentials, bool $unlocked): void
    {
        if (!$unlocked) {
            return;
        }
        try {
            $this->sync->applyResult($domain, $source->updateDomain($domain->refresh(), new DomainChanges(transferLock: true), $credentials));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function log(Domain $domain, DomainRegistrarConnection $connection, ?Staff $staff, InvoiceItem $line, string $action, string $status, ?string $error = null): void
    {
        DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id, 'staff_id' => $staff?->id,
            'invoice_id' => $line->invoice_id, 'action' => $action, 'status' => $status, 'request' => ['line' => $line->id, 'renewal_transfer' => true],
            'error' => $error !== null ? mb_substr(Redact::text($error), 0, 2000) : null]);
    }
}
