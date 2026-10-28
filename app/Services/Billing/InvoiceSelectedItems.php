<?php

namespace App\Services\Billing;

use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Domains\DomainRenewalService;
use App\Services\Money\CreditAutoApply;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * «Invoice selected items» (D7a, as WHMCS's): staff pick a customer's services and domains, and each one's next unbilled
 * period goes on one invoice per customer and currency now, whatever its due date. The cron's own rules
 * (RenewalBilling): never a period twice, never across a hole, never one with an open invoice; what is not billed comes
 * back with why. A domain's next period is the one from its expiry: one billed already (paid, waiting for its registrar)
 * leaves nothing further to bill.
 */
final class InvoiceSelectedItems
{
    /** As far as «next period» goes: any next period of the selection is billed now. */
    private const FAR = 50;

    /**
     * skipped: item => why it was not billed; problems: what staff should look at on what was made.
     *
     * @param  iterable<Service>  $services
     * @param  iterable<Domain>  $domains
     * @return array{invoices: list<int>, billed: list<string>, skipped: array<string, string>, problems: array<string, string>}
     */
    public function handle(iterable $services, iterable $domains): array
    {
        $billing = new RenewalBilling;
        $billing->oneInvoicePerCustomer = true;
        $billing->creditOnFinish = false;
        $until = now()->addYears(self::FAR);
        $billed = $skipped = $problems = [];

        // Earliest first, services and domains together: an invoice's due date is its first line's.
        $items = collect($services)->concat(collect($domains))
            ->unique(fn (Service|Domain $item) => $item::class . ':' . $item->id)
            ->sortBy(fn (Service|Domain $item) => [$item->expires_at?->getTimestamp() ?? 0, $item instanceof Domain ? 1 : 0, $item->id]);

        // Its customers' invoices are not finished by a cron run while lines still go on them (review F2).
        $locks = $this->lock($items->map(fn (Service|Domain $item) => (int) $item->client_id)->unique()->values()->all());
        try {
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
            // Credit pays the customer's oldest due first (adversarial ADV-2): an overdue invoice before next year's.
            foreach (Invoice::query()->whereKey($made)->get(['client_id', 'currency_code'])->unique(fn ($i) => $i->client_id . '|' . $i->currency_code) as $invoice) {
                try {
                    (new CreditAutoApply)->forCustomer((int) $invoice->client_id, $invoice->currency_code);
                } catch (Throwable $e) {
                    report($e);
                    $problems[__('invoices.selected.credit')] = $e->getMessage();
                }
            }
        } finally {
            array_map(fn (Lock $lock) => $lock->release(), $locks);
        }
        // Made, but (review F8, F9): a credit that could not be spent, a stage fee left to staff.
        foreach ($billing->failures as $key => $e) {
            $problems[preg_match('/#(\d+)$/', $key, $m) ? __('invoices.selected.invoice', ['invoice' => $m[1]]) : $key] = $e->getMessage();
        }
        foreach ($billing->notices as $key => $notice) {
            if (!in_array($notice->getMessage(), $skipped, true)) {
                $problems[$key] = $notice->getMessage();
            }
        }

        return ['invoices' => $made, 'billed' => $billed, 'skipped' => $skipped, 'problems' => $problems];
    }

    /**
     * @param  list<int>  $clientIds
     * @return list<Lock>
     */
    private function lock(array $clientIds): array
    {
        $locks = [];
        foreach ($clientIds as $clientId) {
            $lock = RenewalBilling::selectingLock($clientId);
            // block() throws when the time is up, it never returns false (adversarial ADV449-3): «busy», not a 500, and
            // the locks already taken are let go.
            try {
                $lock->block(10);
            } catch (LockTimeoutException) {
                array_map(fn (Lock $held) => $held->release(), $locks);

                throw new DisplayException(__('invoices.selected.busy'));
            }
            $locks[] = $lock;
        }

        return $locks;
    }

    private function billService(RenewalBilling $billing, Service $service, CarbonInterface $until): ?string
    {
        if (($why = $this->serviceSkip($service)) !== null) {
            return $why;
        }
        $lines = fn () => $service->invoiceItems()->count();
        $before = $lines();
        if (DB::transaction(fn () => $billing->billRenewal($service->id, $until)) > 0) {
            // At no price (its next plan, or a coupon still running: review F3) it is renewed at once, nothing invoiced.
            return $lines() > $before ? null : __('invoices.selected.renewed_free');
        }

        return ($billing->notices["invoices_created #{$service->id}"] ?? null)?->getMessage() ?? $this->serviceSkip($service->refresh(), after: true);
    }

    private function billDomain(RenewalBilling $billing, Domain $domain, CarbonInterface $until): ?string
    {
        if (($why = $this->domainSkip($domain)) !== null) {
            return $why;
        }
        if (DB::transaction(fn () => $billing->billDomainRenewal($domain->id, $until)) === 0) {
            $notice = $billing->notices["invoices_created domain #{$domain->id}"] ?? null;
            if ($notice !== null) {
                return $notice->getMessage();
            }
            $domain->refresh();
            $line = $domain->expires_at === null ? null : DomainRenewalService::billedLine($domain, $domain->expires_at->copy()->startOfDay());

            return $line === null ? __('invoices.selected.nothing')
                : __('invoices.selected.domain_billed', ['invoice' => $line->invoice_id, 'status' => $line->invoice->status]);
        }
        DB::transaction(fn () => $billing->addStageFee($domain->id));

        return null;
    }

    /** Why a service is not billed (before, or $after billRenewal billed nothing), null when it may be. */
    public function serviceSkip(Service $service, bool $after = false): ?string
    {
        $open = $service->renewalInvoices()->whereIn('status', Invoice::OPEN_STATUSES)->value('invoices.id');

        return match (true) {
            !in_array($service->status, ['active', 'suspended'], true) => __('invoices.selected.status', ['status' => Service::statusLabel($service->status)]),
            (string) $service->subscription_id !== '' => __('invoices.selected.subscription'),
            $service->plan === null => __('invoices.selected.no_plan'),
            in_array($service->plan->type, ['one-time', 'free'], true) => __('invoices.selected.one_time'),
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
            !in_array($domain->status, RenewalBilling::BILLED_DOMAINS, true) => __('invoices.selected.status', ['status' => $domain->status->getLabel()]),
            $domain->expires_at === null => __('invoices.selected.no_expiry'),
            !$domain->auto_renew => __('invoices.selected.auto_renew_off'),
            default => null,
        };
    }
}
