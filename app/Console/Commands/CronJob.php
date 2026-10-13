<?php

namespace App\Console\Commands;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice as InvoiceEvent;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\CronStat;
use App\Models\DebugLog;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class CronJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cron-job';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run automated tasks';

    private int $successFullCharges = 0;

    /** @var array<string, Throwable> job (or job #service) => what failed */
    private array $failures = [];

    /** @var array<string, Throwable> what staff must look at, though nothing failed (an invoice left open): mailed, the run succeeds */
    private array $notices = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Config::set('audit.console', true);

        // Each job commits on its own (renewals: per service): one failure no longer rolls back the
        // whole run, nothing stays locked for the whole run, and each step sees what the previous
        // ones committed (docs/billing-cursor.md).
        try {
            // Send invoices if due date is x days away
            // Paid invoices whose lines were not applied (the work after the payment's commit failed: a crash, a
            // server error): applied again, and their Paid event fired then (audit sweep BIL-9). Only those MyCS marked
            // paid; a few minutes old, so a payment being applied right now is left to finish. Until applied (for
            // PAID_NOT_APPLIED_DAYS at most), the services of its lines not applied are neither billed again, suspended
            // nor terminated. One invoice at a time; one that fails again is reported and tried again on the next run.
            $this->runCronJob('paid_applied', function ($number = 0) {
                $ids = Invoice::query()->where('status', Invoice::STATUS_PAID)->whereNotNull('apply_pending_since')
                    ->where('apply_pending_since', '<', now()->subMinutes(10))->orderBy('id')->pluck('id');
                foreach ($ids as $id) {
                    $invoice = null;
                    try {
                        $invoice = Invoice::query()->whereKey($id)->where('status', Invoice::STATUS_PAID)->first();
                        if ($invoice === null || $invoice->apply_pending_since === null) {
                            continue;
                        }
                        if (!$this->keepsItsMoney($invoice)) {
                            // Refunded (it stays paid) or its money moved to credit: staff decide, nothing is renewed.
                            Invoice::query()->whereKey($id)->update(['apply_pending_since' => null]);
                            $this->notices["paid_applied #{$id}"] = new DisplayException("Invoice #{$id} was paid but not applied, and its money was refunded or moved to credit since: not applied.");

                            continue;
                        }
                        (new ProcessPaidInvoiceService)->handle($invoice);
                        $number++;
                    } catch (Throwable $e) {
                        report($e);
                        $until = $invoice?->apply_pending_since?->addDays(self::PAID_NOT_APPLIED_DAYS)->toDateString();
                        $this->notices["paid_applied #{$id}"] = new DisplayException("Invoice #{$id} is paid but its lines could not be applied: {$e->getMessage()}. Tried again on the next run; the services of the lines not applied are neither billed nor suspended until {$until}.");

                        continue;
                    }
                    try {
                        event(new InvoiceEvent\Paid($invoice)); // the one its failed application never reached
                    } catch (Throwable $e) {
                        report($e);
                        $this->notices["paid_applied #{$id} paid event"] = new DisplayException("Invoice #{$id} was applied, but its paid notifications failed: {$e->getMessage()}");
                    }
                }

                return $number;
            }, transaction: false);

            // Credit pays what is open, oldest due first, before new renewals take it and before anything is suspended
            // (decision 5, MT-1e): a customer with credit is not suspended while it sits unused. Each invoice in its own
            // transaction, under the payment locks.
            $this->runCronJob('credit_applied', fn () => (new CreditAutoApply)->sweep(function (int $userId, Throwable $e) {
                $this->failures["credit_applied user #{$userId}"] = $e;
            }), transaction: false);

            $this->runCronJob('invoices_created', function ($number = 0) {
                $billUntil = now()->addDays((int) config('settings.cronjob_invoice', 7));
                $ids = Service::whereIn('status', ['active', 'suspended'])->where('expires_at', '<', $billUntil)->pluck('id');
                foreach ($ids as $id) {
                    try {
                        // One short transaction per service, on a fresh locked row: a payment committed
                        // while the cron runs is seen, and nothing stays locked for the whole run.
                        $number += DB::transaction(fn () => $this->billRenewal($id, $billUntil));
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["invoices_created #{$id}"] = $e;
                    }
                }

                return $number;
            }, transaction: false);

            $this->runCronJob('orders_cancelled', function ($number = 0) {
                // Cancel services if first invoice is not paid after x days. One service per transaction: an invoice
                // that cannot be cancelled rolls back its own service only, never the step (audit sweep BIL-10).
                // 0 disables (an order placed a minute ago would go, with the invoice its customer may be paying).
                $days = (int) config('settings.cronjob_order_cancel', 7);
                $ids = $days <= 0 ? collect() : Service::where('status', 'pending')->whereDoesntHave('invoices', function ($query) {
                    $query->where('status', 'paid');
                })->where('created_at', '<', now()->subDays($days))->pluck('id');
                foreach ($ids as $id) {
                    try {
                        $number += DB::transaction(function () use ($id) {
                            $service = Service::query()->whereKey($id)->lockForUpdate()->first();
                            if ($service === null || $service->status !== Service::STATUS_PENDING) {
                                return 0;
                            }
                            $this->cancelOrderInvoices($service);
                            $service->update(['status' => 'cancelled']);
                            if ($service->product?->stock !== null) {
                                $service->product->increment('stock', $service->quantity);
                            }

                            return 1;
                        });
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["orders_cancelled #{$id}"] = $e;
                    }
                }

                return $number;
            }, transaction: false);

            $this->runCronJob('upgrade_invoices_updated', function ($number = 0) {
                // Pending upgrades: cancelled when their service has ended or reached its next due date unpaid, else
                // their line follows the price (audit sweep SVC-6, BIL-11). One upgrade per transaction, read again
                // under the service's and its own lock (the order ServiceUpgradeService takes).
                foreach (ServiceUpgrade::query()->where('status', ServiceUpgrade::STATUS_PENDING)->pluck('id') as $id) {
                    try {
                        $number += DB::transaction(fn () => $this->refreshUpgrade((int) $id));
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["upgrade_invoices_updated #{$id}"] = $e;
                    }
                }

                return $number;
            }, transaction: false);

            $this->runCronJob('cancellations_processed', function ($number = 0) {
                // Cancellation requests «at the end of the billing period», on that day (WHMCS: Process Cancellation
                // Requests): the service ends as the customer asked, before anything would suspend it as unpaid.
                // Day-granular like WHMCS: due today ends at this run. A service without an expiry (one-time, free)
                // has no end of period; its request waits for staff.
                $ids = Service::whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
                    ->where('expires_at', '<=', now()->endOfDay())
                    ->whereHas('cancellation', fn ($query) => $query->where('type', 'end_of_period'))
                    ->pluck('id');
                foreach ($ids as $id) {
                    try {
                        $number += DB::transaction(fn () => $this->endAsRequested($id));
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["cancellations_processed #{$id}"] = $e;
                    }
                }

                return $number;
            }, transaction: false);

            $this->runCronJob('services_suspended', function ($number = 0) {
                if (!config('settings.cronjob_suspend_enabled', true)) {
                    return 0; // Enable Suspension off (as WHMCS)
                }
                // Suspend services Suspend Days past their next due date (as WHMCS). With «suspend only with an overdue
                // invoice» (audit sweep BIL-5, off by default) an open invoice of the service must be overdue too,
                // and a service past due with no open invoice is reported instead. Never while a payment is on its
                // way (SEPA, PAY-9). One service per transaction, the rule read again under its lock (a payment or a
                // hold that just committed).
                $requiresInvoice = (bool) config('settings.cronjob_suspend_requires_invoice', false);
                if ($requiresInvoice) {
                    $notices = $this->suspendable()->whereDoesntHave('invoices', fn ($query) => $query->where('invoices.status', Invoice::STATUS_PENDING))->pluck('id');
                    foreach ($notices as $id) {
                        $this->notices["services_suspended #{$id}"] = new DisplayException("Service #{$id} is past due with no open invoice: not suspended (bill it, or set its due date).");
                    }
                }
                foreach ($this->suspendable(overdueInvoice: $requiresInvoice)->pluck('id') as $id) {
                    try {
                        $number += DB::transaction(function () use ($id, $requiresInvoice) {
                            $service = $this->suspendable(overdueInvoice: $requiresInvoice)->whereKey($id)->lockForUpdate()->first();
                            if ($service === null) {
                                return 0;
                            }
                            // Register before Service Updated listeners, but execute only after the status commits.
                            SuspendJob::dispatch($service)->afterCommit();
                            $service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now()])->save();

                            return 1;
                        });
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["services_suspended #{$id}"] = $e;
                    }
                }

                return $number;
            }, transaction: false);

            $this->runCronJob('services_terminated', function ($number = 0) {
                if (!config('settings.cronjob_terminate_enabled', true)) {
                    return 0; // Enable Termination off (as WHMCS)
                }
                // Terminate services Termination Days past due (as WHMCS), suspended for non-payment, and suspended at
                // least «minimum days suspended» ago (1 by default: never on the day of the suspension, audit sweep
                // BIL-4). One service per transaction (BIL-10), the rule read again under its lock.
                foreach ($this->terminable()->pluck('id') as $id) {
                    try {
                        $number += DB::transaction(function () use ($id) {
                            $service = $this->terminable()->whereKey($id)->lockForUpdate()->first();
                            if ($service === null) {
                                return 0;
                            }
                            TerminateJob::dispatch($service)->afterCommit();
                            $service->update(['status' => 'cancelled']);
                            // Only its own lines: an invoice imported from WHMCS bills every service due that day.
                            // Upgrades first: service, upgrade, then invoices (the order ServiceUpgradeService takes).
                            $this->leftOpen(CancelPendingUpgrades::forService($service, __('invoices.cancellation_reasons.service_terminated')));
                            $this->cancelInvoices($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get(), __('invoices.cancellation_reasons.service_terminated'), $service);
                            if ($service->product?->stock !== null) {
                                $service->product->increment('stock', $service->quantity);
                            }

                            return 1;
                        });
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["services_terminated #{$id}"] = $e;
                    }
                }

                return $number;
            }, transaction: false);

            $this->runCronJob('tickets_closed', function ($number = 0) {
                // Close tickets with no response for x days (0 disables, as WHMCS)
                $days = (int) config('settings.cronjob_close_ticket', 5);
                if ($days <= 0) {
                    return 0;
                }
                Ticket::where('status', 'replied')->each(function ($ticket) use (&$number, $days) {
                    $lastMessage = $ticket->messages()->latest('created_at')->first();
                    if ($lastMessage && $lastMessage->created_at < now()->subDays($days)) {
                        $ticket->update(['status' => 'closed']);
                        $number++;
                    }
                });

                return $number;
            });

            $this->runCronJob('email_logs_deleted', function ($number = 0) {
                // Delete email logs older than x days; 0 keeps them (it would wipe the whole log).
                $days = (int) config('settings.cronjob_delete_email_logs', 90);
                if ($days <= 0) {
                    return 0;
                }

                return EmailLog::where('created_at', '<', now()->subDays($days))->delete();
            });

            if ($this->notices !== []) {
                $notices = e(implode("\n", array_map(fn ($key, $notice) => "{$key}: {$notice->getMessage()}", array_keys($this->notices), $this->notices)));
                NotificationHelper::sendSystemEmailNotification('Cron Job: needs staff attention', <<<HTML
                    The cron job left these for staff:<br>
                    <pre>{$notices}</pre>
                    HTML);
            }

            if ($this->failures !== []) {
                throw reset($this->failures);
            }
        } catch (Throwable $e) {
            $message = e(implode("\n", array_map(fn ($key, $failure) => "{$key}: {$failure->getMessage()}", array_keys($this->failures), $this->failures)) ?: $e->getMessage());
            NotificationHelper::sendSystemEmailNotification('Cron Job Error', <<<HTML
                An error occurred while running the cron job:<br>
                <pre>{$message}.</pre><br>
                Please check the system and application logs for more details.
                HTML);

            throw $e;
        }

        Setting::updateOrCreate(
            ['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => now()->toDateTimeString(), 'type' => 'string']
        );

        CronStat::create([
            'key' => 'invoice_charged',
            'value' => $this->successFullCharges,
            'date' => now()->toDateString(),
        ]);

        $this->info('Successfully charged ' . $this->successFullCharges . ' invoices.');

        // Remove old debug logs
        DebugLog::where('created_at', '<', now()->subDays(30))->delete();
    }

    /**
     * Active services past Suspend Days, not held, not on a free or one-time plan (no period to pay; a service whose
     * plan is gone is not exempt), with no payment on its way. With $overdueInvoice: and an open invoice of theirs due
     * and issued Suspend Days ago (a missing due date counts from its issue).
     *
     * @return Builder<Service>
     */
    private function suspendable(bool $overdueInvoice = false): Builder
    {
        $cutoff = today()->subDays(self::days('cronjob_order_suspend', 8))->toDateString();
        $query = $this->unheldWithNoPaymentOnItsWay(Service::query()->where('status', Service::STATUS_ACTIVE))
            ->whereDate('expires_at', '<', $cutoff)
            ->whereDoesntHave('plan', fn ($plan) => $plan->whereIn('type', ['free', 'one-time']));

        return !$overdueInvoice ? $query : $query->whereHas('invoices', fn ($invoices) => $invoices
            ->where('invoices.status', Invoice::STATUS_PENDING)
            ->whereRaw('DATE(COALESCE(invoices.due_at, invoices.created_at)) < ?', [$cutoff])
            ->whereDate('invoices.created_at', '<', $cutoff));
    }

    /**
     * Suspended for non-payment, past Termination Days, suspended at least «minimum days suspended» ago, not held,
     * with no payment on its way (a SEPA payment would land on the invoices the termination cancels).
     *
     * @return Builder<Service>
     */
    private function terminable(): Builder
    {
        return $this->unheldWithNoPaymentOnItsWay(Service::query()->where('status', Service::STATUS_SUSPENDED))
            ->whereNotNull('dunning_suspended_at')
            ->whereDate('expires_at', '<', today()->subDays(self::days('cronjob_order_terminate', 60))->toDateString())
            ->whereDate('dunning_suspended_at', '<=', today()->subDays(self::days('cronjob_terminate_min_suspended_days', 1))->toDateString());
    }

    /**
     * No hold (a hold holds through its last day), no open invoice of theirs with a processing payment (PAY-9), and no
     * paid invoice of theirs still to be applied (BIL-9): its customer paid, the renewal is on its way. And a plan: one
     * without is never billed (BIL-6), so never dunned either.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    private function unheldWithNoPaymentOnItsWay(Builder $query): Builder
    {
        // A service without a plan is never billed (staff are told, BIL-6): never suspended or terminated as unpaid.
        return $query->whereNotNull('plan_id')->where(fn ($hold) => $hold->whereNull('suspend_hold_until')->orWhereDate('suspend_hold_until', '<', today()))
            ->whereDoesntHave('invoices', fn ($invoices) => $invoices->where('invoices.status', Invoice::STATUS_PENDING)
                ->whereHas('transactions', fn ($payments) => $payments->where('status', InvoiceTransactionStatus::Processing)))
            ->whereNotExists(fn ($line) => $this->paidLineToApply($line));
    }

    /** Protected this long by a paid line that is not applied (then the usual rules, after a notice every day). */
    private const PAID_NOT_APPLIED_DAYS = 30;

    /**
     * A line of the service (services.id in the outer query) on a paid invoice still to be applied (BIL-9), its own line
     * not applied yet: another service of the same invoice, applied, is not protected by it. For PAID_NOT_APPLIED_DAYS.
     *
     * @param  \Illuminate\Database\Query\Builder  $line
     */
    private function paidLineToApply($line): void
    {
        $line->from('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_type', Service::class)->whereColumn('invoice_items.reference_id', 'services.id')
            ->whereNull('invoice_items.applied_at')
            ->where('invoices.status', Invoice::STATUS_PAID)
            ->where('invoices.apply_pending_since', '>=', now()->subDays(self::PAID_NOT_APPLIED_DAYS));
    }

    /**
     * The money that paid it is still on it: succeeded payments, less refunds and what moved to credit, cover what was
     * due (its lines and the notes other than refunds). A refund leaves the invoice paid (its note lowers the total).
     */
    private function keepsItsMoney(Invoice $invoice): bool
    {
        $due = $invoice->items->sum(fn ($item) => RecordPaymentService::toCents($item->price) * (int) $item->quantity)
            + $invoice->adjustmentNotes->where('status', AdjustmentNoteStatus::Active->value)->where('is_refund', false)
                ->sum(fn ($note) => RecordPaymentService::toCents($note->amount));
        $kept = $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
            ->sum(fn ($payment) => RecordPaymentService::toCents($payment->amount) - RecordPaymentService::toCents($payment->refunded_amount)
                - RecordPaymentService::toCents($payment->credited_amount));

        return $kept >= $due;
    }

    /** A day count setting; never negative (a negative count would act before the due date). */
    private static function days(string $setting, int $default): int
    {
        return max(0, (int) config("settings.{$setting}", $default));
    }

    /**
     * One pending upgrade. Its service ended (cancelled, gone) or past its due date (a one-time plan, with no due date,
     * never is): given up on (CancelPendingUpgrades: never a paid one, never while its invoice is kept open). Otherwise
     * its own line takes the current price, under the payment locks (an invoice with a payment on it keeps its line: a
     * lower price would leave a payment above what is owed).
     */
    private function refreshUpgrade(int $id): int
    {
        $serviceId = ServiceUpgrade::query()->whereKey($id)->value('service_id');
        $service = $serviceId === null ? null : Service::query()->whereKey($serviceId)->lockForUpdate()->first();
        $upgrade = ServiceUpgrade::query()->whereKey($id)->lockForUpdate()->first();
        if ($upgrade === null || $upgrade->status !== ServiceUpgrade::STATUS_PENDING) {
            return 0;
        }

        $running = $service !== null && in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true);
        if (!$running || ($service->expires_at !== null && $service->expires_at < now())) {
            // Decided under the invoice's lock: a paid one is left to ServiceUpgradeService, one kept open waits.
            [$cancelled, $left] = CancelPendingUpgrades::one($id, __('invoices.cancellation_reasons.' . ($running ? 'upgrade_expired' : 'service_terminated')));
            $this->leftOpen($left);

            return $cancelled ? 1 : 0;
        }
        // No invoice to follow the price; or a service whose plan was deleted: no price to compute (staff fix it).
        $invoice = $upgrade->invoice;
        if ($invoice === null || $service->plan === null) {
            return 0;
        }

        // Only this upgrade's own line: a renewal line, or another upgrade, on the same invoice keeps its price. Line
        // by line (MT-2b): a change of price is in the audit. ChangeOpenLine locks the invoice and leaves a paid one.
        // Never below zero: a downgrade's difference is credit at order time (Livewire Upgrade), not a negative line
        // that leaves the invoice owing the customer (adversarial review of 2b, S6).
        $price = max(0, $upgrade->calculatePrice()->price);
        $changed = 0;
        $invoice->items()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)->get()
            ->each(function (InvoiceItem $line) use ($price, &$changed) {
                $changed += ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = $price) ? 1 : 0;
            });

        return $changed > 0 ? 1 : 0;
    }

    /**
     * One service whose cancellation request falls due: terminated at the server, cancelled (no dunning marker: the
     * customer did not fail to pay, they left), its open invoices cancelled, its stock back. Locked and re-read.
     */
    private function endAsRequested(int $id): int
    {
        $service = Service::query()->whereKey($id)->lockForUpdate()->first();
        if ($service === null || !in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)
            || !$service->cancellation()->where('type', 'end_of_period')->exists()) {
            return 0;
        }

        // A later period already paid (it waits behind an unpaid one, docs/billing-cursor.md): ending the service now
        // would keep that money without the period. Staff decide (deliver it, or refund/credit it).
        $paidAhead = $service->invoiceItems()->where('period_start', '>=', $service->expires_at)
            ->whereHas('invoice', fn ($query) => $query->where('status', Invoice::STATUS_PAID))->exists();
        if ($paidAhead) {
            $this->notices["cancellations_processed #{$id}"] = new DisplayException("Service #{$id} not ended: a later period is already paid.");

            return 0;
        }

        TerminateJob::dispatch($service)->afterCommit();
        $service->forceFill(['status' => Service::STATUS_CANCELLED])->save();
        $this->leftOpen(CancelPendingUpgrades::forService($service, __('invoices.cancellation_reasons.cancellation_request')));
        $this->cancelInvoices($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get(), __('invoices.cancellation_reasons.cancellation_request'), $service);
        if ($service->product?->stock !== null) {
            $service->product->increment('stock', $service->quantity);
        }

        return 1;
    }

    /**
     * Bill (or, at zero price, renew) the next period of one service (docs/billing-cursor.md):
     * where the live periods that follow the paid-until date end, never the same period twice,
     * never across a hole. Returns 1 when it billed or renewed.
     */
    private function billRenewal(int $id, CarbonInterface $billUntil): int
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

        // Does the service have already an open (pending or draft) invoice?
        if ($service->invoices()->whereIn('status', ['pending', 'draft'])->exists() || $service->cancellation()->exists()) {
            return 0;
        }
        // A payment of it still to be applied (BIL-9): its date has not moved yet, billing now would ask for it twice.
        if (Service::query()->whereKey($service->id)->whereExists(fn ($line) => $this->paidLineToApply($line))->exists()) {
            return 0;
        }

        $lines = $service->livePeriodLines();
        // A suspended service is not billed again (decision A2), except for a hole before a
        // period that is already paid: otherwise that payment would be stranded.
        if ($service->status === 'suspended' && !$lines->contains(fn ($line) => $line->invoice?->status === 'paid'
            && $line->period_start->greaterThan($service->paidUntilBase()))) {
            return 0;
        }

        $periodStart = $service->nextPeriodStart($lines);
        if ($periodStart->greaterThanOrEqualTo($billUntil)) {
            return 0;
        }
        // A coupon «for N cycles»: the first cycle after them is billed at the full price. Cycles are
        // counted from a persisted start (including free cycles). Expiration is recorded once, so
        // skipping N+1 still ends the discount and a later staff price is kept.
        if ($service->coupon && $service->coupon->recurring > 0
            && $service->coupon_expired_at === null
            && $service->cycleNumber($periodStart, $lines) > $service->coupon->recurring) {
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
        $nextStart = $lines->first(fn ($line) => $line->period_start->greaterThan($periodStart))?->period_start;
        if ($nextStart !== null && $periodEnd !== null && $nextStart->lessThan($periodEnd)) {
            $price = $service->priceForPeriod($periodStart, $nextStart);
            $periodEnd = $nextStart;
        }

        // Create invoice
        $invoice = $service->invoices()->make([
            'user_id' => $service->user_id,
            'status' => 'pending',
            'due_at' => $periodStart,
            'currency_code' => $service->currency_code,
        ]);

        $invoice->save();
        // Create invoice items: the line records the period it pays for.
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => $price,
            'quantity' => $service->quantity,
            'description' => $service->describePeriod($periodStart, $periodEnd),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        $invoice = $invoice->refresh();

        // A short prorated hole can round to zero cents; no gateway can collect that amount.
        if ($invoice->total <= 0) {
            $invoice->update(['status' => Invoice::STATUS_PAID]);
        } else {
            $this->payInvoiceWithCredits($invoice, $service);
        }

        // Charge billing agreements
        if ($service->billing_agreement_id && $invoice->fresh()->status === 'pending') {
            DB::afterCommit(function () use ($invoice, $service) {
                try {
                    ExtensionHelper::charge(
                        $service->billingAgreement->gateway,
                        $invoice,
                        $service->billingAgreement
                    );

                    $this->successFullCharges++;
                } catch (Exception $e) {
                    // Ignore errors here
                    NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice);
                }
            });
        }

        return 1;
    }

    private function payInvoiceWithCredits(Invoice $invoice, Service $service): void
    {
        // As much as the credit covers, also in part (decision 5, MT-1e); read and spent under the payment locks.
        // Off with credits, or with "Automatically use credits", or for the invoices CreditAutoApply leaves alone.
        // A gateway subscription's renewal only in full: a subscription sale that became credit pays it.
        if ((string) $service->subscription_id !== '') {
            (new CreditAutoApply)->toSubscriptionRenewal($invoice);

            return;
        }
        (new CreditAutoApply)->toInvoice($invoice, notify: !$service->billing_agreement_id);
    }

    /**
     * An unpaid order's invoices (orders_cancelled): one goes whole when every other service it bills is the same
     * unpaid order (pending, cancelled by this rule too); one that also bills a service already running (staff
     * activated it) loses only this service's lines.
     */
    private function cancelOrderInvoices(Service $service): void
    {
        foreach ($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()->unique('id') as $invoice) {
            $others = $invoice->items()->where('reference_type', Service::class)->where('reference_id', '!=', $service->id)->pluck('reference_id');
            $running = Service::query()->whereIn('id', $others)->where('status', '!=', Service::STATUS_PENDING)->exists();
            $this->cancelInvoices([$invoice], __('invoices.cancellation_reasons.unpaid_order'), $running ? $service : null);
        }
    }

    /**
     * Cancel open invoices the cron gives up on (CancelOpenInvoices: credit paid on them goes back first).
     *
     * @param  iterable<Invoice>  $invoices
     */
    private function cancelInvoices(iterable $invoices, string $reason, Service|ServiceUpgrade|null $onlyFor = null): void
    {
        $this->leftOpen(CancelOpenInvoices::handle($invoices, $reason, $onlyFor));
    }

    /**
     * What CancelOpenInvoices left: an invoice kept open for staff is a notice (mailed, the run succeeds), anything
     * else a failure.
     *
     * @param  array<string, Throwable>  $left
     */
    private function leftOpen(array $left): void
    {
        foreach ($left as $key => $reason) {
            if ($reason instanceof DisplayException) {
                $this->notices[$key] = $reason;
            } else {
                $this->failures[$key] = $reason;
            }
        }
    }

    /**
     * Function to run a specific cron job by its key.
     */
    private function runCronJob(string $key, callable $callback, bool $transaction = true): void
    {
        try {
            $items = ($transaction ? DB::transaction(fn () => $callback()) : $callback()) ?? 0;
        } catch (Throwable $e) {
            report($e);
            $this->failures[$key] = $e;

            return;
        }

        CronStat::create([
            'key' => $key,
            'value' => $items,
            'date' => now()->toDateString(),
        ]);

        $this->info("Cronjob task '" . __('admin.cronjob.' . $key) . "' completed: Processed " . $items . ' items.');
    }
}
