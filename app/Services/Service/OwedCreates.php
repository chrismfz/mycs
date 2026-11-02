<?php

namespace App\Services\Service;

use App\Jobs\Server\CreateJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Product;
use App\Models\Service;
use App\Support\AfterCommit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An account owed and never asked for (predeploy S4): a service set up on payment (or on its order) is paid, its period
 * applied, and its CreateJob was lost on the way to the queue after that commit. The payment's replay finds the line
 * applied and does nothing more, so the service would stay pending, paid, forever. Asked for again: by that replay
 * (RenewServiceService) and every quarter of an hour by app:owed-creates. Harmless twice: CreateJob is unique per
 * service, runs only while it is pending, and the server's create holds its own lock (ExtensionHelper::createServer).
 */
class OwedCreates
{
    /** Left to its own job first: queued, the worker busy. */
    public const AFTER_MINUTES = 15;

    /** Paid (or ordered) longer ago than this: the staff's, never made by itself (an import, a service left as it is). */
    public const WITHIN_DAYS = 7;

    /**
     * Its account is owed: pending, set up on payment or on order by its product, with a server to make it on, paid (its
     * period applied; a one-time or free plan: its invoice paid) unless set up on order, and no create staff hold (open,
     * given up, or resolved by them).
     */
    public static function owes(Service $service): bool
    {
        $product = $service->product;
        if ($service->status !== Service::STATUS_PENDING || $product === null || !$product->setsUpOnPayment() || !$service->hasServerActions()) {
            return false;
        }
        if (ModuleAction::query()->where('service_id', $service->id)->where('action', 'create')
            ->whereIn('status', [ModuleAction::STATUS_OPEN, ModuleAction::STATUS_RESOLVED])->exists()) {
            return false;
        }
        // On order, a one-time plan aside (set up on payment: SetUpOnOrder).
        if ($product->autosetup === Product::AUTOSETUP_ORDER && $service->plan?->type !== 'one-time') {
            return true;
        }
        $once = in_array($service->plan?->type, ['one-time', 'free'], true);

        return $service->invoiceItems()->where(fn (Builder $kind) => $kind->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_CHARGE))
            ->where(fn (Builder $paid) => $paid->whereNotNull('applied_at')
                ->when($once, fn (Builder $q) => $q->orWhereHas('invoice', fn (Builder $invoice) => $invoice->where('status', Invoice::STATUS_PAID))))
            ->exists();
    }

    /** The services owed, asked for again. @return int how many */
    public static function sweep(): int
    {
        $before = CarbonImmutable::now()->subMinutes(self::AFTER_MINUTES);
        $since = CarbonImmutable::now()->subDays(self::WITHIN_DAYS);
        $number = 0;
        Service::query()->where('status', Service::STATUS_PENDING)
            ->where(fn (Builder $when) => $when
                ->whereHas('invoiceItems', fn (Builder $line) => $line->whereBetween('applied_at', [$since, $before])
                    ->orWhereHas('invoice', fn (Builder $invoice) => $invoice->where('status', Invoice::STATUS_PAID)->whereBetween('paid_at', [$since, $before])))
                ->orWhere(fn (Builder $ordered) => $ordered->whereBetween('created_at', [$since, $before])
                    ->whereHas('product', fn (Builder $product) => $product->where('autosetup', Product::AUTOSETUP_ORDER))))
            ->orderBy('id')->each(function (Service $service) use (&$number) {
                try {
                    if (!self::owes($service)) {
                        return;
                    }
                    AfterCommit::job(new CreateJob($service));
                    Log::warning('A paid service still pending: its create asked for again.', ['service_id' => $service->id]);
                    $number++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $number;
    }
}
