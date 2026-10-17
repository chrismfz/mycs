<?php

namespace App\Services\Billing;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * «Invoice selected items» (D7a, as WHMCS's): staff pick a customer's services and domains, and each one's next unbilled
 * period goes on one invoice per customer and currency now, whatever its due date. The cron's own rules
 * (RenewalBilling): never a period twice, never across a hole, never one with an open invoice; what is not billed comes
 * back with why.
 */
final class InvoiceSelectedItems
{
    /** As far as «next period» goes: any next period of the selection is billed now. */
    private const FAR = 50;

    /**
     * @param  iterable<Service>  $services
     * @param  iterable<Domain>  $domains
     * @return array{invoices: list<int>, billed: list<string>, skipped: array<string, string>} skipped: item => why
     */
    public function handle(iterable $services, iterable $domains): array
    {
        $billing = new RenewalBilling;
        $billing->oneInvoicePerCustomer = true;
        $until = now()->addYears(self::FAR);
        $billed = $skipped = [];

        // Earliest first, services and domains together: an invoice's due date is its first line's.
        $items = collect($services)->concat(collect($domains))
            ->sortBy(fn (Service|Domain $item) => [$item->expires_at?->getTimestamp() ?? 0, $item instanceof Domain ? 1 : 0, $item->id]);
        foreach ($items as $item) {
            $label = $item instanceof Domain ? $item->fqdn : '#' . $item->id . ' ' . (string) $item->product?->name;
            try {
                $why = $item instanceof Domain ? $this->billDomain($billing, $item, $until) : $this->billService($billing, $item, $until);
            } catch (Throwable $e) {
                report($e);
                $why = __('invoices.selected.failed', ['error' => $e->getMessage()]);
            }
            $why === null ? $billed[] = $label : $skipped[$label] = $why;
        }

        // These invoices only: a cron run may be adding lines to its own meanwhile.
        $made = array_values(array_unique($billing->made));
        $billing->finishRenewalInvoices($made);
        foreach ($billing->failures as $e) {
            $skipped[__('invoices.selected.finishing')] = $e->getMessage();
        }

        return ['invoices' => $made, 'billed' => $billed, 'skipped' => $skipped];
    }

    private function billService(RenewalBilling $billing, Service $service, CarbonInterface $until): ?string
    {
        if (($why = $this->serviceSkip($service)) !== null) {
            return $why;
        }
        if (DB::transaction(fn () => $billing->billRenewal($service->id, $until)) > 0) {
            return null;
        }

        return ($billing->notices["invoices_created #{$service->id}"] ?? null)?->getMessage() ?? $this->serviceSkip($service->refresh(), after: true);
    }

    private function billDomain(RenewalBilling $billing, Domain $domain, CarbonInterface $until): ?string
    {
        if (($why = $this->domainSkip($domain)) !== null) {
            return $why;
        }
        if (DB::transaction(fn () => $billing->billDomainRenewal($domain->id, $until)) === 0) {
            return ($billing->notices["invoices_created domain #{$domain->id}"] ?? null)?->getMessage() ?? __('invoices.selected.domain_billed');
        }
        DB::transaction(fn () => $billing->addStageFee($domain->id));

        return null;
    }

    /** Why a service is not billed (before, or $after billRenewal billed nothing), null when it may be. */
    public function serviceSkip(Service $service, bool $after = false): ?string
    {
        $open = $service->invoices()->whereIn('status', Invoice::OPEN_STATUSES)->value('invoices.id');

        return match (true) {
            !in_array($service->status, ['active', 'suspended'], true) => __('invoices.selected.status', ['status' => Service::statusLabel($service->status)]),
            (string) $service->subscription_id !== '' => __('invoices.selected.subscription'),
            $service->plan === null => __('invoices.selected.no_plan'),
            (float) $service->price <= 0 => __('invoices.selected.free'),
            $open !== null => __('invoices.selected.open_invoice', ['invoice' => $open]),
            $service->cancellation()->exists() => __('invoices.selected.cancellation'),
            Service::query()->whereKey($service->id)->whereExists(fn ($line) => RenewalBilling::paidLineToApply($line))->exists() => __('invoices.selected.paid_to_apply'),
            $after => __('invoices.selected.nothing'),
            default => null,
        };
    }

    /** Why a domain is not billed, null when it may be. */
    public function domainSkip(Domain $domain): ?string
    {
        return match (true) {
            !config('settings.domain_billing_enabled', true) => __('invoices.selected.domain_billing_off'),
            !in_array($domain->status, RenewalBilling::BILLED_DOMAINS, true) || $domain->expires_at === null => __('invoices.selected.status', ['status' => $domain->status->value]),
            !$domain->auto_renew => __('invoices.selected.auto_renew_off'),
            default => null,
        };
    }
}
