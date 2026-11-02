<?php

namespace App\Services\Service;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paid services still waiting for their account (predeploy S4): set up on payment, their period applied here, and still
 * pending a quarter of an hour later with no create recorded for them at all. Staff are told (app:owed-creates, every
 * 15 minutes; once a week per service): nothing is created from here. A create cannot be told apart from its state alone:
 * one killed mid-way leaves no row and may have made the account (code review of #582, R1), so a guess could make it
 * twice. A create that was decided and never queued is asked for again from its own row (ModuleAction::askAgainNotQueued).
 *
 * Only what MyCS applied (applied_at: never an imported line, ADV-582-4), recently, on an invoice that kept its money
 * (ADV-582-2); never one made by hand without a payment (ADV-582-1) or with any create recorded (ADV-582-3).
 */
class OwedCreates
{
    /** Left to its own job first: queued, the worker busy. */
    public const AFTER_MINUTES = 15;

    /** Applied longer ago than this: the staff's, never made by itself. */
    public const WITHIN_DAYS = 7;

    /** Its account is owed (see the class). */
    public static function owes(Service $service): bool
    {
        $product = $service->product;
        if ($service->status !== Service::STATUS_PENDING || $product === null || !$product->setsUpOnPayment() || !$service->hasServerActions()) {
            return false;
        }
        if (ModuleAction::query()->where('service_id', $service->id)->where('action', 'create')->exists()) {
            return false;
        }

        return self::paidLines($service->invoiceItems()->getQuery(), CarbonImmutable::now()->subDays(self::WITHIN_DAYS), CarbonImmutable::now())
            ->with('invoice')->get()
            ->contains(fn (InvoiceItem $line) => $line->invoice?->status === Invoice::STATUS_PAID && $line->invoice->keepsItsMoney());
    }

    /** The services owed, told to staff (each once a week). @return int how many */
    public static function sweep(): int
    {
        $before = CarbonImmutable::now()->subMinutes(self::AFTER_MINUTES);
        $since = CarbonImmutable::now()->subDays(self::WITHIN_DAYS);
        $owed = [];
        Service::query()->where('status', Service::STATUS_PENDING)
            ->whereHas('invoiceItems', fn (Builder $line) => self::paidLines($line, $since, $before))
            ->orderBy('id')->each(function (Service $service) use (&$owed) {
                try {
                    if (self::owes($service) && Cache::add("owed-create:{$service->id}", true, now()->addDays(self::WITHIN_DAYS))) {
                        $owed[] = (int) $service->id;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });
        if ($owed !== []) {
            Log::warning('Paid services still pending, no create recorded.', ['service_ids' => $owed]);
            NotificationHelper::sendSystemEmailNotification('Paid services waiting for their account', e(
                'These services are paid and still pending, with no create recorded for them: their create job may have been lost. '
                . 'Check each on its server first (it may exist), then start it («Create» on the service, or Accept on its order): '
                . implode(', ', array_map(fn (int $id) => "#{$id}", $owed))
            ));
        }

        return count($owed);
    }

    /**
     * Its period lines applied here between $since and $before (a service's charge has no period to pay).
     *
     * @param  Builder<InvoiceItem>  $lines
     * @return Builder<InvoiceItem>
     */
    private static function paidLines(Builder $lines, CarbonImmutable $since, CarbonImmutable $before): Builder
    {
        return $lines->where(fn (Builder $kind) => $kind->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_CHARGE))
            ->whereBetween('applied_at', [$since, $before]);
    }
}
