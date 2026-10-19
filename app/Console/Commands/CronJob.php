<?php

namespace App\Console\Commands;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\DomainStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice as InvoiceEvent;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\CronStat;
use App\Models\DebugLog;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\McpCall;
use App\Models\McpOauthClient;
use App\Models\McpOauthCode;
use App\Models\McpOauthGrant;
use App\Models\McpProposal;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainReminders;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\PaymentReminders;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Licensing\Licenses;
use App\Services\Mcp\Proposals;
use App\Services\Money\CreditAutoApply;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\CardExpiryNotices;
use App\Services\Service\SuspensionDigest;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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

    /** @var list<int> services suspended for non-payment in this run (committed): the staff digest */
    private array $suspended = [];

    /** @var list<int> services terminated for non-payment in this run (committed): the staff digest */
    private array $terminated = [];

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
        $this->failures = $this->notices = $this->suspended = $this->terminated = [];

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
                        $until = $invoice?->apply_pending_since?->addDays(RenewalBilling::PAID_NOT_APPLIED_DAYS)->toDateString();
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

            // Paid domain renewals not done at their registrar yet (D5b-2): a job lost, a registrar down, an unknown
            // outcome waiting. The service's guards (lock, once per invoice, Pending Module Actions) make this harmless.
            $this->runCronJob('domains_renewed', function ($number = 0) {
                foreach (DomainRenewalService::waitingLines()->where('created_at', '<', now()->subMinutes(10))->pluck('id') as $lineId) {
                    try {
                        $outcome = app(DomainRenewalService::class)->renewLine((int) $lineId);
                        $number += in_array($outcome, [DomainRenewalService::APPLIED, DomainRenewalService::ADOPTED], true) ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["domains_renewed line #{$lineId}"] = $e;
                    }
                }

                return $number;
            }, transaction: false);

            // A domain lost for good (D7, owner 2026-10-17: deleted at its registry, moved away, or ended by staff): the open
            // invoices of its renewal are cancelled, as myip's hook did after 30 days, now when it is known to be lost.
            // Before the credit step, so credit paid on them goes back and pays what is still owed.
            $this->runCronJob('domain_invoices_cancelled', function ($number = 0) {
                $own = fn ($line) => $line->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_CHARGE));
                $reasons = collect(['en', 'el'])->map(fn (string $locale) => (string) trans('invoices.cancellation_reasons.domain_lost', [], $locale))->all();
                $lost = Domain::query()->whereIn('status', DomainStatus::terminalValues())
                    ->whereHas('invoiceItems', fn ($line) => $own($line)->whereHas('invoice', fn ($invoice) => $invoice->whereIn('status', Invoice::OPEN_STATUSES)))
                    ->get();
                foreach ($lost as $domain) {
                    // Read again (adversarial ADV-3): one restored meanwhile keeps its invoice. Deleted counts as lost only
                    // past its last recoverable day (review F2: the registrar may still restore it by hand until then).
                    $domain = $domain->fresh();
                    if ($domain === null || !$domain->status->isTerminal() || ($domain->status === DomainStatus::Deleted && !self::pastRecovery($domain))) {
                        continue;
                    }
                    // Transferred away: a day after staff marked it (owner 2026-10-18: time to undo a mistake first).
                    $away = $domain->meta['transferred_away']['at'] ?? null;
                    if ($domain->status === DomainStatus::TransferredAway && is_string($away) && Carbon::parse($away)->greaterThan(now()->subDay())) {
                        continue;
                    }
                    // Not one staff reopened after this cancelled it (ADV-6): theirs to decide, not cancelled every night.
                    $invoices = Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
                        ->where(fn ($q) => $q->whereNull('cancellation_reason')->orWhereNotIn('cancellation_reason', $reasons))
                        ->whereHas('items', fn ($line) => $own($line->where('reference_type', Domain::class)->where('reference_id', $domain->id)))->get();
                    $left = CancelOpenInvoices::handle($invoices, __('invoices.cancellation_reasons.domain_lost'), $domain);
                    // One kept open for staff (it holds money, it was renewed at the registrar): told once a month per
                    // reason, not every night (review F6).
                    $this->leftOpen(array_filter($left, fn ($reason, $key) => !$reason instanceof DisplayException
                        || Cache::add('domain-lost-left-open:' . $key . ':' . sha1($reason->getMessage()), true, now()->addDays(30)), ARRAY_FILTER_USE_BOTH));
                    // What was done (ADV-4, F5): cancelled, or the domain's lines taken off.
                    $number += Invoice::query()->whereKey($invoices->pluck('id'))->where(fn ($q) => $q->where('status', Invoice::STATUS_CANCELLED)
                        ->orWhereDoesntHave('items', fn ($line) => $own($line->where('reference_type', Domain::class)->where('reference_id', $domain->id))))->count();
                }

                return $number;
            }, transaction: false);

            // Domains ordered and paid (or free) not sent to their registrar yet (D8b-2): a job lost, a registrar down. The
            // service's guards (lock, sent once, a pending charge never asked again) make this harmless.
            $this->runCronJob('domains_ordered', function ($number = 0) {
                foreach (DomainOrderFulfilment::due()->where('updated_at', '<', now()->subMinutes(10))->pluck('id') as $id) {
                    try {
                        $number += app(DomainOrderFulfilment::class)->fulfil((int) $id) === DomainOrderFulfilment::SENT ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["domains_ordered #{$id}"] = $e;
                    }
                }

                return $number;
            }, transaction: false);

            // A transfer's EPP code (owner 2026-10-17): kept for staff while it may matter, cleared once the transfer is no
            // longer pending (completed, refused, cancelled) and it was ordered domain_epp_code_days (30) ago or more.
            $this->runCronJob('domain_epp_codes_cleared', fn () => Domain::query()->whereNotNull('epp_code')
                ->where('status', '!=', DomainStatus::PendingTransfer->value)->where('created_at', '<', now()->subDays(max(1, (int) config('settings.domain_epp_code_days', 30))))
                ->update(['epp_code' => null, 'updated_at' => now()]));

            // Credit pays what is open, oldest due first, before new renewals take it and before anything is suspended
            // (decision 5, MT-1e): a customer with credit is not suspended while it sits unused. Each invoice in its own
            // transaction, under the payment locks.
            $this->runCronJob('credit_applied', fn () => (new CreditAutoApply)->sweep(function (int $userId, Throwable $e) {
                $this->failures["credit_applied user #{$userId}"] = $e;
            }), transaction: false);

            $this->runCronJob('invoices_created', function ($number = 0) {
                $billing = new RenewalBilling;
                // What it tells staff is mailed also when a step of it fails (review F7).
                try {
                    $billUntil = now()->addDays((int) config('settings.cronjob_invoice', 7));
                    $ids = Service::whereIn('status', ['active', 'suspended'])->where('expires_at', '<', $billUntil)->pluck('id');
                    foreach ($ids as $id) {
                        try {
                            // One short transaction per service, on a fresh locked row: a payment committed
                            // while the cron runs is seen, and nothing stays locked for the whole run.
                            $number += DB::transaction(fn () => $billing->billRenewal($id, $billUntil));
                        } catch (Throwable $e) {
                            report($e);
                            $this->failures["invoices_created #{$id}"] = $e;
                        }
                    }
                    // Domains (docs/domains.md D-4, D-14, D5b): their own step, N days before expiry, grouped with the services
                    // due the same day; and the fee of a grace or redemption onto the open renewal invoice (D-8).
                    $domainsUntil = now()->addDays(RenewalBilling::domainInvoiceDays());
                    // Off until its registrar renewal is live (D5b-2, review 2): never an invoice nothing would act on.
                    $domains = !config('settings.domain_billing_enabled', true) ? collect() : Domain::query()->whereIn('status', array_map(fn (DomainStatus $s) => $s->value, RenewalBilling::BILLED_DOMAINS))
                        ->where('auto_renew', true)->whereNotNull('expires_at')->where('expires_at', '<', $domainsUntil)->pluck('id');
                    foreach ($domains as $id) {
                        try {
                            $number += DB::transaction(fn () => $billing->billDomainRenewal($id, $domainsUntil));
                            DB::transaction(fn () => $billing->addStageFee($id));
                        } catch (Throwable $e) {
                            report($e);
                            $this->failures["invoices_created domain #{$id}"] = $e;
                        }
                    }
                    // Grouped renewals (PR C): emailed and paid by credit once all their lines are on them.
                    $billing->finishRenewalInvoices();
                } finally {
                    $this->notices += $billing->notices;
                    $this->failures += $billing->failures;
                }

                return $number;
            }, transaction: false);

            // Saved payment methods are charged on the due date and declined charges tried again (PR H, decision 5): after
            // the renewals (one due today is charged in the same run), before the reminders and the suspensions.
            $this->runCronJob('invoices_charged', function () {
                $charged = (new AutoCharge)->run(function (int $id, Throwable $e) {
                    $this->failures["invoices_charged #{$id}"] = $e;
                });
                $this->successFullCharges += $charged;

                return $charged;
            }, transaction: false);

            // Saved payment methods expiring this month (PR H2b, WHMCS CCDaySendExpiryNotices).
            $this->runCronJob('card_expiry_notices', function () {
                return (new CardExpiryNotices)->run(function (int $id, Throwable $e) {
                    $this->failures["card_expiry_notices #{$id}"] = $e;
                });
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
                // Domains ordered and not paid in as many days (D8b): cancelled, their lines off their invoice, the name
                // free again (live_fqdn). One with money from a gateway or a transfer on it is left to staff.
                foreach ($days <= 0 ? [] : $this->domainOrderPartPaid($days) as $partPaid) {
                    $this->notices["orders_cancelled domain #{$partPaid}"] = new DisplayException("Domain #{$partPaid} is an order part paid and not registered or transferred yet: not cancelled (refund it, or take the rest).");
                }
                foreach ($days <= 0 ? [] : $this->unpaidDomainOrders($days)->pluck('id') as $id) {
                    try {
                        $number += DB::transaction(function () use ($id, $days) {
                            $domain = $this->unpaidDomainOrders($days)->whereKey($id)->lockForUpdate()->first();
                            if ($domain === null) {
                                return 0;
                            }
                            $invoices = Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
                                ->whereHas('items', fn ($line) => $line->where('reference_type', Domain::class)->where('reference_id', $domain->id))->get();
                            $left = $invoices->isEmpty() ? [] : CancelOpenInvoices::handle($invoices, __('invoices.cancellation_reasons.unpaid_order'), $domain);
                            if ($left !== []) {
                                $this->leftOpen($left);

                                return 0;
                            }
                            $domain->update(['status' => DomainStatus::Cancelled]);
                            $domain->order?->refreshStatus();

                            return 1;
                        });
                    } catch (Throwable $e) {
                        report($e);
                        $this->failures["orders_cancelled domain #{$id}"] = $e;
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

            // Domain expiry notices (D7, docs/domains.md §2b): before the expiry and after it, on domain_reminder_days.
            $this->runCronJob('domain_reminders', fn () => (new DomainReminders)->send(function (int $id, Throwable $e) {
                $this->notices["domain_reminders #{$id}"] = new DisplayException("Domain #{$id}: its expiry notice could not be sent: {$e->getMessage()}. Tried again on the next run.");
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
                        $done = DB::transaction(function () use ($id, $requiresInvoice) {
                            $service = $this->suspendable(overdueInvoice: $requiresInvoice)->whereKey($id)->lockForUpdate()->first();
                            if ($service === null) {
                                return 0;
                            }
                            // Register before Service Updated listeners, but execute only after the status commits.
                            SuspendJob::dispatch($service)->afterCommit();
                            $service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();

                            return 1;
                        });
                        $number += $done;
                        if ($done === 1) {
                            $this->suspended[] = (int) $id;
                        }
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
                        $done = DB::transaction(function () use ($id) {
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
                        $number += $done;
                        if ($done === 1) {
                            $this->terminated[] = (int) $id;
                        }
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

            // MCP proposals not decided within their day expire; decided ones are kept 90 days (docs/mcp.md).
            $this->runCronJob('mcp_proposals_expired', fn () => Proposals::expire()
                + McpProposal::query()->where('status', '!=', McpProposal::STATUS_PENDING)->where('updated_at', '<', now()->subDays(90))->delete());

            // The MCP calls' record (docs/mcp.md), 90 days.
            $this->runCronJob('mcp_calls_pruned', fn () => McpCall::query()->where('created_at', '<', now()->subDays(90))->delete()
                // Its OAuth: codes after a day (they live a minute), grants revoked or long expired after 90 days.
                + McpOauthCode::query()->where('created_at', '<', now()->subDay())->delete()
                + McpOauthGrant::query()->where(fn ($query) => $query->where('revoked_at', '<', now()->subDays(90))
                    ->orWhere('refresh_expires_at', '<', now()->subDays(90)))->delete()
                // and the clients left without a grant after a day (registration is open: review MCPO-6).
                + McpOauthClient::query()->where('created_at', '<', now()->subDay())->whereDoesntHave('grants')->delete());

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

            // What went down today, per service (as WHMCS's «Cron Job Activity»): staff unsuspend or hold the ones
            // they want kept. Before the notices mail (its failure would skip this), and also on a run with failures:
            // these suspensions happened all the same. Not sent is a notice, not a failed run: every step did its work.
            try {
                SuspensionDigest::send($this->suspended, $this->terminated);
            } catch (Throwable $e) {
                report($e);
                $this->notices['suspension_digest'] = new DisplayException('The email of the services suspended or terminated today could not be sent: '
                    . $e->getMessage() . '. See Services, status Suspended or Terminated.');
            }

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

    /**
     * Domains of an order (D8b), pending since $days ago, their registration or transfer not paid nor on its way: never
     * paid, no money from a gateway or a transfer, not an order staff accepted.
     *
     * @return Builder<Domain>
     */
    private function unpaidDomainOrders(int $days): Builder
    {
        $own = fn ($line) => $line->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER]);

        return Domain::query()->whereIn('status', [DomainStatus::PendingRegister->value, DomainStatus::PendingTransfer->value])
            ->whereNotNull('order_id')->where('created_at', '<', now()->subDays($days))
            ->whereDoesntHave('order', fn ($order) => $order->whereNotNull('accepted_at'))
            // Never sent to its registrar (D8b-2 marks it once sent; a free transfer included, adversarial review).
            ->where(fn ($q) => $q->whereNull('meta')->orWhereNull('meta->sent_at'))
            // Its line open, or cancelled already with the rest of its order (code review H1: a service of the same order
            // cancelled first takes the whole invoice); or none at all (a free transfer, D-6: else it held its name forever).
            ->where(fn ($q) => $q->whereHas('invoiceItems', fn ($line) => $own($line)->whereHas('invoice', fn ($i) => $i->whereIn('status', [...Invoice::OPEN_STATUSES, Invoice::STATUS_CANCELLED])))
                ->orWhereDoesntHave('invoiceItems', $own))
            ->whereDoesntHave('invoiceItems', fn ($line) => $own($line)->whereHas('invoice', fn ($i) => $i->where('status', Invoice::STATUS_PAID)))
            ->whereDoesntHave('invoiceItems', fn ($line) => $own($line)->whereHas('invoice', fn ($i) => $this->withRealMoney($i)))
            // Not one with staff, nor with a charge at its registrar not confirmed (it may be done there): code review 4.
            ->whereDoesntHave('registrarLogs', fn ($log) => $log->whereIn('action', ['register', 'transfer'])->where('status', DomainRegistrarLog::STATUS_PENDING))
            ->whereNotExists(fn ($q) => $q->from('module_actions')->whereColumn('module_actions.domain_id', 'domains.id')
                ->where('module_actions.status', ModuleAction::STATUS_OPEN)
                ->whereIn('module_actions.action', [ModuleAction::DOMAIN_REGISTER, ModuleAction::DOMAIN_TRANSFER]));
    }

    /**
     * Domains of an order part paid (money from a gateway or a transfer on it), pending since $days ago: told to staff.
     *
     * @return list<int>
     */
    private function domainOrderPartPaid(int $days): array
    {
        $own = fn ($line) => $line->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER]);

        return Domain::query()->whereIn('status', [DomainStatus::PendingRegister->value, DomainStatus::PendingTransfer->value])
            ->whereNotNull('order_id')->where('created_at', '<', now()->subDays($days))
            ->whereHas('invoiceItems', fn ($line) => $own($line)->whereHas('invoice', fn ($i) => $this->withRealMoney($i)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
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
            ->whereNotExists(fn ($line) => RenewalBilling::paidLineToApply($line));
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
     * A deleted domain past its last recoverable day (review F2 of #175): the end of its redemption, else of its grace,
     * else its expiry plus its TLD's (or its own) days, and never sooner than 30 days after its expiry (myip's hook).
     */
    private static function pastRecovery(Domain $domain): bool
    {
        if ($domain->expires_at === null) {
            return true;
        }
        $last = $domain->redemptionEndsOn() ?? $domain->graceEndsOn()
            ?? $domain->expires_at->copy()->addDays((int) $domain->graceDays() + (int) $domain->redemptionDays());
        $floor = $domain->expires_at->copy()->addDays(30);

        return ($last->greaterThan($floor) ? $last : $floor)->copy()->startOfDay()->lessThan(today());
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
