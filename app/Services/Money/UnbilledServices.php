<?php

namespace App\Services\Money;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\DunningRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The money check's `service_unbilled` (owner 2026-11-10, docs/money-trail.md §5): every service that runs without being
 * billed. Read-only, and no new money logic: it finds and says, staff decide. An active service on a recurring plan that
 *  - has a price and no due date (never billed, never suspended);
 *  - holds a period given by an invoice that is not paid (its payment deleted, edited down or charged back; cancelled,
 *    refunded), runs on a package whose upgrade invoice is unpaid again, or runs past the due date of an unpaid invoice
 *    billing it that carries no period (as WHMCS left it);
 *  - is past due and nothing asks for the money: no unpaid invoice, no payment on its way, no cancellation at the end
 *    of its period, and either the period was given up (a cancelled renewal: the cron never bills it again) or it is
 *    still active two days after the cron should have suspended it (not held, its account not exempt);
 *  - is the addon of a service that ended.
 * An account exempt from the cron's suspensions is billed as any other (RenewalBilling): with no suspension to follow,
 * this rule is all that watches it (review of #783).
 * A handful of queries whatever the number of services (dates compared in PHP: SQLite keeps a time part on them).
 */
final class UnbilledServices
{
    public const NO_DUE_DATE = 'no_due_date';

    public const PERIOD_NOT_PAID = 'period_not_paid';

    public const PAST_DUE = 'past_due';

    public const ADDON_OF_ENDED = 'addon_of_ended';

    /** Days past Suspend Days an active service may still be waiting for the cron (a failed run, the hourly window). */
    public const GRACE_DAYS = 2;

    /** @var list<array{kind: string, service: int, detail: string}> */
    private array $found = [];

    /** @return list<array{kind: string, service: int, detail: string}> by service */
    public function find(): array
    {
        $this->found = [];
        $today = CarbonImmutable::today();
        $this->noDueDate();
        $this->periodNotPaid($today);
        $this->upgradeNotPaid();
        $this->runsPastUnpaid($today);
        $this->pastDue($today);
        $this->addonOfEnded();
        usort($this->found, fn (array $a, array $b) => [$a['service'], $a['kind'], $a['detail']] <=> [$b['service'], $b['kind'], $b['detail']]);

        return $this->found;
    }

    /**
     * Running and billed by period: active, a recurring plan (a one-time or free one has no next period; one with no
     * plan the cron tells staff of every night).
     *
     * @return Builder<Service>
     */
    private static function running(): Builder
    {
        return Service::query()->where('services.status', Service::STATUS_ACTIVE)
            ->whereHas('plan', fn ($plan) => $plan->whereNotIn('type', ['free', 'one-time']));
    }

    /**
     * A line that bills a period of its service (not a charge of it, nor a discount on one).
     *
     * @param  Builder<InvoiceItem>  $lines
     * @return Builder<InvoiceItem>
     */
    private static function periodLines(Builder $lines): Builder
    {
        return $lines->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('services', 'services.id', '=', 'invoice_items.reference_id')
            ->where('invoice_items.reference_type', Service::class)
            ->where(fn ($line) => $line->whereNull('invoice_items.kind')
                ->orWhereNotIn('invoice_items.kind', [InvoiceItem::KIND_CHARGE, InvoiceItem::KIND_DISCOUNT]))
            // Its customer's: an invoice the service left behind with its first customer is not its bill.
            ->whereColumn('invoices.client_id', 'services.client_id')
            ->whereIn('services.id', self::running()->select('services.id'))
            ->orderBy('invoice_items.id')
            ->select(['invoice_items.id', 'invoice_items.invoice_id', 'invoice_items.reference_id', 'invoice_items.period_start',
                'invoice_items.period_end', 'services.expires_at as service_expires_at']);
    }

    private function add(string $kind, int $service, string $detail): void
    {
        $this->found[] = ['kind' => $kind, 'service' => $service, 'detail' => $detail];
    }

    private static function day(mixed $date): string
    {
        return CarbonImmutable::parse($date)->toDateString();
    }

    /**
     * No due date, and a price: the cron reads the date to bill and to suspend, so it does neither. At 0.00 there is
     * nothing to bill: a WHMCS «Free Account» comes exactly so (no date, 0.00 kept), on purpose.
     */
    private function noDueDate(): void
    {
        foreach (self::running()->whereNull('expires_at')->where('price', '>', 0)->orderBy('id')->pluck('id') as $id) {
            $this->add(self::NO_DUE_DATE, (int) $id, 'recurring service with no due date (never billed, never suspended): set its next due date');
        }
    }

    /**
     * The period it runs on was given (applied) by an invoice that is not paid now: the customer paid and the service
     * renewed, then the payment was deleted, edited down or charged back (unpaid again), or the invoice was cancelled or
     * refunded. Reminders go for an unpaid one, but the suspension counts from the new due date, and no next invoice is
     * issued while it is open. Only while the service still holds that period (staff moving the date back ends it) and
     * is active: once the cron suspends it, Suspend Days after the new date, it runs no more. An invoice staff marked
     * paid is paid: their word (paid_balance shows it). A period billed again since and paid with money (staff moved the
     * date back and re-issued it: the rule's own advice, REV-783-4) is paid for.
     */
    private function periodNotPaid(CarbonImmutable $today): void
    {
        $lines = self::periodLines(InvoiceItem::query())
            ->whereNotNull('invoice_items.applied_at')->whereNotNull('invoice_items.period_end')
            ->where('invoices.status', '!=', Invoice::STATUS_PAID)
            ->get()
            ->filter(function (InvoiceItem $line) use ($today) {
                if ($line->getAttribute('service_expires_at') === null) {
                    return false;
                }
                $runsTo = self::day($line->getAttribute('service_expires_at'));
                $end = $line->period_end->toDateString();

                // The last period it was given (it runs to its end, overdue or not), or one before a later paid one
                // while it still runs: never an old story of a service long renewed since.
                return $runsTo === $end || ($runsTo > $end && $end > $today->toDateString());
            });
        if ($lines->isEmpty()) {
            return;
        }
        $paidFor = InvoiceItem::query()->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_type', Service::class)->whereIn('invoice_items.reference_id', $lines->pluck('reference_id')->unique()->all())
            ->where('invoices.status', Invoice::STATUS_PAID)->whereNull('invoices.paid_by_staff_at')
            ->whereNotNull('invoice_items.period_start')->whereNotNull('invoice_items.period_end')
            ->get(['invoice_items.id', 'invoice_items.reference_id', 'invoice_items.period_start', 'invoice_items.period_end'])->groupBy('reference_id');
        $invoices = Invoice::query()->whereKey($lines->pluck('invoice_id')->unique()->all())->with(['items', 'adjustmentNotes', 'transactions'])->get()->keyBy('id');
        // When its money was last taken back: the journal's last reversal (a payment deleted or edited) or refund (a chargeback).
        $since = MoneyEvent::query()->whereIn('invoice_id', $invoices->keys()->all())
            ->whereIn('kind', [MoneyEventKind::Reversal->value, MoneyEventKind::Refund->value])
            ->selectRaw('invoice_id, MAX(occurred_at) as taken_back_at')->groupBy('invoice_id')->pluck('taken_back_at', 'invoice_id');
        foreach ($lines as $line) {
            $invoice = $invoices[$line->invoice_id];
            $runsTo = self::day($line->getAttribute('service_expires_at'));
            $end = $line->period_end->toDateString();
            // No start (a line written around the model): only its end is known, and said.
            $start = $line->period_start?->toDateString();
            if (($paidFor[$line->reference_id] ?? collect())->contains(fn (InvoiceItem $paid) => $paid->period_start->toDateString() < $end
                && $paid->period_end->toDateString() > ($start ?? $line->period_end->subDay()->toDateString()))) {
                continue;
            }
            if (in_array($invoice->status, Invoice::OPEN_STATUSES, true)) {
                if (RecordPaymentService::toCents($invoice->remaining) <= 0) {
                    continue; // covered, to be closed as paid: open_settled says so
                }
                $owes = number_format(RecordPaymentService::toCents($invoice->remaining) / 100, 2, '.', '');
                // Either half is safe: the date moved back by staff un-applies the line, and its payment then renews the
                // service as any open renewal (Service::applyStaffChanges, sweep 10).
                $this->add(self::PERIOD_NOT_PAID, (int) $line->reference_id, "runs to {$runsTo} on invoice #{$invoice->id}, which is {$invoice->status} again"
                    . (isset($since[$invoice->id]) ? ' since ' . self::day($since[$invoice->id]) : '')
                    . " (owes {$owes} {$invoice->currency_code}): collect it, or move the due date back" . ($start !== null ? " to {$start}" : ''));
            } elseif (in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true)) {
                // Not «paid»: a payment that landed between the two reads is no finding.
                $this->add(self::PERIOD_NOT_PAID, (int) $line->reference_id, "runs to {$runsTo} on invoice #{$invoice->id}, which is {$invoice->status}: "
                    . 'nothing pays for the period ' . ($start !== null ? "from {$start}" : "to {$end}") . ': bill it again, or move the due date back');
            }
        }
    }

    /**
     * The other thing a payment gives a service (REV-783-2): an upgrade paid and done, then its payment deleted, edited
     * down or charged back. Its invoice owes again and the service keeps the bigger package, while it is still on the
     * product, plan and options that upgrade gave it. A cancelled or refunded upgrade invoice is staff's own doing and is not told.
     * Cancelling it changes no package (nothing reads an upgrade's invoice once it is completed), so the advice names
     * both steps: the package changed back alone leaves an invoice that applies nothing when paid (S10-STAFF-FREEDOM-2).
     */
    private function upgradeNotPaid(): void
    {
        $upgrades = ServiceUpgrade::query()->join('services', 'services.id', '=', 'service_upgrades.service_id')
            ->join('invoices', 'invoices.id', '=', 'service_upgrades.invoice_id')
            ->where('service_upgrades.status', ServiceUpgrade::STATUS_COMPLETED)
            ->whereIn('invoices.status', Invoice::OPEN_STATUSES)
            ->whereColumn('invoices.client_id', 'services.client_id')
            ->whereIn('services.id', self::running()->select('services.id'))
            ->orderBy('service_upgrades.id')->with('product:id,name')
            ->get(['service_upgrades.id', 'service_upgrades.service_id', 'service_upgrades.product_id', 'service_upgrades.plan_id', 'service_upgrades.from_product_id', 'service_upgrades.invoice_id', 'service_upgrades.applied_at'])
            // Still on what it gave: the product, the plan and its options (REV-805-2), not once staff put them back.
            ->filter(fn (ServiceUpgrade $upgrade) => ($service = Service::query()->find($upgrade->service_id)) !== null && $upgrade->heldBy($service));
        if ($upgrades->isEmpty()) {
            return;
        }
        $invoices = Invoice::query()->whereKey($upgrades->pluck('invoice_id')->unique()->all())->with(['items', 'adjustmentNotes', 'transactions'])->get()->keyBy('id');
        foreach ($upgrades as $upgrade) {
            $invoice = $invoices[$upgrade->invoice_id];
            $owes = RecordPaymentService::toCents($invoice->remaining);
            if ($owes <= 0 || !in_array($invoice->status, Invoice::OPEN_STATUSES, true)) {
                continue;
            }
            $this->add(self::PERIOD_NOT_PAID, (int) $upgrade->service_id, 'runs on ' . ($upgrade->product->name ?? "product #{$upgrade->product_id}")
                . ($upgrade->applied_at !== null ? ' since ' . self::day($upgrade->applied_at) : '') . " by upgrade invoice #{$invoice->id}, which is {$invoice->status} again (owes "
                . number_format($owes / 100, 2, '.', '') . " {$invoice->currency_code}): collect it, or cancel the invoice and change the package back (product, plan and price)");
        }
    }

    /**
     * The same, for a line with no period (an invoice the WHMCS importer brought, or one staff made by hand: it takes
     * its period when it is paid): unpaid and past its due date, while its service is active with a due date still to
     * come. WHMCS let it run (its date moved by hand, or a later invoice paid first). The ordinary overdue customer is
     * not this: their service's due date has passed with the invoice's, and the cron's suspension follows it.
     */
    private function runsPastUnpaid(CarbonImmutable $today): void
    {
        $lines = self::periodLines(InvoiceItem::query())
            ->whereNull('invoice_items.applied_at')->whereNull('invoice_items.period_start')
            ->where('invoices.status', Invoice::STATUS_UNPAID)
            ->whereNotNull('invoices.due_at')->whereDate('invoices.due_at', '<', $today->toDateString())
            ->whereDate('services.expires_at', '>', $today->toDateString())
            ->get();
        if ($lines->isEmpty()) {
            return;
        }
        $invoices = Invoice::query()->whereKey($lines->pluck('invoice_id')->unique()->all())->with(['items', 'adjustmentNotes', 'transactions'])->get()->keyBy('id');
        foreach ($lines->unique(fn (InvoiceItem $line) => $line->invoice_id . '|' . $line->reference_id) as $line) {
            $invoice = $invoices[$line->invoice_id];
            if (RecordPaymentService::toCents($invoice->remaining) <= 0) {
                continue; // covered, to be closed as paid: open_settled says so
            }
            $owes = number_format(RecordPaymentService::toCents($invoice->remaining) / 100, 2, '.', '');
            $this->add(self::PERIOD_NOT_PAID, (int) $line->reference_id, 'runs to ' . self::day($line->getAttribute('service_expires_at'))
                . " while invoice #{$invoice->id} that bills it is unpaid, due since {$invoice->due_at->toDateString()}"
                . " (owes {$owes} {$invoice->currency_code}): collect it, cancel it, or move the due date back");
        }
    }

    /**
     * Past due, and nothing asks for the money: no unpaid invoice billing it (a draft is never sent or reminded, and
     * keeps the cron from billing: it asks nobody, as the cron's own notice reads it), no payment on its way, no
     * cancellation at the end of its period (it ends on its date; an «immediate» one left on an active service, as the
     * importer brings them, is never processed by the cron). Then either its period was given up (a renewal cancelled
     * or refunded: the cron never bills it again, RenewalBilling), told from its first day past due, held or not and
     * whatever its account (a hold or an exemption stops a suspension, not a bill); or it is still active GRACE_DAYS
     * after Suspend Days, when the cron should have billed it and then suspended it (the suspension is off, or asks for
     * an overdue invoice, or the cron did not run): not while a hold or its account's exemption says why it was not.
     * The ordinary overdue customer has an unpaid invoice; a service suspended for non-payment is the dunning's.
     */
    private function pastDue(CarbonImmutable $today): void
    {
        $services = self::running()
            ->whereNotNull('expires_at')->whereDate('expires_at', '<', $today->toDateString())
            ->whereDoesntHave('cancellation', fn ($request) => $request->where('type', 'end_of_period'))
            ->whereDoesntHave('renewalInvoices', fn ($invoices) => $invoices->where('invoices.status', Invoice::STATUS_UNPAID)
                ->whereColumn('invoices.client_id', 'services.client_id'))
            // A payment on its way, as the dunning reads it: a processing payment, a paid line still to be applied (BIL-9).
            ->whereDoesntHave('invoices', fn ($invoices) => $invoices->where('invoices.status', Invoice::STATUS_UNPAID)
                ->whereHas('transactions', fn ($payments) => $payments->where('status', InvoiceTransactionStatus::Processing)))
            ->whereNotExists(fn ($line) => RenewalBilling::paidLineToApply($line))
            ->with('parent:id,suspend_hold_until')
            ->orderBy('id')->get(['id', 'expires_at', 'suspend_hold_until', 'parent_service_id']);
        if ($services->isEmpty()) {
            return;
        }
        $ids = $services->pluck('id')->all();
        $exempt = Service::query()->whereKey($ids)->whereHas('client', fn ($client) => Client::whereFlag($client, 'dunning_exempt'))->pluck('id')->flip();
        $givenUp = InvoiceItem::query()->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_type', Service::class)->whereIn('invoice_items.reference_id', $ids)
            ->whereNotNull('invoice_items.forgone_at')->whereNotNull('invoice_items.period_start')->whereNotNull('invoice_items.period_end')
            ->orderBy('invoice_items.id')
            ->get(['invoice_items.id', 'invoice_items.invoice_id', 'invoice_items.reference_id', 'invoice_items.period_start', 'invoice_items.period_end', 'invoices.status as invoice_status'])
            ->groupBy('reference_id');
        $late = $today->subDays(DunningRules::days('cronjob_order_suspend', 8) + self::GRACE_DAYS)->toDateString();
        foreach ($services as $service) {
            $due = self::day($service->expires_at);
            $line = ($givenUp[$service->id] ?? collect())
                ->first(fn (InvoiceItem $line) => $line->period_start->toDateString() <= $due && $line->period_end->toDateString() > $due);
            if ($line !== null) {
                $this->add(self::PAST_DUE, (int) $service->id, "past due since {$due}, still active, with no unpaid invoice: its period from "
                    . "{$line->period_start->toDateString()} was given up (invoice #{$line->invoice_id}, {$line->getAttribute('invoice_status')}): re-issue it, or move the due date");
            } elseif ($due < $late && !isset($exempt[$service->id]) && !$service->holdInForce($today)) {
                $this->add(self::PAST_DUE, (int) $service->id, "past due since {$due}, still active, with no unpaid invoice: nothing asks for the money "
                    . '(not billed, or only on a draft; not suspended): bill it (Invoice selected items), or set its due date');
            }
        }
    }

    /** An addon runs with its parent, on its parent's dates (docs/addons.md): active after its parent ended, it runs alone. */
    private function addonOfEnded(): void
    {
        $addons = self::running()->whereHas('parent', fn ($parent) => $parent->whereIn('status', Service::ENDED_STATUSES))
            ->with('parent:id,status')->orderBy('id')->get(['id', 'parent_service_id']);
        foreach ($addons as $addon) {
            $this->add(self::ADDON_OF_ENDED, (int) $addon->id, "active addon of service #{$addon->parent_service_id}, which is {$addon->parent?->status}: end it too, or it runs on its own");
        }
    }
}
