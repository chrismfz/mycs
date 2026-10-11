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
use App\Services\Service\RenewServiceService;
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
                    $service->invoices()->where('status', 'pending')->update(['status' => 'cancelled']);

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
                            $upgrade->invoice->update(['status' => 'cancelled']);
                        }

                        $number++;

                        return;
                    }
                    if (!$upgrade->invoice) {
                        return;
                    }

                    $upgrade->invoice->items()->update([
                        'price' => $upgrade->calculatePrice()->price,
                    ]);

                    $number++;
                });

                return $number;
            });

            $this->runCronJob('services_suspended', function ($number = 0) {
                // Suspend orders if due date is overdue for x days
                Service::where('status', 'active')
                    ->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_suspend', 2)))
                    ->where(function ($query) {
                        $query->whereNull('suspend_hold_until')->orWhere('suspend_hold_until', '<', now());
                    })
                    ->get()->each(function ($service) use (&$number) {
                        SuspendJob::dispatch($service);

                        $service->update(['status' => 'suspended']);
                        $number++;
                    });

                return $number;
            });

            $this->runCronJob('services_terminated', function ($number = 0) {
                // Terminate orders if due date is overdue for x days
                Service::where('status', 'suspended')->where('expires_at', '<', now()->subDays((int) config('settings.cronjob_order_terminate', 14)))->each(function ($service) use (&$number) {
                    TerminateJob::dispatch($service);

                    $service->update(['status' => 'cancelled']);
                    // Cancel outstanding invoices
                    $service->invoices()->where('status', 'pending')->update(['status' => 'cancelled']);

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
        // Calculate if we should edit the price because of the coupon
        if ($service->coupon) {
            // Calculate what iteration of the coupon we are in
            $iteration = $service->invoices()->count() + 1;
            if ($iteration == $service->coupon->recurring) {
                // Calculate the price
                $service->price = $service->calculatePrice();
                $service->save();
            }
        }

        // If service price is 0, immediately activate next period
        if ($service->price <= 0) {
            (new RenewServiceService)->handle($service);

            return 1;
        }

        $periodEnd = $service->periodEnd($periodStart, $lines);
        $price = $service->price;

        // A hole shorter than a period (the next live period starts sooner): bill only the hole, prorated.
        $nextStart = $lines->first(fn ($line) => $line->period_start->greaterThan($periodStart))?->period_start;
        if ($nextStart !== null && $periodEnd !== null && $nextStart->lessThan($periodEnd)) {
            $days = (int) $periodStart->diffInDays($nextStart);
            $fullDays = max(1, (int) $periodStart->diffInDays($periodEnd));
            $cents = (int) round((float) $service->price * 100);
            $price = number_format(intdiv($cents * $days + intdiv($fullDays, 2), $fullDays) / 100, 2, '.', '');
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

        $this->payInvoiceWithCredits($invoice);

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

    private function payInvoiceWithCredits(Invoice $invoice): void
    {
        // Credits switched off: the balance is never spent automatically either.
        if (!config('settings.credits_enabled') || !config('settings.credits_auto_use', true)) {
            return;
        }
        $user = $invoice->user;
        $credits = $user->credits()
            ->where('currency_code', $invoice->currency_code)
            ->lockForUpdate()
            ->first();
        $remaining = $invoice->fresh()->remaining;

        if ($remaining > 0 && $credits && $credits->amount >= $remaining) {
            $credits->amount -= $remaining;
            $credits->save();

            ExtensionHelper::addPayment($invoice->id, null, amount: $remaining, isCreditTransaction: true);
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
