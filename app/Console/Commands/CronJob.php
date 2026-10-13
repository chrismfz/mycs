<?php

namespace App\Console\Commands;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\CronStat;
use App\Models\DebugLog;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Money\CreditAutoApply;
use App\Services\Service\RenewServiceService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Console\Command;
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
                // Cancel services if first invoice is not paid after x days
                Service::where('status', 'pending')->whereDoesntHave('invoices', function ($query) {
                    $query->where('status', 'paid');
                })->where('created_at', '<', now()->subDays((int) config('settings.cronjob_order_cancel', 7)))->get()->each(function ($service) use (&$number) {
                    $this->cancelInvoices($service->invoices()->where('status', 'pending')->get());

                    $service->update(['status' => 'cancelled']);

                    if ($service->product->stock !== null) {
                        $service->product->increment('stock', $service->quantity);
                    }

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('upgrade_invoices_updated', function ($number = 0) {
                // Update pending upgrade invoices
                ServiceUpgrade::where('status', 'pending')->get()->each(function ($upgrade) use (&$number) {
                    if ($upgrade->service->expires_at < now()) {
                        $upgrade->update(['status' => 'cancelled']);
                        // Somehow people manage to have an upgrade without an invoice
                        if ($upgrade->invoice) {
                            $this->cancelInvoices([$upgrade->invoice], throughModel: true);
                        }

                        $number++;

                        return;
                    }
                    if (!$upgrade->invoice) {
                        return;
                    }

                    // Only this upgrade's own line: a renewal line, or another upgrade, on the same invoice keeps its price.
                    $upgrade->invoice->items()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)->update([
                        'price' => $upgrade->calculatePrice()->price,
                    ]);

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('cancellations_processed', function ($number = 0) {
                // Cancellation requests «at the end of the billing period», on that day (WHMCS: Process Cancellation
                // Requests): the service ends as the customer asked, before anything would suspend it as unpaid.
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
                // Suspend orders if due date is overdue for x days
                Service::where('status', 'active')
                    ->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_suspend', 2)))
                    ->where(function ($query) {
                        $query->whereNull('suspend_hold_until')->orWhere('suspend_hold_until', '<', now());
                    })
                    ->lockForUpdate()->get()->each(function ($service) use (&$number) {
                        // Register before Service Updated listeners, but execute only after the status commits.
                        SuspendJob::dispatch($service)->afterCommit();
                        $service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now()])->save();
                        $number++;
                    });

                return $number;
            });

            $this->runCronJob('services_terminated', function ($number = 0) {
                // Terminate orders if due date is overdue for x days
                Service::where('status', 'suspended')->whereNotNull('dunning_suspended_at')->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_terminate', 14)))->lockForUpdate()->get()->each(function ($service) use (&$number) {
                    TerminateJob::dispatch($service);

                    $service->update(['status' => 'cancelled']);
                    // Cancel outstanding invoices
                    $this->cancelInvoices($service->invoices()->where('status', 'pending')->get());

                    if ($service->product->stock !== null) {
                        $service->product->increment('stock', $service->quantity);
                    }

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('tickets_closed', function ($number = 0) {
                // Close tickets if no response for x days
                Ticket::where('status', 'replied')->each(function ($ticket) use (&$number) {
                    $lastMessage = $ticket->messages()->latest('created_at')->first();
                    if ($lastMessage && $lastMessage->created_at < now()->subDays((int) config('settings.cronjob_close_ticket', 7))) {
                        $ticket->update(['status' => 'closed']);
                        $number++;
                    }
                });

                return $number;
            });

            $this->runCronJob('email_logs_deleted', function ($number = 0) {
                $number = EmailLog::where('created_at', '<', now()->subDays((int) config('settings.cronjob_delete_email_logs', 90)))->count();
                // Delete email logs older then x
                EmailLog::where('created_at', '<', now()->subDays((int) config('settings.cronjob_delete_email_logs', 90)))->delete();

                return $number;
            });

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
     * Bill (or, at zero price, renew) the next period of one service (docs/billing-cursor.md):
     * where the live periods that follow the paid-until date end, never the same period twice,
     * never across a hole. Returns 1 when it billed or renewed.
     */
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

        TerminateJob::dispatch($service)->afterCommit();
        $service->forceFill(['status' => Service::STATUS_CANCELLED])->save();
        $this->cancelInvoices($service->invoices()->where('invoices.status', Invoice::STATUS_PENDING)->get());
        if ($service->product?->stock !== null) {
            $service->product->increment('stock', $service->quantity);
        }

        return 1;
    }

    private function billRenewal(int $id, CarbonInterface $billUntil): int
    {
        $service = Service::query()->whereKey($id)->lockForUpdate()->first();
        if (!$service || !in_array($service->status, ['active', 'suspended'], true)) {
            return 0;
        }

        // Does the service have already an open (pending or draft) invoice?
        if ($service->invoices()->whereIn('status', ['pending', 'draft'])->exists() || $service->cancellation()->exists()) {
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
     * Cancel open invoices the cron gives up on (CancelOpenInvoices: credit paid on them goes back first).
     *
     * @param  iterable<Invoice>  $invoices
     */
    private function cancelInvoices(iterable $invoices, bool $throughModel = false): void
    {
        $this->failures = array_merge($this->failures, CancelOpenInvoices::handle($invoices, $throughModel));
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
