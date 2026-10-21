<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Staff;
use App\Services\Order\OrderPricing;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarTransactions;
use App\Support\Redact;
use App\Support\TimeLimit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A paid domain renewal done at its registrar (docs/domains.md D-1, D-2, D-13, D-15, D-17, D5b-2). Real money at the
 * registrar, so:
 * - one at a time per domain (the registrar lock);
 * - never on an unconfirmed charge: a charge is logged «pending» before it is asked, and stays so unless the registrar
 *   answered (done) or refused it (4xx: nothing done). While one is pending for the domain, from any line, invoice or
 *   «Renew now», nothing is charged; after PENDING_HOLD_DAYS, staff check the registrar (code review 1, 2, 4);
 * - the registrar first (D-2): the years charged are those its expiry lacks to reach the end of the paid period, so a
 *   period it covers is adopted and two paid periods, in any order, get exactly the years paid (code review 3);
 * - a live domain is renewed; one in its grace at the registrar (soft quarantine) is restored, its grace fee paid, and
 *   the rest renewed; its redemption (hard quarantine) and anything else go to staff (D-13; code review 5);
 * - a manual registrar, a refusal or an unknown outcome become a Pending Module Action, never a failed payment (D-15).
 */
class DomainRenewalService
{
    /** Slack when comparing the registrar's expiry with the period paid (29 February, registry rounding). */
    private const TOLERANCE_DAYS = 3;

    /** An unconfirmed charge blocks any new one this long, then staff check (as the Blesta module's RenewalPlan). */
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
        return InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->whereNotNull('period_start')->whereNotNull('period_end')
            ->whereNull('applied_at')->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_PAID));
    }

    /**
     * Its renewal line for the period from $start. Billed (D5b-1, the cron): a line whose period reaches past it on any
     * invoice but a cancelled one whose period was kept billable (Δ3, ADV-4: cancelled before it started). Payable («Renew
     * now»): only on an invoice still to be paid or paid, never a cancelled or refunded one (adversarial ADV-3). By its
     * period only: a renewal line always has one (prepareLine), and a line without one is never taken for this year's
     * renewal (it used to be, by its date: an owner change or a late renewal of last year counted, review R6 of #172).
     */
    public static function billedLine(Domain $domain, Carbon $start, bool $payable = false): ?InvoiceItem
    {
        $dead = $payable ? [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED] : [Invoice::STATUS_CANCELLED];

        // Its period covers $start (adversarial ADV-7 of #174: not a later year past a hole), within the days an expiry moves
        // by a sync (D5b-1 review: a period a day off is the same one).
        return $domain->invoiceItems()->whereNull('kind')->whereDate('period_end', '>', $start->toDateString())
            ->whereDate('period_start', '<=', $start->copy()->addDays(self::SAME_PERIOD_DAYS)->toDateString())
            ->when($payable, fn ($q) => $q->whereHas('invoice', fn ($invoice) => $invoice->whereNotIn('status', $dead)))
            // Cancelled: billed only if given up (Δ3); one cancelled before its period started is billed again (ADV-4).
            ->when(!$payable, fn ($q) => $q->where(fn ($kept) => $kept->whereNotNull('forgone_at')
                ->orWhereHas('invoice', fn ($invoice) => $invoice->where('status', '!=', Invoice::STATUS_CANCELLED))))
            ->orderByDesc('id')->first();
    }

    /**
     * What a new domain line is (review R6 of #172, code review F1 of #174): a renewal only with its period (the cron's,
     * «Renew now», «Invoice next period»), or when its maker asks for one (kind «renewal», the Admin API): then it gets the
     * domain's first period not on a live invoice, from its expiry, and paid it renews at the registrar (D5b-2). Anything
     * else on the domain without a period (an owner change, a fee) is a «charge»: it never renews nor counts as billed.
     * The safe side by default: a renewal is never made by forgetting to say it is not one.
     */
    /** An expiry a sync moved by this many days still starts the same period. */
    public const SAME_PERIOD_DAYS = 31;

    public static function prepareLine(InvoiceItem $line): void
    {
        if ($line->reference_type !== Domain::class) {
            return;
        }
        $asked = $line->kind === InvoiceItem::KIND_RENEWAL;
        if ($asked) {
            $line->kind = null;
        }
        if ($line->kind !== null || $line->period_start !== null || $line->period_end !== null) {
            return; // a stage fee, a charge, or a renewal with its period
        }
        if (!$asked) {
            $line->kind = InvoiceItem::KIND_CHARGE;

            return;
        }
        $domain = Domain::query()->find($line->reference_id);
        if ($domain?->expires_at === null) {
            // F6: no expiry, no period to renew; a renewal paid now would renew nothing and be billed again later.
            throw new DisplayException(__('domains.renew.not_renewable', ['domain' => (string) $domain?->fqdn]));
        }
        $start = $domain->expires_at->copy()->startOfDay();
        // Past the periods live invoices bill already (never the same year twice); bounded, as a period is at least a year.
        for ($i = 0; $i < 20 && ($billed = self::billedLine($domain, $start, payable: true)) !== null && $billed->period_end !== null; $i++) {
            $start = Carbon::instance($billed->period_end)->startOfDay();
        }
        // As the cron bills it (F8, D-13): its TLD's shortest period in grace or redemption (one; two for .gr), else its years.
        $years = $domain->nextRenewalYears();
        $line->period_start = $start->toImmutable();
        $line->period_end = $start->toImmutable()->addYearsNoOverflow($years);
    }

    /**
     * Still a paid renewal waiting, read now (its invoice may be refunded meanwhile).
     *
     * @phpstan-impure
     */
    private function stillWaiting(InvoiceItem $line): bool
    {
        return self::waitingLines()->whereKey($line->id)->exists();
    }

    /** The domain's charge whose outcome is not known (any line, invoice or «Renew now»), or null. */
    private function unconfirmed(Domain $domain): ?DomainRegistrarLog
    {
        return DomainRegistrarLog::query()->where('domain_id', $domain->id)->whereIn('action', ['renew', 'restore'])
            ->where('status', DomainRegistrarLog::STATUS_PENDING)->latest('id')->first();
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
            $this->staffDecides($domain, $line, null, "The domain has ended ({$domain->status->value}): renew or restore it by hand, or refund the payment and close this.");

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
        TimeLimit::atLeast(600);
        try {
            return $this->renewLocked($domain->refresh(), $line->refresh(), $connection, $staff);
        } finally {
            $lock->release();
        }
    }

    private function renewLocked(Domain $domain, InvoiceItem $line, DomainRegistrarConnection $connection, ?Staff $staff): string
    {
        // Still a paid renewal waiting, under the lock (adversarial ADV-5: refunded meanwhile).
        if ($line->applied_at !== null || !$this->stillWaiting($line)) {
            return self::NOTHING;
        }
        $target = Carbon::parse($line->period_end)->startOfDay();

        try {
            $now = $this->factory->for($connection)->syncDomain($domain, $this->factory->credentialsFor($connection));
        } catch (Throwable $e) {
            $this->staffDecides($domain, $line, $connection->registrar, 'The registrar could not be read: ' . $e->getMessage());

            return self::FAILED;
        }
        $this->sync->applyResult($domain, $now);
        // The registrar first (D-2): a period it covers is adopted, whatever charge made it so.
        if ($this->covers($now, $target)) {
            return $this->apply($domain, $line, $connection, $staff, $now, true);
        }
        $pending = $this->unconfirmed($domain);
        if ($pending !== null) {
            if ($pending->created_at->greaterThan(now()->subDays(self::PENDING_HOLD_DAYS))) {
                return self::WAITING; // it may still show up at the registrar: the next run looks again
            }
            $this->staffDecides($domain, $line, $connection->registrar, "A {$pending->action} asked on {$pending->created_at->toDateString()} had no clear answer and the registrar does not show it: check the registrar before anything else.");

            return self::FAILED;
        }

        // Renewed when active; in its grace restored (Openprovider's soft quarantine), or renewed where the registrar renews
        // in grace (the .gr registry's 15 days: G4a of grepp). The adapter's status, never its raw words.
        $capabilities = $this->factory->for($connection)->capabilities();
        $restore = $now->status === DomainStatus::Grace && !$capabilities->renewsInGrace;
        $renewable = $now->status === DomainStatus::Active || ($now->status === DomainStatus::Grace && $capabilities->renewsInGrace);
        // A restore brings back one year; where the TLD's shortest period is more (a .gr name through a registrar that
        // restores), what a restore and renewal would do there is not known: staff decide before anything is charged
        // (review 1 of #189).
        if ($restore && $domain->stageRenewalYears() > 1) {
            $this->staffDecides($domain, $line, $connection->registrar, 'In its grace at a registrar that restores, and its TLD renews in periods of '
                . $domain->stageRenewalYears() . ' years: restore and renew it by hand.');

            return self::FAILED;
        }
        if (!$restore && !$renewable) {
            $stage = $now->status === DomainStatus::Redemption ? 'its redemption (a restore with a fee)' : "status {$now->rawStatus}";
            $this->staffDecides($domain, $line, $connection->registrar, "The registrar has it in {$stage}: staff decide (D-13).");

            return self::FAILED;
        }
        if ($restore && !$this->stageFeePaid($domain, $line, DomainStatus::Grace)) {
            $this->staffDecides($domain, $line, $connection->registrar, 'A restore is needed but its grace fee is not paid: bill it, or restore it by hand.');

            return self::FAILED;
        }

        if (!$this->stillWaiting($line)) {
            return self::NOTHING; // refunded while the registrar was read (ADV-5)
        }
        // Years already charged for this line (a «Renew now», a restore whose renew was refused): never again, whatever
        // the registrar shows yet (adversarial: a lagging registrar after «Renew now» was charged twice).
        $paid = $this->periodYears($line);
        $done = $this->chargedYears($line);
        if ($done >= $paid) {
            return $this->apply($domain, $line, $connection, $staff, $now, false); // charged: the registrar lags
        }
        $years = min($this->lacking($now, $target), $paid - $done);
        // A registrar that renews in steps (.gr: 2, 4 … 10) is never asked for another number of years: staff decide.
        if ($years % max(1, $capabilities->renewYearStep) !== 0) {
            $this->staffDecides($domain, $line, $connection->registrar, "The registrar renews in steps of {$capabilities->renewYearStep} years and {$years} would be needed: renew it by hand.");

            return self::FAILED;
        }
        $steps = $restore ? array_filter(['restore' => 1, 'renew' => $years - 1]) : ['renew' => $years];
        $result = $now;
        foreach ($steps as $action => $stepYears) {
            $result = $this->charge($domain, $line, $connection, $staff, $action, $stepYears, $target);
            if ($result === null) {
                return self::FAILED;
            }
        }

        return $this->apply($domain->refresh(), $line, $connection, $staff, $result, false);
    }

    /** One charge, logged pending before it is asked; null when it failed (refused, or its outcome unknown). */
    private function charge(Domain $domain, InvoiceItem $line, DomainRegistrarConnection $connection, ?Staff $staff, string $action, int $years, Carbon $target): ?DomainSyncResult
    {
        $registrar = $this->factory->for($connection);
        $credentials = $this->factory->credentialsFor($connection);
        $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id,
            'staff_id' => $staff?->id, 'invoice_id' => $line->invoice_id, 'action' => $action, 'status' => DomainRegistrarLog::STATUS_PENDING,
            'request' => ['years' => $years, 'target_expiry' => $target->toDateString(), 'line' => $line->id]]);
        $transactions = app(RegistrarTransactions::class);
        $mark = $transactions->mark();
        try {
            $result = $action === 'restore' ? $registrar->restore($domain->refresh(), $credentials) : $registrar->renew($domain->refresh(), $years, $credentials);
        } catch (RegistrarRefused|RegistrarNotAsked|DomainRegistrarNotConfigured $e) {
            // Refused, or never asked (the rules, a read before it, not configured: review 4 of #192): nothing done.
            $log->update(['status' => DomainRegistrarLog::STATUS_FAILED, 'error' => mb_substr(Redact::text($e->getMessage()), 0, 2000), 'transactions' => $transactions->since($mark)]);
            $this->staffDecides($domain, $line, $connection->registrar, ($e instanceof RegistrarRefused
                ? "The registrar refused the {$action}: " : "The {$action} was not asked of the registrar: ") . $e->getMessage());

            return null;
        } catch (Throwable $e) {
            // Its outcome is unknown (no answer, a 5xx): left pending, never asked again blindly.
            $log->update(['error' => mb_substr(Redact::text($e->getMessage()), 0, 2000), 'transactions' => $transactions->since($mark)]);
            $this->staffDecides($domain, $line, $connection->registrar, "The {$action} got no clear answer ({$e->getMessage()}): its outcome is checked on the next run; do not renew it by hand before checking the registrar.");

            return null;
        }
        $log->update(['status' => DomainRegistrarLog::STATUS_OK, 'response' => Redact::array(['status' => $result->rawStatus, 'expires_at' => $result->expiresAt]),
            'transactions' => $transactions->since($mark)]);
        $this->sync->applyResult($domain->refresh(), $result);

        return $result;
    }

    /** The whole years of a line's period. */
    private function periodYears(InvoiceItem $line): int
    {
        return max(1, (int) round(Carbon::parse($line->period_start)->diffInYears(Carbon::parse($line->period_end))));
    }

    /** The years the registrar was charged, and answered, for this line. */
    private function chargedYears(InvoiceItem $line): int
    {
        return (int) DomainRegistrarLog::query()->where('domain_id', $line->reference_id)->whereIn('action', ['renew', 'restore'])
            ->where('status', DomainRegistrarLog::STATUS_OK)->where('request->line', $line->id)->get()
            ->sum(fn (DomainRegistrarLog $log) => (int) ($log->request['years'] ?? 0));
    }

    /** The registrar's expiry reaches the end of the paid period. */
    private function covers(DomainSyncResult $result, Carbon $target): bool
    {
        return $result->status === DomainStatus::Active && $result->expiresAt !== null
            && Carbon::parse($result->expiresAt)->greaterThanOrEqualTo($target->copy()->subDays(self::TOLERANCE_DAYS));
    }

    /** The whole years its registrar expiry lacks to reach the end of the paid period (1 to 10). */
    private function lacking(DomainSyncResult $result, Carbon $target): int
    {
        $from = $result->expiresAt !== null ? Carbon::parse($result->expiresAt)->startOfDay() : null;
        $years = 1;
        while ($from !== null && $years < 10 && $from->copy()->addYearsNoOverflow($years)->lessThan($target->copy()->subDays(self::TOLERANCE_DAYS))) {
            $years++;
        }

        return $years;
    }

    /** Its stage's fee, when there is one, is paid (on a paid invoice, billed with or after this renewal). */
    private function stageFeePaid(Domain $domain, InvoiceItem $line, DomainStatus $stage): bool
    {
        $fee = $domain->stageFee($stage);
        if ($fee === null || bccomp($fee, '0', 2) <= 0) {
            return true;
        }

        return $domain->invoiceItems()->whereIn('kind', ['grace_fee', 'redemption_fee'])->where('created_at', '>=', $line->created_at)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_PAID))->exists();
    }

    private function apply(Domain $domain, InvoiceItem $line, DomainRegistrarConnection $connection, ?Staff $staff, DomainSyncResult $result, bool $adopted): string
    {
        $short = !$this->covers($result, Carbon::parse($line->period_end)->startOfDay());
        DB::transaction(function () use ($domain, $line, $connection, $staff, $result, $adopted) {
            $line->forceFill(['applied_at' => now()])->save();
            if ($adopted) {
                DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $connection->id,
                    'staff_id' => $staff?->id, 'invoice_id' => $line->invoice_id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_ADOPTED,
                    'response' => ['status' => $result->rawStatus, 'expires_at' => $result->expiresAt, 'line' => $line->id]]);
            }
            ModuleAction::domainSucceeded($domain, $line);
        });
        // Charged, but the registrar does not show the new expiry yet: applied (it was charged), and staff told.
        if ($short) {
            ModuleAction::domainFailed($domain, $line, $connection->registrar, new RuntimeException(
                "Renewed, but the registrar shows {$result->expiresAt} for a period paid to {$line->period_end->toDateString()}: check it on its next sync, then close this."));
        }
        $this->lifecycle->advance($domain->refresh());
        self::tellRenewed($domain);

        return $adopted ? self::ADOPTED : self::APPLIED;
    }

    /** The customer's «renewed until» (WHMCS's Domain Renewal Confirmation, mail check of 2026-10-21): after it is applied. */
    private static function tellRenewed(Domain $domain): void
    {
        try {
            NotificationHelper::domainNotification($domain->client, $domain, 'domain_renewed', [
                'fqdn' => $domain->fqdn,
                'expires' => $domain->expires_at?->format('d/m/Y'),
            ]);
        } catch (Throwable $e) {
            report($e); // renewed at the registrar: a mail that fails is not a failed renewal
        }
    }

    private function staffDecides(Domain $domain, InvoiceItem $line, ?string $module, string $why): void
    {
        ModuleAction::domainFailed($domain, $line, $module, new RuntimeException($why));
    }

    /**
     * «Renew now» (D-1): staff renew a trusted customer's domain before the payment. The registrar first: its period
     * billed if it is not (an invoice of its own; one already billed is used), then renewed at the registrar for the
     * years its expiry lacks, logged against that invoice; the later payment finds the period covered and adopts it.
     * Never over an unconfirmed charge, nor over a paid renewal already on its way (code review 2).
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
        TimeLimit::atLeast(600);
        try {
            if ($this->unconfirmed($domain) !== null) {
                throw new RuntimeException(__('domains.renew.unconfirmed', ['domain' => $domain->fqdn]));
            }
            if (self::waitingLines()->where('reference_id', $domain->id)->exists()) {
                throw new RuntimeException(__('domains.renew.paid_on_its_way', ['domain' => $domain->fqdn]));
            }
            // Renewed now already, its invoice not paid yet (adversarial ADV-6): not a second year on credit.
            $renewedUnpaid = DomainRegistrarLog::query()->where('domain_id', $domain->id)->whereIn('action', ['renew', 'restore'])
                ->where('status', DomainRegistrarLog::STATUS_OK)->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_UNPAID))->value('invoice_id');
            if ($renewedUnpaid !== null) {
                throw new RuntimeException(__('domains.renew.renewed_unpaid', ['domain' => $domain->fqdn, 'invoice' => $renewedUnpaid]));
            }
            // A name never renewed at its TLD's price (.gr: 2 characters): staff do it by hand, before any invoice (review 2
            // of #202).
            if ($domain->staffPriced()) {
                throw new RuntimeException(__('domains.renew.staff_priced', ['domain' => $domain->fqdn]));
            }
            // Never billed for a period its registrar does not renew (review 2 of #189): checked before the invoice.
            $step = max(1, $this->factory->for($connection)->capabilities()->renewYearStep);
            if ($domain->nextRenewalYears() % $step !== 0) {
                throw new RuntimeException(__('domains.renew.not_in_steps', ['domain' => $domain->fqdn, 'step' => $step, 'years' => $domain->nextRenewalYears()]));
            }
            $now = $this->factory->for($connection)->syncDomain($domain, $this->factory->credentialsFor($connection));
            $this->sync->applyResult($domain, $now);
            $line = $this->billedOrBill($domain->refresh());
            $target = Carbon::parse($line->period_end)->startOfDay();
            if ($this->covers($now, $target)) {
                return $line; // already renewed: its payment adopts it
            }
            $capabilities = $this->factory->for($connection)->capabilities();
            if (!($now->status === DomainStatus::Active || ($now->status === DomainStatus::Grace && $capabilities->renewsInGrace))) {
                throw new RuntimeException(__('domains.renew.not_active_at_registrar', ['domain' => $domain->fqdn, 'status' => (string) $now->rawStatus]));
            }
            if ($this->lacking($now, $target) % max(1, $capabilities->renewYearStep) !== 0) {
                throw new RuntimeException(__('domains.renew.not_in_steps', ['domain' => $domain->fqdn, 'step' => $capabilities->renewYearStep, 'years' => $this->lacking($now, $target)]));
            }
            if ($this->charge($domain, $line, $connection, $staff, 'renew', $this->lacking($now, $target), $target) === null) {
                throw new RuntimeException((string) (ModuleAction::query()->open()->where('domain_id', $domain->id)->where('invoice_item_id', $line->id)->value('last_error')
                    ?? __('domains.renew.unconfirmed', ['domain' => $domain->fqdn])));
            }
            $this->lifecycle->advance($domain->refresh());

            return $line;
        } finally {
            $lock->release();
        }
    }

    /** Its renewal line for the period from its expiry: the one billed, else one on an invoice of its own (the domain locked). */
    private function billedOrBill(Domain $domain): InvoiceItem
    {
        return DB::transaction(function () use ($domain) {
            $domain = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $start = $domain->expires_at->copy()->startOfDay();
            $billed = self::billedLine($domain, $start, payable: true);
            if ($billed !== null) {
                return $billed;
            }
            // The period the cron would bill (its stage's in grace: two years for .gr; adversarial O3 of #189).
            $years = $domain->nextRenewalYears();
            $price = $domain->renewalPriceFor($years)
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
     * Staff say it was renewed by hand (code review 6). A paid line of a live domain only. With a registrar API the
     * registrar must show it (read now, under the lock): the line is adopted, its expiry the registrar's, never ours;
     * with a manual registrar the expiry moves to the end of the paid period.
     *
     * @throws RuntimeException why it was not applied
     */
    public function markRenewedByHand(InvoiceItem $line, ?Staff $staff): void
    {
        $domain = Domain::query()->find($line->reference_id);
        if ($domain === null || !self::waitingLines()->whereKey($line->id)->exists()) {
            throw new RuntimeException(__('domains.renew.not_a_paid_renewal'));
        }
        if (in_array($domain->status->value, DomainStatus::terminalValues(), true)) {
            throw new RuntimeException(__('domains.renew.not_renewable', ['domain' => $domain->fqdn]));
        }
        $connection = $domain->effectiveRegistrarConnection();
        if ($connection instanceof DomainRegistrarConnection && $connection->isUsable()) {
            $lock = Cache::lock("domain-registrar:{$domain->id}", 600);
            if (!$lock->get()) {
                throw new RuntimeException(__('domains.manage.busy', ['domain' => $domain->fqdn]));
            }
            try {
                $now = $this->factory->for($connection)->syncDomain($domain, $this->factory->credentialsFor($connection));
                $this->sync->applyResult($domain, $now);
                if (!$this->covers($now, Carbon::parse($line->period_end)->startOfDay())) {
                    throw new RuntimeException(__('domains.renew.registrar_does_not_show', ['domain' => $domain->fqdn, 'expires' => (string) $now->expiresAt]));
                }
                $this->apply($domain->refresh(), $line->refresh(), $connection, $staff, $now, true);
            } finally {
                $lock->release();
            }

            return;
        }
        DB::transaction(function () use ($domain, $line, $staff) {
            $domain = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail(); // the domain first (D5b-1 lock order)
            $line = InvoiceItem::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($line->applied_at !== null) {
                return;
            }
            $line->forceFill(['applied_at' => now()])->save();
            $end = Carbon::parse($line->period_end);
            if ($domain->expires_at === null || $domain->expires_at->lessThan($end)) {
                $domain->forceFill(['expires_at' => $end])->save(); // audited
            }
            DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $domain->effectiveRegistrarConnection()?->id,
                'staff_id' => $staff?->id, 'invoice_id' => $line->invoice_id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK,
                'response' => ['by_hand' => true, 'expires_at' => $end->toDateString(), 'line' => $line->id]]);
            ModuleAction::domainSucceeded($domain, $line);
        });
        $this->lifecycle->advance($domain->refresh());
        self::tellRenewed($domain);
    }
}
