<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Domains\Concerns\GuardsRegistrarWrites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE one path to a registrar renewal (Πυλώνας A / A3a) — both the on-issue
 * hook (InvoiceObserver, §6.1 «renew = on-issue») and the View «Ανανέωση στον
 * registrar» button go through here. Nothing else may call
 * DomainRegistrar::renew().
 *
 * §6.6 ΔΕΣΜΕΥΤΙΚΟ (owner war story — the WHMCS double-renew/relid bug):
 * BEFORE calling renew() we sync and compare the registrar's CURRENT expiry
 * with the period the renewal covers — if it already covers it (someone
 * renewed already: the button, the registrar panel, another path), we ADOPT
 * (update expires_at, log 'adopted', NO renew call). The only way to
 * double-renew is to explicitly ask for two periods. Every attempt — ok /
 * adopted / failed — lands in domain_registrar_logs (the §9 API history).
 */
class DomainRenewalService
{
    use GuardsRegistrarWrites;

    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
    ) {}

    /**
     * On-PAID hook (MyCS: the domain renews when its invoice is paid; Ekdosi renewed on
     * issue of the fiscal invoice). Every invoice item that bills a Service linked to a
     * domain drives one adopt-or-renew. Returns the audit rows of the domains it handled.
     *
     * @return list<DomainRegistrarLog>
     */
    public function renewForInvoice(Invoice $invoice): array
    {
        $logs = [];
        foreach ($invoice->items as $item) {
            if ($item->reference_type !== Service::class || !$item->reference instanceof Service) {
                continue;
            }
            $log = $this->renewForService($invoice, $item->reference, $item->period_start?->toDateString());
            if ($log !== null) {
                $logs[] = $log;
            }
        }

        return $logs;
    }

    /**
     * One paid renewal of one domain-linked service. Null when the service has no domain,
     * the domain is manual-routed (renewed by hand), or this invoice already drove the
     * renewal (idempotent across event re-fires). Throws on a REAL failure.
     */
    public function renewForService(Invoice $invoice, Service $service, ?string $periodStart = null): ?DomainRegistrarLog
    {
        $domain = Domain::query()->where('service_id', $service->id)->first();
        if ($domain === null) {
            return null; // an ordinary (non-domain) service
        }

        $already = DomainRegistrarLog::query()
            ->where('domain_id', $domain->id)
            ->where('invoice_id', $invoice->id)
            ->where('action', 'renew')
            ->whereIn('status', [DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::STATUS_ADOPTED])
            ->exists();
        if ($already) {
            return null;
        }

        $connection = $domain->effectiveRegistrarConnection();
        if ($connection === null || $this->factory->for($connection)->key() === 'manual') {
            return null; // manual-routed: renewed by hand at the registrar portal
        }

        $years = $this->yearsFor($service);
        // The billing cursor: the period this invoice pays for (invoice_items.period_start,
        // Ekdosi: the ServiceContract's next_due_date). NOT the domain's expires_at: a renewal
        // done by hand before payment already moved it, and target = expiry + years would
        // renew AGAIN (the WHMCS double-renew). Items without a cursor (created before the
        // column existed) fall back to renew()'s default: the domain's expiry.
        if ($years === null) {
            $message = "Ο κύκλος χρέωσης της υπηρεσίας του {$domain->fqdn} δεν αντιστοιχεί σε έτη ανανέωσης — η ανανέωση στον registrar δεν εκτελέστηκε.";
            DomainRegistrarLog::create([
                'domain_id' => $domain->id,
                'registrar_connection_id' => $connection->id, 'invoice_id' => $invoice->id,
                'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_FAILED,
                'request' => ['fqdn' => $domain->fqdn, 'baseline_expiry' => $periodStart],
                'error' => $message,
            ]);

            throw new RuntimeException($message);
        }

        $fromPrior = DomainRegistrarLog::query()
            ->where('invoice_id', $invoice->id)
            ->where('action', 'renew')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DomainRegistrarLog $r) => $r->request['baseline_expiry'] ?? null)
            ->first(fn ($baseline) => is_string($baseline) && $baseline !== '');
        $periodStart = $fromPrior ?? $periodStart;

        // The cross-tenant sweep must gate the intent-match leg TOO (gate r2):
        // it never touches the registrar, but silently marking a claimed-
        // elsewhere name «covered» by pre-claim button years would hide from
        // the operator exactly what every other leg refuses loudly. The
        // refusal row carries the period (r5 lesson) and the invoice id.
        $claimLog = $this->writeLogger($domain, $connection, 'renew', [
            'fqdn' => $domain->fqdn, 'years' => $years, 'baseline_expiry' => $periodStart,
        ], $invoice->id);

        // INTENT-MATCH adopt (date-drift-proof, the second §6.6 leg): an OK
        // button renewal not yet tied to any invoice IS this invoice's renewal
        // — consume it, no date comparison needed. Covers the operator who
        // renewed early while the SC cursor had drifted from the expiry
        // (billing grace edits), where the cursor+years date check would
        // wrongly renew again. BOUNDED to the current billing window: a
        // years-old orphan log must NOT satisfy today's invoice (its registrar
        // year may have lapsed long ago) — stale logs fall through to the
        // sync-backed date check, which renews if truly needed.
        $cutoff = $periodStart !== null
            ? Carbon::parse($periodStart)->subYears($years)
            : Carbon::today()->subYears($years);
        $candidates = DomainRegistrarLog::query()
            ->unconsumedOkRenewals($domain)
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('id')
            ->get();
        // AGGREGATE + compare-and-swap: split button renewals (two 1yr logs
        // behind one biennial invoice) count TOGETHER — same arithmetic as the
        // date-adopt stamping. Each row is CAS-claimed (whereNull → update),
        // so two concurrent invoices can never both spend the same year; a
        // loser whose claims fall short has them ROLLED BACK to the pool (the
        // transaction below) and falls through to renew(), whose sync-backed
        // date check is the ground truth.
        if ($candidates->sum(fn (DomainRegistrarLog $r) => max(0, (int) ($r->request['years'] ?? 0))) >= $years) {
            // ATOMIC claim: claims + the ADOPTED summary commit (or vanish)
            // together. A partial claim must NEVER persist — a claimed-but-
            // uncovered invoice would satisfy the $already idempotency guard
            // forever while having fewer registrar years than it billed
            // (r5 finding 1: the regression the plain loop introduced). On
            // insufficient coverage (a race stole rows) the rollback releases
            // every claim back to the pool and we fall through to renew().
            try {
                return DB::transaction(function () use ($candidates, $years, $domain, $connection, $invoice): DomainRegistrarLog {
                    $covered = 0;
                    $consumedIds = [];
                    foreach ($candidates as $row) {
                        if ($covered >= $years) {
                            break;
                        }
                        if (DomainRegistrarLog::query()->whereKey($row->id)->whereNull('invoice_id')
                            ->update(['invoice_id' => $invoice->id]) === 1) {
                            $consumedIds[] = $row->id;
                            // max(0): a years-less row contributes NOTHING —
                            // same arithmetic as the gate sum above.
                            $covered += max(0, (int) ($row->request['years'] ?? 0));
                        }
                    }
                    if ($covered < $years) {
                        // rollback releases the claims atomically — nothing sticks
                        throw new InsufficientAdoptCoverage;
                    }

                    return DomainRegistrarLog::create([
                        'domain_id' => $domain->id,
                        'registrar_connection_id' => $connection->id,
                        'invoice_id' => $invoice->id,
                        'action' => 'renew',
                        'status' => DomainRegistrarLog::STATUS_ADOPTED,
                        'request' => ['fqdn' => $domain->fqdn, 'years' => $years],
                        'response' => ['consumed_log_ids' => $consumedIds],
                    ]);
                });
            } catch (InsufficientAdoptCoverage) {
                // a race stole rows — the date check below is the ground truth
            }
        }

        return $this->renew($domain, $years, $invoice, $periodStart);
    }

    /**
     * Adopt-or-renew for N years. `$periodStart` is where the renewed period
     * begins: the SC cursor for the on-issue flow (the period the invoice
     * covers), or null = the domain's current expiry (the View button —
     * «δώσε άλλα N χρόνια από όπου είμαστε»). Throws on refusal/failure
     * (after logging); returns the audit row ('ok' or 'adopted').
     */
    public function renew(Domain $domain, int $years, ?Invoice $invoice = null, ?string $periodStart = null): DomainRegistrarLog
    {
        // The baseline the renewal extends FROM (nullable until validated —
        // refusals below still log it so the record carries what was known).
        $baseline = $periodStart ?? $domain->expires_at?->toDateString();
        // NoOverflow: consistent with BillingCycle::advance (a Feb-29 baseline
        // must compute the SAME target on first issue and re-issue).
        $target = $baseline !== null ? Carbon::parse($baseline)->addYearsNoOverflow($years)->toDateString() : null;
        $connection = $domain->effectiveRegistrarConnection();

        // EVERY attempt logs — refusals included: the docblock contract, and
        // load-bearing for the re-issue period recovery (a refusal without a
        // failed row would leave a later re-issue with no period to recover,
        // re-opening the interleaved-revert double-renew door).
        $log = $this->writeLogger($domain, $connection, 'renew', [
            'fqdn' => $domain->fqdn,
            'years' => $years,
            'baseline_expiry' => $baseline,
            'target_expiry' => $target,
        ], $invoice?->id);

        // Dead set / wrong state: never a registrar charge from any path.
        if ($domain->trashed()) {
            $this->refuseWrite($log, 'Το domain είναι διαγραμμένο — δεν ανανεώνεται.');
        }
        if ($domain->status instanceof DomainStatus && !$domain->status->isRenewable()) {
            $this->refuseWrite($log, "Το {$domain->fqdn} είναι σε κατάσταση «{$domain->status->getLabel()}» — δεν ανανεώνεται (redemption/μεταφερμένα/ακυρωμένα θέλουν άλλο χειρισμό).");
        }
        $adapter = $this->resolveWriteAdapter($connection, $log, 'Ο registrar είναι «manual» — ανανεώστε στο portal του registrar και ενημερώστε τη λήξη.');
        if ($baseline === null) {
            $this->refuseWrite($log, "Το {$domain->fqdn} δεν έχει γνωστή λήξη — κάντε πρώτα «Συγχρονισμό από registrar».");
        }
        // Shared reseller account (r1 P1 of the A3d gate): a renew here would
        // sync-adopt ANOTHER tenant's registrar id/expiry and then extend (or
        // intent-satisfy from) THEIR registration — the exact class every
        // other write already refuses.

        // ONE renewal at a time per domain: the check→write sequence must not
        // race (View button vs on-issue hook vs auto-issue — both would pass
        // the pre-check and both would POST = double charge).
        return $this->withRegistrarLock(
            $domain,
            'renew',
            $log,
            "Άλλη ανανέωση του {$domain->fqdn} είναι ήδη σε εξέλιξη — ΜΗΝ ξαναζητήσετε ανανέωση· δείτε το ιστορικό API του domain σε λίγο.",
            function () use ($domain, $connection, $adapter, $years, $invoice, $baseline, $target, $log): DomainRegistrarLog {
                // §6.6: FRESH TRUTH FIRST. If the registrar's current expiry already
                // covers the target period, someone renewed already → ADOPT, no call.
                try {
                    $this->sync->sync($domain);
                } catch (\Throwable $e) {
                    // If we can't even read the registrar, we must not WRITE to it
                    // blind — the whole guard rests on the read.
                    $log(DomainRegistrarLog::STATUS_FAILED, null, 'Προ-έλεγχος (sync) απέτυχε: ' . $e->getMessage());

                    throw new RuntimeException("Η ανανέωση του {$domain->fqdn} ΔΕΝ εκτελέστηκε — ο προ-έλεγχος στον registrar απέτυχε: " . $e->getMessage());
                }
                $domain->refresh();
                if ($domain->expires_at !== null && $domain->expires_at->toDateString() >= $target) {
                    // The registrar year(s) covering this adopt may have come from
                    // (unconsumed) button renewals — stamp newest-first until the
                    // stamped years cover the billed term, so NO leftover log can
                    // also satisfy a later intent-match (a double-count would let
                    // billed years exceed registrar years — e.g. two 1yr button
                    // logs behind one biennial adopt must BOTH be consumed).
                    $consumedIds = [];
                    if ($invoice !== null) {
                        $covered = 0;
                        // SAME window as the intent-match leg: a stale orphan the
                        // intent-match refuses must not be swallowed here either
                        // (it belongs to the A5 reconciler's orphan sweep, not to
                        // an invoice whose coverage came from elsewhere).
                        $pool = DomainRegistrarLog::query()
                            ->unconsumedOkRenewals($domain)
                            ->where('created_at', '>=', Carbon::parse($baseline)->subYears($years))
                            ->orderByDesc('id')
                            ->get();
                        foreach ($pool as $row) {
                            if ($covered >= $years) {
                                break;
                            }
                            if (DomainRegistrarLog::query()->whereKey($row->id)->whereNull('invoice_id')
                                ->update(['invoice_id' => $invoice->id]) === 1) {
                                $consumedIds[] = $row->id;
                                $covered += max(1, (int) ($row->request['years'] ?? 1));
                            }
                        }
                    }

                    return $log(DomainRegistrarLog::STATUS_ADOPTED, [
                        'registrar_expiry' => $domain->expires_at->toDateString(),
                        'consumed_log_ids' => $consumedIds,
                    ], null);
                }
                // Re-check renewability on the FRESH status: the sync may have just
                // flipped the row to Deleted/redemption (registrar DEL) — «νεκρό
                // όνομα = ποτέ χρέωση» holds against stale local state too.
                if ($domain->status instanceof DomainStatus && !$domain->status->isRenewable()) {
                    $log(DomainRegistrarLog::STATUS_FAILED, null, 'Ο registrar αναφέρει κατάσταση «' . $domain->status->getLabel() . '» — η ανανέωση δεν εκτελέστηκε.');

                    throw new RuntimeException("Το {$domain->fqdn} είναι πλέον σε κατάσταση «{$domain->status->getLabel()}» στον registrar — δεν ανανεώνεται.");
                }

                try {
                    $result = $adapter->renew($domain, $years, $this->factory->credentialsFor($connection));
                } catch (\Throwable $e) {
                    $log(DomainRegistrarLog::STATUS_FAILED, null, $e->getMessage());

                    throw $e;
                }
                $this->sync->apply($domain, $result);

                // A renew that landed SHORT of the billed target (the registrar
                // extended from ITS OWN expiry, which lagged the cursor — e.g. a
                // prior failed renewal never retried) is a real charge, so it logs
                // 'ok' — but flagged, so the caller can alert the operator. NULL =
                // unknown (the post-renew re-fetch fell back; the A5 reconciler
                // re-evaluates ok-logs whose landed expiry misses their target).
                $short = $result->expiresAt === null ? null : $result->expiresAt < $target;

                return $log(DomainRegistrarLog::STATUS_OK, [
                    'registrar_expiry' => $result->expiresAt,
                    'raw_status' => $result->rawStatus,
                    'short_of_target' => $short,
                ], null);
            },
        );
    }

    /**
     * Registrar years for one paid period: yearly plans only (1y, 2y, 3y…; also 12/24/36
     * months). Anything else (monthly, quarterly) is not a domain cycle → null.
     */
    public function yearsFor(Service $service): ?int
    {
        $plan = $service->plan;
        if ($plan === null || (int) $plan->billing_period < 1) {
            return null;
        }
        $period = (int) $plan->billing_period;

        return match ($plan->billing_unit) {
            'year' => $period <= 10 ? $period : null,
            'month' => $period % 12 === 0 && $period <= 120 ? intdiv($period, 12) : null,
            default => null,
        };
    }
}
