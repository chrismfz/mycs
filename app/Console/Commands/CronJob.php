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
use App\Models\BillingAgreement;
use App\Models\CronStat;
use App\Models\DebugLog;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\McpCall;
use App\Models\McpOauthCode;
use App\Models\McpOauthGrant;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\PaymentReminders;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Licensing\Licenses;
use App\Services\Money\CreditAutoApply;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use App\Support\AfterCommit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
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
     * This run's renewal invoices by group key (PR C): the invoice the next line of the same group goes on.
     *
     * @var array<string, int>
     */
    private array $renewalGroups = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // One run at a time (audit sweep BIL-16): a manual run overlapping the scheduled one would cancel orders and give
        // stock back twice. The lock outlives a crashed run by 2 hours at most.
        $lock = Cache::lock('app:cron-job', 7200);
        if (!$lock->get()) {
            $this->error('Another cron run is in progress: not started.');

            return self::FAILURE;
        }
        try {
            return $this->runSteps();
        } finally {
            $lock->release();
        }
    }

    private function runSteps()
    {
        Config::set('audit.console', true);
        // One command object can run twice (a test, a long-lived worker): nothing carries over from a run to the next.
        $this->renewalGroups = $this->failures = $this->notices = [];

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
                // Grouped renewals (PR C): emailed, paid by credit and charged once all their lines are on them.
                $this->finishRenewalInvoices();

                return $number;
            }, transaction: false);

            $this->runCronJob('orders_cancelled', function ($number = 0) {
                // Cancel services if first invoice is not paid after x days. One service per transaction: an invoice
                // that cannot be cancelled rolls back its own service only, never the step (audit sweep BIL-10).
                // 0 disables (an order placed a minute ago would go, with the invoice its customer may be paying).
                $days = (int) config('settings.cronjob_order_cancel', 7);
                $ids = $days <= 0 ? collect() : $this->unpaidOrders($days)->pluck('id');
                if ($days > 0) {
                    foreach ($this->orderPartPaid($days)->pluck('id') as $partPaid) {
                        $this->notices["orders_cancelled #{$partPaid}"] = new DisplayException("Service #{$partPaid} is an order part paid and not started: not cancelled (refund it, or take the rest and start it).");
                    }
                }
                foreach ($ids as $id) {
                    try {
                        $number += DB::transaction(function () use ($id, $days) {
                            $service = $this->unpaidOrders($days)->whereKey($id)->lockForUpdate()->first();
                            if ($service === null) {
                                return 0;
                            }
                            $this->cancelOrderInvoices($service);
                            $service->update(['status' => Service::STATUS_CANCELLED]);
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

            // Payment reminders before the due date and overdue notices (Σ4): before the suspensions, so a last notice
            // goes out before its service is suspended. Each invoice on its own.
            $this->runCronJob('reminders_sent', fn () => (new PaymentReminders)->send(function (int $id, Throwable $e) {
                $this->notices["reminders_sent #{$id}"] = new DisplayException("Invoice #{$id}: its payment reminder could not be sent: {$e->getMessage()}. Tried again on the next run.");
            }), transaction: false);

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
                    $notices = $this->suspendable()->whereDoesntHave('invoices', fn ($query) => $query->where('invoices.status', Invoice::STATUS_UNPAID))->pluck('id');
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
                            $service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();

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
                            $service->update(['status' => Service::STATUS_TERMINATED]); // as WHMCS: not cancelled, the customer did not ask
                            // Only its own lines: an invoice imported from WHMCS bills every service due that day.
                            // Upgrades first: service, upgrade, then invoices (the order ServiceUpgradeService takes).
                            $this->leftOpen(CancelPendingUpgrades::forService($service, __('invoices.cancellation_reasons.service_terminated')));
                            // Those overdue past the days set stay as a debt where «keep overdue invoices» is on (Δ3).
                            $this->cancelInvoices(CancelOpenInvoices::onServiceEnd($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()),
                                __('invoices.cancellation_reasons.service_terminated'), $service);
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

            // The MCP calls' record (docs/mcp.md), 90 days.
            $this->runCronJob('mcp_calls_pruned', fn () => McpCall::query()->where('created_at', '<', now()->subDays(90))->delete()
                // Its OAuth: codes after a day (they live a minute), grants revoked or long expired after 90 days.
                + McpOauthCode::query()->where('created_at', '<', now()->subDay())->delete()
                + McpOauthGrant::query()->where(fn ($query) => $query->where('revoked_at', '<', now()->subDays(90))
                    ->orWhere('refresh_expires_at', '<', now()->subDays(90)))->delete());

            // nixpal licensing: the access log kept 90 days (WHMCS addon «logprune»).
            $this->runCronJob('license_logs_pruned', fn () => Licenses::pruneLog());

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
     * Orders never paid (audit sweep BIL-12): a pending service placed $days ago, billed (an invoice of its own, open or
     * already cancelled as unpaid with another service of the same order), never paid, and with no money on an open one:
     * a payment from a gateway or a transfer (processing too) leaves it to staff (orderPartPaid). Credit alone does not:
     * cancelling gives it back (CancelOpenInvoices), as WHMCS cancels an order part paid by credit. A pending service
     * staff created without an invoice is not an order waiting for payment: left alone.
     *
     * @return Builder<Service>
     */
    private function unpaidOrders(int $days): Builder
    {
        return Service::query()->where('status', Service::STATUS_PENDING)->where('created_at', '<', now()->subDays($days))
            // Not one of an order staff accepted (paid or not, decision 4; its create may still be failing: ADV-4).
            ->whereDoesntHave('order', fn ($order) => $order->whereNotNull('accepted_at'))
            ->whereHas('invoices', fn ($invoices) => $invoices->whereIn('invoices.status', [Invoice::STATUS_UNPAID, Invoice::STATUS_CANCELLED]))
            ->whereDoesntHave('invoices', fn ($invoices) => $invoices->where('invoices.status', Invoice::STATUS_PAID))
            ->whereDoesntHave('invoices', fn ($invoices) => $this->withRealMoney($invoices));
    }

    /**
     * Pending services placed $days ago with money from a gateway or a transfer on an open invoice: not cancelled,
     * reported to staff (refund it, or take the rest and start it).
     *
     * @return Builder<Service>
     */
    private function orderPartPaid(int $days): Builder
    {
        return Service::query()->where('status', Service::STATUS_PENDING)->where('created_at', '<', now()->subDays($days))
            ->whereHas('invoices', fn ($invoices) => $this->withRealMoney($invoices));
    }

    /** Open invoices with a payment that is not credit (succeeded), or one on its way. */
    private function withRealMoney($invoices)
    {
        return $invoices->whereIn('invoices.status', Invoice::OPEN_STATUSES)->whereHas('transactions', fn ($payments) => $payments
            ->where(fn ($money) => $money->where(fn ($paid) => $paid->where('status', InvoiceTransactionStatus::Succeeded)->where('is_credit_transaction', false))
                ->orWhere('status', InvoiceTransactionStatus::Processing)));
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
            ->where('invoices.status', Invoice::STATUS_UNPAID)
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
            ->whereDate('dunning_suspended_at', '<=', today()->subDays(self::days('cronjob_terminate_min_suspended_days', 1))->toDateString())
            // A later period already paid (it waits behind one unpaid or given up on): ending the service would keep that
            // money without the period. Staff decide; the cron tells them (billRenewal), as endAsRequested does.
            ->whereNotExists(fn ($line) => $line->from('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.reference_type', Service::class)->whereColumn('invoice_items.reference_id', 'services.id')
                ->whereColumn('invoice_items.period_start', '>=', 'services.expires_at')
                ->where('invoices.status', Invoice::STATUS_PAID));
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
            ->whereDoesntHave('invoices', fn ($invoices) => $invoices->where('invoices.status', Invoice::STATUS_UNPAID)
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
        $due = $invoice->items->sum(fn (InvoiceItem $item) => $item->totalCents())
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
        // Overdue ones past the days set stay as a debt where «keep overdue invoices» is on (Δ3a).
        $this->cancelInvoices(CancelOpenInvoices::onServiceEnd($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()),
            __('invoices.cancellation_reasons.cancellation_request'), $service);
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
        if ($service->invoices()->whereIn('status', Invoice::OPEN_STATUSES)->exists() || $service->cancellation()->exists()) {
            return 0;
        }
        // A payment of it still to be applied (BIL-9): its date has not moved yet, billing now would ask for it twice.
        if (Service::query()->whereKey($service->id)->whereExists(fn ($line) => $this->paidLineToApply($line))->exists()) {
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
        // group are billed one after another, never two at once (one cron run at a time).
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
            // Finalized, emailed, paid by credit and charged once all its lines are on it (finishRenewalInvoices): marked
            // in the same commit as its first line, so that a run that fails or stops before then leaves it to the next.
            $invoice->finalize_later = true;
            $invoice->forceFill(['finish_pending_at' => now()]);
            $invoice->save();
            // Known to the group once this service's transaction commits (adversarial C1-ADV-1): one rolled back leaves
            // no invoice behind for the next line to land on.
            if ($key !== null) {
                $id = (int) $invoice->id;
                DB::afterCommit(function () use ($key, $id) {
                    $this->renewalGroups[$key] = $id;
                });
            }
        }
        // The line records the period it pays for.
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => $price,
            'quantity' => $service->quantity,
            // With its options (PR C, as WHMCS): one invoice can bill several services of the same product.
            'description' => $service->describeLine($periodStart, $periodEnd),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return 1;
    }

    /**
     * The renewal invoices of this run (one per group, PR C), its key: customer, due date and currency; null
     * for one billed alone (a gateway subscription's renewal, a customer with «Separate invoices»).
     */
    private function renewalGroupKey(Service $service, CarbonInterface $periodStart): ?string
    {
        if ((string) $service->subscription_id !== '' || $service->client?->separate_invoices) {
            return null;
        }

        return implode('|', [$service->client_id, $periodStart->toDateString(), $service->currency_code]);
    }

    /**
     * Each renewal invoice still to finish (PR C), once all its lines are on it: this run's, and any a previous run left
     * (a failure, a crash). Its Finalized event (the email) fires, nothing to pay closes it as paid, credit pays it (MT-1e)
     * and its saved method is charged. One invoice per transaction, which clears its mark: finished once. A credit
     * failure is reported and does not hold back the email or the charge; any other failure leaves it marked, for the next
     * run.
     */
    private function finishRenewalInvoices(): void
    {
        $this->renewalGroups = [];
        $ids = Invoice::query()->whereNotNull('finish_pending_at')->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id) {
                    $invoice = (new RecordPaymentService)->lockInvoice($id);
                    if ($invoice->finish_pending_at === null) {
                        return;
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
                    try {
                        DB::transaction(fn () => $this->payInvoiceWithCredits($invoice, $subscription, notify: $agreement === null));
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["invoices_created credit invoice #{$id}"] = $e;
                    }
                    if ($agreement !== null && $invoice->fresh()->status === Invoice::STATUS_UNPAID) {
                        $this->chargeSavedMethod($invoice, $agreement);
                    }
                });
            } catch (Throwable $e) {
                report($e);
                $this->failures["invoices_created invoice #{$id}"] = $e;
            }
        }
    }

    /**
     * The account's saved method pays its renewal invoice, after commit (DATA-9: a method gone, the customer is told). Read
     * again then (code review of C2): removed, replaced or with «Auto-charge» turned off meanwhile, it is not charged.
     */
    private function chargeSavedMethod(Invoice $invoice, BillingAgreement $agreement): void
    {
        DB::afterCommit(function () use ($invoice, $agreement) {
            try {
                if ((int) $invoice->client?->fresh()?->autoChargeAgreement()?->id !== (int) $agreement->id) {
                    return;
                }
                if ($agreement->gateway === null) {
                    throw new DisplayException("Invoice #{$invoice->id}: the account's saved payment method is gone.");
                }
                ExtensionHelper::charge($agreement->gateway, $invoice, $agreement);

                $this->successFullCharges++;
            } catch (Throwable $e) {
                if (!$e instanceof DisplayException) {
                    report($e);
                }
                NotificationHelper::invoicePaymentFailedNotification($invoice->client, $invoice);
            }
        });
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
