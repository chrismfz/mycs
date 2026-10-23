<?php

namespace App\Services\Billing;

use App\Enums\DomainStatus;
use App\Events\Invoice as InvoiceEvent;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Domains\DomainRenewalService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Order\OrderPricing;
use App\Services\Service\RenewServiceService;
use App\Support\AfterCommit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Renewal invoicing (docs/billing-cursor.md, PR C; docs/domains.md D5b-1): a service's next period, a domain's, a
 * domain's stage fee, grouped one invoice per customer, due date and currency, then finished (emailed, paid by credit).
 * The cron's invoices_created step, and staff's «Invoice selected items» (D7a), which group per customer and currency
 * only: one invoice for what they selected, as WHMCS's.
 */
final class RenewalBilling
{
    /** @var array<string, int> this run's groups: key => invoice id */
    private array $renewalGroups = [];

    /** @var array<string, Throwable> mailed to staff, the run succeeds */
    public array $notices = [];

    /** @var array<string, Throwable> */
    public array $failures = [];

    /**
     * «Invoice selected items» (D7a): one invoice per customer and currency for what staff selected, whatever the due
     * dates (the first line's, the earliest, is the invoice's), and whatever «Separate invoices» says: staff asked for one.
     */
    public bool $oneInvoicePerCustomer = false;

    /**
     * Credit pays each invoice as it is finished (the cron: its credit step ran before, so only new invoices are left).
     * Off for «Invoice selected items» (adversarial ADV-2): it then pays the customer's oldest due first.
     */
    public bool $creditOnFinish = true;

    /** @var list<int> the invoices made here, committed: «Invoice selected items» finishes these only (D7a). */
    public array $made = [];

    /**
     * Held by an «Invoice selected items» run for each of its customers while it fills their invoices (D7a review F2):
     * the cron does not finish them meanwhile (one email with all the lines); the run finishes them itself.
     */
    public static function selectingLock(int $clientId): Lock
    {
        return Cache::lock("invoice-selected:{$clientId}", 600);
    }

    /** Protected this long by a paid line that is not applied (then the usual rules, after a notice every day): the default. */
    public const PAID_NOT_APPLIED_DAYS = 30;

    /**
     * The days of that protection: the setting (Settings → Cron, «Paid but not applied: protected days»), 1 to 365. One
     * that is not a whole number (empty, text: a setting written outside the form) is the default, never a day (review).
     */
    public static function paidNotAppliedDays(): int
    {
        $days = filter_var(config('settings.cronjob_paid_not_applied_days'), FILTER_VALIDATE_INT);

        return max(1, min(365, $days === false ? self::PAID_NOT_APPLIED_DAYS : $days));
    }

    /**
     * A line of the service (services.id in the outer query) on a paid invoice still to be applied (BIL-9), its own line
     * not applied yet: another service of the same invoice, applied, is not protected by it. For paidNotAppliedDays().
     *
     * @param  Builder  $line
     */
    public static function paidLineToApply($line): void
    {
        $line->from('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_type', Service::class)->whereColumn('invoice_items.reference_id', 'services.id')
            ->whereNull('invoice_items.applied_at')
            ->where('invoices.status', Invoice::STATUS_PAID)
            ->where('invoices.apply_pending_since', '>=', now()->subDays(self::paidNotAppliedDays()));
    }

    /**
     * Bill (or, at zero price, renew) the next period of one service (docs/billing-cursor.md):
     * where the live periods that follow the paid-until date end, never the same period twice,
     * never across a hole. Returns 1 when it billed or renewed.
     */
    public function billRenewal(int $id, CarbonInterface $billUntil): int
    {
        $service = Service::query()->whereKey($id)->lockForUpdate()->first();
        if (!$service || !in_array($service->status, ['active', 'suspended'], true)) {
            return 0;
        }
        // No plan (deleted before BIL-6, or an import with none): nothing to price a period with. Staff set one.
        if ($service->plan === null) {
            $this->notices["invoices_created #{$id}"] = new DisplayException("Service #{$id} has no plan: not billed (set its plan).");

            return 0;
        }
        // One-time or free (D7a adversarial ADV-1): no next period to bill, even with an expiry (imported, or set by staff).
        if (in_array($service->plan->type, ['one-time', 'free'], true)) {
            return 0;
        }

        // Does the service have already an open (pending or draft) invoice?
        if ($service->invoices()->whereIn('status', Invoice::OPEN_STATUSES)->exists() || $service->cancellation()->exists()) {
            return 0;
        }
        // A payment of it still to be applied (BIL-9): its date has not moved yet, billing now would ask for it twice.
        if (Service::query()->whereKey($service->id)->whereExists(fn ($line) => self::paidLineToApply($line))->exists()) {
            return 0;
        }

        // Read once (the history of a service can be long): the live lines, and with them those given up on (Δ3).
        [$lines, $billed] = $service->billedPeriodLines();
        // A suspended service is not billed again (decision A2), except for a hole before a
        // period that is already paid: otherwise that payment would be stranded. And one suspended for non-payment that
        // paid since, still overdue (Δ3a, adversarial D3A-9): its payment did not unsuspend it, its next period is billed
        // so that it has something to pay (else it would be unsuspended, billed and suspended again the same night).
        $paidSinceSuspended = $service->dunning_suspended_at !== null && $lines->contains(fn ($line) => $line->invoice?->status === 'paid'
            && $line->applied_at !== null && $line->applied_at->greaterThanOrEqualTo($service->dunning_suspended_at));
        if ($service->status === 'suspended' && !$paidSinceSuspended && !$lines->contains(fn ($line) => $line->invoice?->status === 'paid'
            && $line->period_start->greaterThan($service->paidUntilBase()))) {
            return 0;
        }

        // Past the periods given up on too (Δ3): a renewal cancelled or refunded is not billed again.
        $periodStart = $service->nextPeriodStart($billed);
        // A period given up on at the paid-until date: nothing after it is billed (its payment would renew nothing behind
        // the gap); the service falls to the suspension and termination rules. Staff close the gap (reopen the invoice,
        // or move the date over it) to bill on. A paid period waiting behind the gap: staff are told.
        if ($service->nextPeriodStart($lines)->lessThan($periodStart)) {
            if ($lines->contains(fn ($line) => $line->invoice?->status === Invoice::STATUS_PAID && $line->period_start->greaterThan($service->paidUntilBase()))) {
                $this->notices["invoices_created #{$id}"] = new DisplayException("Service #{$id}: a paid period waits behind a period given up on (a cancelled or refunded renewal): reopen that invoice, or move the service's date over the gap.");
            }

            return 0;
        }
        if ($periodStart->greaterThanOrEqualTo($billUntil)) {
            return 0;
        }
        // A change of cycle «from the next renewal» (SVC-14b): this period is the first of the plan set to come, at its
        // price. One no longer of the service's product (staff moved it since) is dropped.
        if ($service->next_plan_id !== null) {
            $next = $service->nextPlan;
            if ($next !== null && $next->priceable_type === Product::class && (int) $next->priceable_id === (int) $service->product_id) {
                $service->plan_id = $next->id;
                $service->setRelation('plan', $next);
                $service->price = (float) $service->calculatePrice();
            }
            $service->next_plan_id = null;
            $service->save();
        }
        // A coupon «for N cycles»: the first cycle after them is billed at the full price. Cycles are
        // counted from a persisted start (including free cycles). Expiration is recorded once, so
        // skipping N+1 still ends the discount and a later staff price is kept.
        if ($service->coupon && $service->coupon->cycles() > 0
            && $service->coupon_expired_at === null
            && $service->cycleNumber($periodStart, $lines) > $service->coupon->cycles()) {
            // A first-cycle coupon never was in the renewal price (checkout keeps the full one): nothing to take
            // back, and a recalculation would replace a price staff or an old catalog set.
            if ($service->coupon->cycles() > 1) {
                $service->price = $service->calculatePrice(applyCoupon: false);
            }
            $service->coupon_expired_at = CarbonImmutable::now();
        }
        // Its required product gone (cancelled, terminated; C4 of #274): the discount ends with it, as for its cycles.
        if ($service->coupon && $service->coupon_expired_at === null && !$service->coupon->requirementHeldBy($service->client)) {
            $service->price = $service->calculatePrice(applyCoupon: false);
            $service->coupon_expired_at = CarbonImmutable::now();
        }
        if ($service->isDirty(['price', 'coupon_started_at', 'coupon_expired_at'])) {
            $service->save();
        }

        // If service price is 0, immediately activate next period
        if ($service->price <= 0) {
            (new RenewServiceService)->handle($service);

            return 1;
        }

        $periodEnd = $service->periodEnd($periodStart, $lines);
        $price = $service->price;
        $service->rememberBillingDay($periodStart);
        if ($service->isDirty('billing_day')) {
            $service->save();
        }

        // A hole shorter than a period (the next live period starts sooner): bill only the hole, prorated.
        $nextStart = $billed->first(fn ($line) => $line->period_start->greaterThan($periodStart))?->period_start;
        if ($nextStart !== null && $periodEnd !== null && $nextStart->lessThan($periodEnd)) {
            $price = $service->priceForPeriod($periodStart, $nextStart);
            $periodEnd = $nextStart;
        }

        // Grouped (Δ3, PR C): one invoice per customer, due date and currency in this run, as WHMCS bills what falls due
        // the same day. Not a gateway subscription's renewal (it pays its own invoice, in full), nor a customer with
        // «Separate invoices». The email, credit and saved-method charge come once all its lines are on it
        // (finishRenewalInvoices).
        // A line of nothing (a short prorated hole rounding to zero cents) goes alone: closed as paid, it renews its service
        // at once, not when the rest of a group is paid.
        $key = RecordPaymentService::toCents($price) * (int) $service->quantity > 0 ? $this->renewalGroupKey($service, $periodStart) : null;
        // Lock order: the service (above), then its group's invoice, as for every line of the group; the services of a
        // group are billed one after another, never two at once (one cron run at a time; a group is this instance's own,
        // the cron's or one «Invoice selected items» run's).
        $invoice = $key !== null && isset($this->renewalGroups[$key])
            ? (new RecordPaymentService)->lockInvoice($this->renewalGroups[$key]) : null;
        if ($invoice !== null && ($invoice->status !== Invoice::STATUS_UNPAID || $invoice->finish_pending_at === null
            || (int) $invoice->client_id !== (int) $service->client_id || $invoice->currency_code !== $service->currency_code
            || $invoice->hasPayments(lock: true))) {
            $invoice = null; // paid, finished or changed meanwhile: a new one
        }
        if ($invoice === null) {
            $invoice = $service->invoices()->make([
                'client_id' => $service->client_id,
                'status' => Invoice::STATUS_UNPAID,
                'due_at' => $periodStart,
                'currency_code' => $service->currency_code,
            ]);
            // Finalized, emailed and paid by credit once all its lines are on it (finishRenewalInvoices): marked
            // in the same commit as its first line, so that a run that fails or stops before then leaves it to the next.
            $invoice->finalize_later = true;
            $invoice->forceFill(['finish_pending_at' => now()]);
            $invoice->save();
            // Known to the group once this service's transaction commits (adversarial C1-ADV-1): one rolled back leaves
            // no invoice behind for the next line to land on.
            $this->remember($key, (int) $invoice->id);
        }
        // The line records the period it pays for.
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => $price,
            'quantity' => $service->quantity,
            // With its options (PR C, as WHMCS): one invoice can bill several services of the same product.
            'description' => $service->describeLine($periodStart, $periodEnd) . self::groupNote($service),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return 1;
    }

    /** The domains billed for their next period (D5b): alive, the customer's still (expired, grace, redemption). */
    public const BILLED_DOMAINS = [DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption];

    /** Days before expiry a domain's renewal is invoiced (D-14): its own setting (default 15), empty the services'. */
    public static function domainInvoiceDays(): int
    {
        // Not set: 15 (D7, owner 2026-10-17: both companies alike); set empty: as the services'.
        $days = config('settings.domain_invoice_days', 15);

        return $days === null || $days === '' ? (int) config('settings.cronjob_invoice', 7) : max(0, (int) $days);
    }

    /**
     * Bill a domain's next period (docs/domains.md D-4, D5b): from its expiry, for its years, at its renewal price, due
     * on its expiry (today, past it). A period is billed once, whatever became of its invoice: paid, open, or cancelled by
     * staff (who reopen it, or turn auto renew off: do not renew). Grouped as the services' renewals (PR C).
     */
    public function billDomainRenewal(int $id, CarbonInterface $billUntil): int
    {
        $domain = Domain::query()->whereKey($id)->lockForUpdate()->first();
        if ($domain === null || !in_array($domain->status, self::BILLED_DOMAINS, true) || !$domain->auto_renew || $domain->expires_at === null) {
            return 0;
        }
        $periodStart = $domain->expires_at->copy()->startOfDay();
        if ($periodStart->greaterThanOrEqualTo($billUntil)) {
            return 0;
        }
        // Billed (D5b-1 review 3, ADV-10): one rule with «Renew now» (DomainRenewalService::billedLine).
        if (DomainRenewalService::billedLine($domain, $periodStart) !== null) {
            return 0;
        }
        // Past its last recoverable day (review 4): nothing to renew any more; staff decide.
        $lastDay = $domain->redemptionEndsOn() ?? $domain->graceEndsOn() ?? $domain->expires_at;
        if ($domain->status !== DomainStatus::Active && $lastDay->copy()->startOfDay()->lessThan(today())) {
            $this->notices["invoices_created domain #{$id}"] = new DisplayException("Domain {$domain->fqdn}: past its last recoverable day ({$lastDay->toDateString()}): not billed.");

            return 0;
        }
        // In grace or redemption its TLD's shortest period (D-13: a restore brings back one year; review 8; .gr: two, its
        // periods are even, G4a of grepp), at that period's price.
        $years = $domain->nextRenewalYears();
        // A period its registrar does not renew (.gr: odd years) is never billed: staff decide (review 2 of #189).
        $step = $domain->renewYearStep();
        if ($years % $step !== 0) {
            $this->notices["invoices_created domain #{$id}"] = new DisplayException("Domain {$domain->fqdn}: its registrar renews in steps of {$step} years, not {$years}: not billed (set its renewal period).");

            return 0;
        }
        $price = $domain->renewalPriceFor($years);
        if ($price === null) {
            $this->notices["invoices_created domain #{$id}"] = new DisplayException("Domain {$domain->fqdn}: no renew price for "
                . $years . " year(s) in {$domain->currency_code}: not billed (set one on its TLD or the domain).");

            return 0;
        }
        // Catalog prices are as the customer sees them: net on the line (Δ1; review 7, with prices incl. VAT).
        $price = OrderPricing::toNet($price, $domain->client);
        $periodEnd = $periodStart->copy()->addYearsNoOverflow($years);
        $due = $periodStart->greaterThan(today()) ? $periodStart : today();

        $key = match (true) {
            bccomp($price, '0', 2) <= 0 => null,
            $this->oneInvoicePerCustomer => implode('|', [$domain->client_id, $domain->currency_code]),
            (bool) $domain->client->separate_invoices => null,
            default => implode('|', [$domain->client_id, $due->toDateString(), $domain->currency_code]),
        };
        $invoice = $key !== null && isset($this->renewalGroups[$key]) ? (new RecordPaymentService)->lockInvoice($this->renewalGroups[$key]) : null;
        if ($invoice !== null && ($invoice->status !== Invoice::STATUS_UNPAID || $invoice->finish_pending_at === null
            || (int) $invoice->client_id !== (int) $domain->client_id || $invoice->currency_code !== $domain->currency_code
            || $invoice->hasPayments(lock: true))) {
            $invoice = null;
        }
        if ($invoice === null) {
            $invoice = new Invoice(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => $due,
                'currency_code' => $domain->currency_code]);
            $invoice->finalize_later = true;
            $invoice->forceFill(['finish_pending_at' => now()]);
            $invoice->save();
            $this->remember($key, (int) $invoice->id);
        }
        $invoice->items()->create([
            'reference_type' => Domain::class,
            'reference_id' => $domain->id,
            'price' => $price,
            'quantity' => 1,
            'description' => $domain->describeRenewal($periodStart, $periodEnd, $years),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return 1;
    }

    /**
     * The fee of the stage a domain is in (grace or redemption, D-8: «existing invoice»), on its open renewal invoice: one
     * stage fee per invoice, the current stage's (grace's becomes redemption's). Never on an invoice with money on it
     * (staff are told); a stage without a fee (none, or 0.00) adds nothing.
     */
    public function addStageFee(int $id): void
    {
        $domain = Domain::query()->whereKey($id)->lockForUpdate()->first();
        if ($domain === null || !in_array($domain->status, [DomainStatus::Grace, DomainStatus::Redemption], true) || $domain->expires_at === null) {
            return;
        }
        // A registrar that renews in grace charges no grace fee (the .gr registry: review 6 of #189).
        if ($domain->status === DomainStatus::Grace && $domain->renewsInGrace()) {
            return;
        }
        $fee = $domain->stageFee($domain->status);
        if ($fee === null || bccomp($fee, '0', 2) <= 0) {
            return;
        }
        // Its renewal by the same rule as billing (sweep L2: an expiry a day later than its period's start lost its fee).
        // Never a later period billed ahead (ADV-3 of #310).
        $renewal = $domain->invoiceItems()->whereNull('kind')->whereDate('period_end', '>', $domain->expires_at->toDateString())
            ->whereDate('period_start', '>=', $domain->expires_at->copy()->subDays(DomainRenewalService::SAME_PERIOD_DAYS)->toDateString())
            ->whereDate('period_start', '<=', $domain->expires_at->copy()->addDays(DomainRenewalService::SAME_PERIOD_DAYS)->toDateString())
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', '!=', Invoice::STATUS_CANCELLED))->orderByDesc('id')->first();
        if ($renewal === null) {
            return;
        }
        $kind = $domain->status === DomainStatus::Grace ? 'grace_fee' : 'redemption_fee';
        // Paid already (waiting for its registrar renewal) and now in a stage with a fee: staff decide (adversarial
        // ADV-6), told once.
        if ($renewal->invoice?->status !== Invoice::STATUS_UNPAID) {
            if (!$domain->invoiceItems()->where('kind', $kind)->where('created_at', '>=', $renewal->created_at)->exists()
                && Cache::add("domain-fee-paid-notice:{$renewal->id}:{$kind}", true, now()->addDays(60))) {
                $this->notices["invoices_created domain fee #{$id}"] = new DisplayException("Domain {$domain->fqdn}: its renewal (invoice #{$renewal->invoice_id}) is paid and it is now in {$domain->status->value}: the {$kind} ({$fee}) was not billed, bill it by hand if due.");
            }

            return;
        }
        $invoice = (new RecordPaymentService)->lockInvoice($renewal->invoice_id);
        // This stage's fee of this renewal waived by staff (its invoice cancelled: adversarial ADV-9): never billed again.
        if ($domain->invoiceItems()->where('kind', $kind)->where('created_at', '>=', $renewal->created_at)
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_CANCELLED))->exists()) {
            return;
        }
        // This renewal's stage fee, wherever staff moved it (review 5: a split took it to another invoice): one only.
        $existing = $domain->invoiceItems()->whereIn('kind', ['grace_fee', 'redemption_fee'])->where('created_at', '>=', $renewal->created_at)
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', Invoice::STATUS_CANCELLED))->orderByDesc('id')->first();
        if ($invoice->status !== Invoice::STATUS_UNPAID || $existing?->kind === $kind) {
            return;
        }
        if ($existing !== null && (int) $existing->invoice_id !== (int) $invoice->id) {
            return; // moved by staff: theirs to change
        }
        if ($invoice->hasPayments(lock: true)) {
            // Once per invoice and stage (review 11), not every night of the stage.
            if (Cache::add("domain-fee-notice:{$invoice->id}:{$kind}", true, now()->addDays(60))) {
                $this->notices["invoices_created domain fee #{$id}"] = new DisplayException("Domain {$domain->fqdn}: its renewal invoice #{$invoice->id} has payments: the {$kind} ({$fee}) was not added, bill it by hand.");
            }

            return;
        }
        $line = ['price' => OrderPricing::toNet($fee, $domain->client), 'quantity' => 1, 'kind' => $kind,
            'description' => __('domains.invoice.' . $kind, ['domain' => $domain->fqdn])];
        $existing !== null ? $existing->update($line) // grace's fee becomes redemption's
            : $invoice->items()->create($line + ['reference_type' => Domain::class, 'reference_id' => $domain->id]);
        // Its new total is sent again (review 6) before any charge of it; one still being made is sent once finished.
        if ($invoice->finish_pending_at === null) {
            AfterCommit::event(new InvoiceEvent\Finalized($invoice->refresh(), true), $invoice);
        }
    }

    /** A new invoice, known once its line's transaction commits: to its group (if any), and as made here. */
    private function remember(?string $key, int $id): void
    {
        DB::afterCommit(function () use ($key, $id) {
            if ($key !== null) {
                $this->renewalGroups[$key] = $id;
            }
            $this->made[] = $id;
        });
    }

    /**
     * The renewal invoices of this run (one per group, PR C), its key: customer, due date and currency; null
     * for one billed alone (a gateway subscription's renewal, a customer with «Separate invoices»).
     */
    private function renewalGroupKey(Service $service, CarbonInterface $periodStart): ?string
    {
        if ((string) $service->subscription_id !== '') {
            return null;
        }
        if ($this->oneInvoicePerCustomer) {
            return implode('|', [$service->client_id, $service->currency_code]);
        }
        if ($service->client?->separate_invoices) {
            return null;
        }

        return implode('|', [$service->client_id, $periodStart->toDateString(), $service->currency_code]);
    }

    /**
     * Each renewal invoice still to finish (PR C), once all its lines are on it: this run's, and any a previous run left
     * (a failure, a crash). Its Finalized event (the email) fires, nothing to pay closes it as paid and credit pays it
     * (MT-1e); its saved method is charged on its due date (invoices_charged, PR H). One invoice per transaction, which
     * clears its mark: finished once. A credit failure is reported and does not hold back the email; any other failure
     * leaves it marked, for the next run. With $only, those only (D7a: not the invoices a cron run is still adding to).
     *
     * @param  list<int>|null  $only
     */
    public function finishRenewalInvoices(?array $only = null): void
    {
        $this->renewalGroups = [];
        $ids = Invoice::query()->whereNotNull('finish_pending_at')->when($only !== null, fn ($q) => $q->whereKey($only))->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id) {
                    $invoice = (new RecordPaymentService)->lockInvoice($id);
                    if ($invoice->finish_pending_at === null) {
                        return;
                    }
                    // Still being filled by staff (D7a review F2): theirs to finish, now; else a leftover, finished here.
                    if (!in_array($id, $this->made, true)) {
                        $guard = self::selectingLock((int) $invoice->client_id);
                        if (!$guard->get()) {
                            return;
                        }
                        $guard->release();
                    }
                    $invoice->forceFill(['finish_pending_at' => null])->saveQuietly();
                    if ($invoice->status !== Invoice::STATUS_UNPAID) {
                        return; // staff cancelled or settled it meanwhile
                    }
                    AfterCommit::event(new InvoiceEvent\Finalized($invoice, true), $invoice);
                    // A short prorated hole can round to zero cents; no gateway can collect that amount.
                    if ($invoice->total <= 0) {
                        $invoice->update(['status' => Invoice::STATUS_PAID]);

                        return;
                    }
                    // A gateway subscription pays its own invoice; any other is charged to the account's saved method (decision 6).
                    $service = Service::query()->whereIn('id', $invoice->items()->where('reference_type', Service::class)->select('reference_id'))
                        ->orderBy('id')->first();
                    $subscription = (string) $service?->subscription_id !== '';
                    $agreement = $subscription ? null : $invoice->client?->autoChargeAgreement();
                    if (!$this->creditOnFinish) {
                        return;
                    }
                    try {
                        DB::transaction(fn () => $this->payInvoiceWithCredits($invoice, $subscription, notify: $agreement === null));
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["invoices_created credit invoice #{$id}"] = $e;
                    }
                    // Its saved method is charged on its due date by invoices_charged (PR H), not now.
                });
            } catch (Throwable $e) {
                report($e);
                $this->failures["invoices_created invoice #{$id}"] = $e;
            }
        }
    }

    private function payInvoiceWithCredits(Invoice $invoice, bool $subscription, bool $notify): void
    {
        // As much as the credit covers, also in part (decision 5, MT-1e); read and spent under the payment locks.
        // Off with credits, or with "Automatically use credits", or for the invoices CreditAutoApply leaves alone.
        // A gateway subscription's renewal only in full: a subscription sale that became credit pays it.
        if ($subscription) {
            (new CreditAutoApply)->toSubscriptionRenewal($invoice);

            return;
        }
        (new CreditAutoApply)->toInvoice($invoice, notify: $notify);
    }

    /** The group discount its price holds (services.group_percent), said on its line (ROADMAP 5: in the price). */
    private static function groupNote(Service $service): string
    {
        if ($service->price_locked || (float) $service->group_percent <= 0) {
            return '';
        }

        // In the customer's language, as its PDF (#264): the cron runs in the system's.
        return ' ' . __('invoices.group_discount_note', ['percent' => rtrim(rtrim(number_format((float) $service->group_percent, 2, '.', ''), '0'), '.')],
            NotificationHelper::localeFor($service->client));
    }
}
