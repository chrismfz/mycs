<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Services\Order\OrderPricing;
use App\Support\Domains\DomainSyncResult;
use App\Support\Redact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A paid domain renewal done at its registrar (docs/domains.md D-1, D-2, D-13, D-15, D-17, D5b-2). Real money at the
 * registrar, so:
 * - one at a time per domain (the registrar lock), and once per invoice: a charge is logged as «pending» before it is
 *   asked, so an answer that never came (a timeout) is never asked again blindly;
 * - the registrar first (D-2): a period it already covers (renewed elsewhere, by «Renew now», by an earlier attempt) is
 *   adopted, never charged twice;
 * - a live domain is renewed for the paid years; one in its grace or redemption at the registrar is restored (one year,
 *   D-13) and the other paid years renewed;
 * - a manual registrar, a domain past recovery, a refusal or an unknown outcome become a Pending Module Action for staff,
 *   never a failed payment (D-15).
 * The line is applied (applied_at) once the registrar covers its period or has charged for it.
 */
class DomainRenewalService
{
    /** Slack when comparing the registrar's expiry with the period paid (29 February, registry rounding). */
    private const TOLERANCE_DAYS = 3;

    /** An unknown outcome blocks a new charge this long (as the Blesta module's RenewalPlan). */
    private const PENDING_HOLD_DAYS = 7;

    public const APPLIED = 'applied';

    public const ADOPTED = 'adopted';

    public const WAITING = 'waiting';

    public const FAILED = 'failed';

    public const NOTHING = 'nothing';

    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
        private readonly DomainLifecycle $lifecycle,
    ) {}

    /**
     * The paid domain renewal lines not applied yet (D5b-2): what the cron and the payment hand here.
     *
     * @return Builder<InvoiceItem>
     */
    public static function waitingLines(): Builder
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->whereNotNull('period_end')
            ->whereNull('applied_at')->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_PAID));
    }

    /** Renew at the registrar what this paid line paid for; returns what happened (one of the constants). */
    public function renewLine(int $lineId, ?Staff $staff = null): string
    {
        $line = self::waitingLines()->whereKey($lineId)->first();
        $domain = $line !== null ? Domain::query()->find($line->reference_id) : null;
        if ($line === null || $domain === null) {
            return self::NOTHING; // applied meanwhile, or not a paid renewal
        }
        if (in_array($domain->status->value, DomainStatus::terminalValues(), true)) {
            $this->staffDecides($domain, $line, null, "The domain has ended ({$domain->status->value}): renew or restore it by hand, or refund the payment.");

            return self::WAITING;
        }
        $connection = $domain->effectiveRegistrarConnection();
        if (!$connection instanceof DomainRegistrarConnection || !$connection->isUsable()) {
            $this->staffDecides($domain, $line, $connection === null ? 'manual' : $connection->registrar,
                'No registrar with an API (manual): renew it by hand at the registrar, then mark this resolved.');

            return self::WAITING;
        }

        $lock = Cache::lock("domain-registrar:{$domain->id}", 600);
        if (!$lock->get()) {
            return self::WAITING; // a change is under way: the next run
        }
        set_time_limit(600);
        try {
            return $this->renewLocked($domain->refresh(), $line->refresh(), $connection, $staff);
        } finally {
            $lock->release();
        }
    }

    private function renewLocked(Domain $domain, InvoiceItem $line, DomainRegistrarConnection $connection, ?Staff $staff): string
    {
        if ($line->applied_at !== null) {
            return self::NOTHING;
        }
        $registrar = $this->factory->for($connection);
        $credentials = $this->factory->credentialsFor($connection);
        $target = Carbon::parse($line->period_end)->startOfDay();
        $years = max(1, (int) round(Carbon::parse($line->period_start)->diffInYears($target)));

        // The registrar first (D-2): its truth applied, and a period it already covers adopted.
        try {
            $now = $registrar->syncDomain($domain, $credentials);
        } catch (Throwable $e) {
            $this->staffDecides($domain, $line, $connection->registrar, 'The registrar could not be read: ' . $e->getMessage());

            return self::FAILED;
        }
        $this->sync->applyResult($domain, $now);
        if ($this->covers($now, $target)) {
            return $this->apply($domain, $line, $connection, $staff, DomainRegistrarLog::STATUS_ADOPTED, $now, 'adopted');
        }

        // Once per invoice (D-17): a charge already asked for this invoice whose outcome is unknown is not asked again
        // for a while; after that, staff decide.
        $earlier = DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('invoice_id', $line->invoice_id)
            ->whereIn('action', ['renew', 'restore'])->whereIn('status', [DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::STATUS_OK])->latest('id')->first();
        if ($earlier !== null) {
            if ($earlier->created_at->greaterThan(now()->subDays(self::PENDING_HOLD_DAYS))) {
                return self::WAITING; // its outcome may still show up at the registrar: the next run looks again
            }
            $this->staffDecides($domain, $line, $connection->registrar, "A {$earlier->action} for this invoice was asked on {$earlier->created_at->toDateString()} and the registrar does not show it: check the registrar before anything else.");

            return self::FAILED;
        }

        // What the registrar can do with it now.
        $restore = in_array($now->status, [DomainStatus::Grace, DomainStatus::Redemption], true) && $now->rawStatus !== 'ACT';
        if (!$restore && $now->rawStatus !== 'ACT') {
            $this->staffDecides($domain, $line, $connection->registrar, "The registrar has it as {$now->rawStatus}: it cannot be renewed automatically.");

            return self::FAILED;
        }

        $steps = $restore ? array_filter(['restore' => 1, 'renew' => $years - 1]) : ['renew' => $years];
        $result = $now;
        foreach ($steps as $action => $stepYears) {
            $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id,
                'staff_id' => $staff?->id, 'invoice_id' => $line->invoice_id, 'action' => $action, 'status' => DomainRegistrarLog::STATUS_PENDING,
                'request' => ['years' => $stepYears, 'target_expiry' => $target->toDateString(), 'line' => $line->id]]);
            try {
                $result = $action === 'restore' ? $registrar->restore($domain, $credentials) : $registrar->renew($domain->refresh(), $stepYears, $credentials);
            } catch (ConnectionException $e) {
                // No answer: it may have been charged. Left «pending»: never asked again blindly (above).
                $log->update(['error' => mb_substr(Redact::text($e->getMessage()), 0, 2000)]);
                $this->staffDecides($domain, $line, $connection->registrar, "No answer from the registrar to the {$action}: its outcome is checked on the next run.");

                return self::FAILED;
            } catch (Throwable $e) {
                $log->update(['status' => DomainRegistrarLog::STATUS_FAILED, 'error' => mb_substr(Redact::text($e->getMessage()), 0, 2000)]);
                $this->staffDecides($domain, $line, $connection->registrar, "The registrar refused the {$action}: " . $e->getMessage());

                return self::FAILED;
            }
            $log->update(['status' => DomainRegistrarLog::STATUS_OK, 'response' => Redact::array(['status' => $result->rawStatus, 'expires_at' => $result->expiresAt])]);
            $this->sync->applyResult($domain->refresh(), $result);
        }

        // Charged: applied, whatever the registrar shows yet (its new expiry reaches us with the sync).
        return $this->apply($domain->refresh(), $line, $connection, $staff, DomainRegistrarLog::STATUS_OK, $result, 'renewed');
    }

    /** The registrar's expiry reaches the end of the paid period. */
    private function covers(DomainSyncResult $result, Carbon $target): bool
    {
        return $result->status === DomainStatus::Active && $result->expiresAt !== null
            && Carbon::parse($result->expiresAt)->greaterThanOrEqualTo($target->copy()->subDays(self::TOLERANCE_DAYS));
    }

    private function apply(Domain $domain, InvoiceItem $line, DomainRegistrarConnection $connection, ?Staff $staff, string $status, DomainSyncResult $result, string $how): string
    {
        DB::transaction(function () use ($domain, $line, $connection, $staff, $status, $result, $how) {
            $line->forceFill(['applied_at' => now()])->save();
            if ($how === 'adopted') {
                DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id,
                    'staff_id' => $staff?->id, 'invoice_id' => $line->invoice_id, 'action' => 'renew', 'status' => $status,
                    'response' => ['status' => $result->rawStatus, 'expires_at' => $result->expiresAt]]);
            }
            ModuleAction::domainSucceeded($domain, $line);
        });
        $this->lifecycle->advance($domain->refresh());

        return $how === 'adopted' ? self::ADOPTED : self::APPLIED;
    }

    private function staffDecides(Domain $domain, InvoiceItem $line, ?string $module, string $why): void
    {
        ModuleAction::domainFailed($domain, $line, $module, new RuntimeException($why));
    }

    /**
     * «Renew now» (D-1): staff renew a trusted customer's domain before the payment. Its renewal is billed first if it is
     * not (an invoice of its own), then renewed at the registrar for its years, logged against that invoice; the later
     * payment finds the period covered and adopts it, never charging the registrar again.
     *
     * @return InvoiceItem the renewal line, to be paid
     */
    public function renewNow(Domain $domain, Staff $staff): InvoiceItem
    {
        $connection = $domain->effectiveRegistrarConnection();
        if (!$connection instanceof DomainRegistrarConnection || !$connection->isUsable()) {
            throw new RuntimeException(__('domains.manage.not_manageable', ['domain' => $domain->fqdn]));
        }
        if ($domain->expires_at === null || in_array($domain->status->value, DomainStatus::terminalValues(), true)) {
            throw new RuntimeException(__('domains.renew.not_renewable', ['domain' => $domain->fqdn]));
        }
        $lock = Cache::lock("domain-registrar:{$domain->id}", 600);
        if (!$lock->get()) {
            throw new RuntimeException(__('domains.manage.busy', ['domain' => $domain->fqdn]));
        }
        set_time_limit(600);
        try {
            $line = $this->billedOrBill($domain->refresh());
            $registrar = $this->factory->for($connection);
            $credentials = $this->factory->credentialsFor($connection);
            $now = $registrar->syncDomain($domain, $credentials);
            $this->sync->applyResult($domain, $now);
            $target = Carbon::parse($line->period_end)->startOfDay();
            if ($this->covers($now, $target)) {
                return $line; // already renewed: its payment adopts it
            }
            if ($now->rawStatus !== 'ACT') {
                throw new RuntimeException(__('domains.renew.not_active_at_registrar', ['domain' => $domain->fqdn, 'status' => (string) $now->rawStatus]));
            }
            $years = max(1, (int) round(Carbon::parse($line->period_start)->diffInYears($target)));
            $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id,
                'staff_id' => $staff->id, 'invoice_id' => $line->invoice_id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_PENDING,
                'request' => ['years' => $years, 'target_expiry' => $target->toDateString(), 'line' => $line->id, 'renew_now' => true]]);
            try {
                $result = $registrar->renew($domain->refresh(), $years, $credentials);
            } catch (ConnectionException $e) {
                $log->update(['error' => mb_substr(Redact::text($e->getMessage()), 0, 2000)]);

                throw $e; // unknown: left pending, its payment looks at the registrar first
            } catch (Throwable $e) {
                $log->update(['status' => DomainRegistrarLog::STATUS_FAILED, 'error' => mb_substr(Redact::text($e->getMessage()), 0, 2000)]);

                throw $e;
            }
            $log->update(['status' => DomainRegistrarLog::STATUS_OK, 'response' => Redact::array(['status' => $result->rawStatus, 'expires_at' => $result->expiresAt])]);
            $this->sync->applyResult($domain->refresh(), $result);
            $this->lifecycle->advance($domain->refresh());

            return $line;
        } finally {
            $lock->release();
        }
    }

    /** Its renewal line for the period from its expiry: the one billed (open or paid), else one on an invoice of its own. */
    private function billedOrBill(Domain $domain): InvoiceItem
    {
        return DB::transaction(function () use ($domain) {
            $start = $domain->expires_at->copy()->startOfDay();
            $billed = $domain->invoiceItems()->whereNull('kind')->whereDate('period_end', '>', $start->toDateString())
                ->whereHas('invoice', fn ($invoice) => $invoice->where('status', '!=', Invoice::STATUS_CANCELLED))->orderByDesc('id')->first();
            if ($billed !== null) {
                return $billed;
            }
            $years = max(1, (int) $domain->years);
            $price = $domain->renewalPrice()
                ?? throw new RuntimeException(__('domains.renew.no_price', ['domain' => $domain->fqdn, 'years' => $years]));
            $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID,
                'due_at' => $start->greaterThan(today()) ? $start : today(), 'currency_code' => $domain->currency_code]);
            $end = $start->copy()->addYearsNoOverflow($years);

            return $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id,
                'price' => OrderPricing::toNet($price, $domain->client), 'quantity' => 1,
                'description' => $domain->describeRenewal($start, $end, $years), 'period_start' => $start, 'period_end' => $end]);
        });
    }

    /**
     * Staff renewed it by hand (a manual registrar, or after checking the registrar): the line applied, the domain's
     * expiry moved to the end of the paid period (a registrar with an API corrects it on its next sync).
     */
    public function markRenewedByHand(InvoiceItem $line, ?Staff $staff): void
    {
        DB::transaction(function () use ($line, $staff) {
            $line = InvoiceItem::query()->whereKey($line->id)->lockForUpdate()->first();
            $domain = $line !== null ? Domain::query()->whereKey($line->reference_id)->lockForUpdate()->first() : null;
            if ($line === null || $domain === null || $line->applied_at !== null || $line->period_end === null) {
                return;
            }
            $line->forceFill(['applied_at' => now()])->save();
            $end = Carbon::parse($line->period_end);
            if ($domain->expires_at === null || $domain->expires_at->lessThan($end)) {
                $domain->forceFill(['expires_at' => $end])->save(); // audited
            }
            DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $domain->effectiveRegistrarConnection()?->id,
                'staff_id' => $staff?->id, 'invoice_id' => $line->invoice_id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
                'response' => ['by_hand' => true, 'expires_at' => $end->toDateString()]]);
        });
        $domain = Domain::query()->find($line->reference_id);
        if ($domain !== null) {
            $this->lifecycle->advance($domain);
        }
    }
}
