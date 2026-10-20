<?php

namespace App\Support\Portal;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\Billing\RenewalPrice;
use App\Services\Domains\DomainRenewalService;
use App\Services\Order\OrderPricing;
use App\Support\CurrentClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use MyCS\Pricing\LinePricer;

/**
 * «Upcoming renewals» on the home page (P4d, owner 2026-10-19): what renews from today to the end of the second month
 * after this one, by month, with what it will cost, so a customer sees beforehand what they will pay. A forecast with
 * the rules of the renewal billing (RenewalBilling):
 * - services: active, of a recurring plan, not being cancelled; every period that starts in the window (a monthly one
 *   three times), at the price it will be billed with tax: the next plan's from a cycle change «from the next renewal»,
 *   the full one after a coupon's last cycle; a period of nothing is renewed without an invoice, not listed;
 * - domains: active with auto renew on, for their period at their renewal price with tax; none set, or a period their
 *   registrar does not renew (staff decide): listed without an amount;
 * - a period already invoiced and unpaid is marked so (it is also among the unpaid invoices), one already paid is not
 *   to come.
 * Each only within the member's grant.
 */
final class UpcomingRenewals
{
    public const MONTHS = 3;

    public function __construct(private readonly Client $client) {}

    /**
     * @return list<array{month: string, label: string, items: list<array{name: string, date: string, amount: ?string, url: string, invoiced: bool}>, totals: string}>
     */
    public function months(): array
    {
        $today = CarbonImmutable::today();
        $until = $today->startOfMonth()->addMonthsNoOverflow(self::MONTHS - 1)->endOfMonth();
        $rows = [];
        if (CurrentClient::allows('products')) {
            array_push($rows, ...$this->services($today, $until));
        }
        if (CurrentClient::allows('domains')) {
            array_push($rows, ...$this->domains($today, $until));
        }

        $months = [];
        for ($month = $today->startOfMonth(); $month->lte($until); $month = $month->addMonthNoOverflow()) {
            $in = array_values(array_filter($rows, fn (array $row) => $row['date']->format('Y-m') === $month->format('Y-m')));
            usort($in, fn (array $a, array $b) => $a['date'] <=> $b['date'] ?: strcmp($a['name'], $b['name']));
            $totals = [];
            foreach ($in as $row) {
                if ($row['cents'] !== null) {
                    $code = $row['currency']->code ?? '';
                    $totals[$code] = [($totals[$code][0] ?? 0) + $row['cents'], $row['currency']];
                }
            }
            $months[] = [
                'month' => $month->format('Y-m'),
                'label' => ucfirst($month->locale(app()->getLocale())->isoFormat('MMMM YYYY')),
                'items' => array_map(fn (array $row) => [
                    'name' => $row['name'],
                    'date' => $row['date']->format('d/m/Y'),
                    'amount' => $row['cents'] === null ? null : self::format($row['cents'], $row['currency']),
                    'url' => $row['url'],
                    'invoiced' => $row['invoiced'],
                ], $in),
                'totals' => implode(' + ', array_map(fn (array $total) => self::format($total[0], $total[1]), $totals)),
            ];
        }

        return $months;
    }

    /** @return list<array{name: string, date: CarbonImmutable, cents: ?int, currency: ?Currency, url: string, invoiced: bool}> */
    private function services(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $services = $this->client->services()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->whereNotNull('expires_at')->where('expires_at', '<=', $until->endOfDay())
            ->whereHas('plan', fn ($plan) => $plan->whereNotIn('type', ['one-time', 'free']))
            ->whereDoesntHave('cancellation')
            ->with(['plan', 'nextPlan', 'product', 'currency', 'coupon'])->get();
        // A suspended one is not billed again, unless suspended for non-payment and paid since (Δ3a, RenewalBilling).
        $services = $services->filter(fn (Service $service) => $service->status === Service::STATUS_ACTIVE || ($service->dunning_suspended_at !== null
            && $service->invoiceItems()->whereNotNull('applied_at')->where('applied_at', '>=', $service->dunning_suspended_at)
                ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_PAID))->exists()));
        $billed = $this->billedServicePeriods($services->modelKeys());

        $rows = [];
        foreach ($services as $service) {
            $service->setRelation('client', $this->client);
            $start = CarbonImmutable::parse($service->expires_at)->startOfDay();
            $first = true;
            // Every period that starts in the window (its billing day kept, as the billing steps them).
            for ($guard = 0; $start->lte($until) && $guard < 100; $guard++) {
                $priced = RenewalPrice::for($service, $start, $first);
                $end = $priced->periodEnd($start);
                $line = $billed[$service->id][$start->toDateString()] ?? null;
                // A period given up on (Δ3): the billing bills nothing after it.
                if ($line?->forgone_at !== null) {
                    break;
                }
                if ($start->gte($from) && $line?->invoice?->status !== Invoice::STATUS_PAID) {
                    $cents = $priced->recurringGrossCents();
                    if ($cents > 0 || $line !== null) {
                        $rows[] = [
                            'name' => (string) $service->label,
                            'date' => $start,
                            'cents' => $line?->line_total !== null ? (int) round((float) $line->line_total * 100) : $cents,
                            'currency' => $service->currency,
                            'url' => $line !== null ? route('invoices.show', $line->invoice) : route('services.show', $service),
                            'invoiced' => $line !== null,
                        ];
                    }
                }
                if ($end === null || $end->lte($start)) {
                    break;
                }
                $start = $end->startOfDay();
                $first = false;
                $service = $priced;
            }
        }

        return $rows;
    }

    /**
     * The services' periods already on an invoice, by their start: unpaid (to pay, marked), paid (to be applied, BIL-9: not
     * to come), or given up on (Δ3: nothing more is billed).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, InvoiceItem>>
     */
    private function billedServicePeriods(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $billed = [];
        InvoiceItem::query()->where('reference_type', Service::class)->whereIn('reference_id', $ids)->whereNull('kind')
            ->whereNotNull('period_start')
            ->where(fn ($q) => $q->whereNotNull('forgone_at')
                ->orWhereHas('invoice', fn ($invoice) => $invoice->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])))
            ->with('invoice')->orderBy('id')->get()
            ->each(function (InvoiceItem $line) use (&$billed) {
                $billed[(int) $line->reference_id][$line->period_start->toDateString()] = $line;
            });

        return $billed;
    }

    /** @return list<array{name: string, date: CarbonImmutable, cents: ?int, currency: ?Currency, url: string, invoiced: bool}> */
    private function domains(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rate = OrderPricing::taxRate($this->client);

        return $this->client->domains()->where('status', DomainStatus::Active->value)->where('auto_renew', true)
            ->whereBetween('expires_at', [$from->startOfDay(), $until->endOfDay()])
            ->with(['domainTld.prices', 'domainTld.registrarConnection', 'registrarConnection', 'currency'])->get()
            ->map(function (Domain $domain) use ($rate) {
                $domain->setRelation('client', $this->client);
                $date = CarbonImmutable::parse($domain->expires_at);
                // Its renewal already on an invoice (the billing's own check): unpaid, marked; paid (waiting for its registrar)
                // or given up on (Δ3), not to come.
                $line = DomainRenewalService::billedLine($domain, Carbon::parse($domain->expires_at)->startOfDay());
                if ($line !== null && ($line->forgone_at !== null || $line->invoice?->status !== Invoice::STATUS_UNPAID)) {
                    return null;
                }
                $years = $domain->nextRenewalYears();
                // At its renewal price for its period (RenewalBilling); none, or a period its registrar does not renew
                // (.gr: odd years): staff decide, listed without an amount.
                $price = $years % $domain->renewYearStep() === 0 ? $domain->renewalPriceFor($years) : null;
                $cents = match (true) {
                    $line?->line_total !== null => (int) round((float) $line->line_total * 100),
                    $price === null => null,
                    default => (int) round((float) LinePricer::invoiceLine(OrderPricing::toNet($price, $this->client), 1, $rate)['total'] * 100),
                };

                return [
                    'name' => $domain->fqdn,
                    'date' => $date,
                    'cents' => $cents,
                    'currency' => $domain->currency,
                    'url' => $line !== null ? route('invoices.show', $line->invoice) : route('domains.show', $domain),
                    'invoiced' => $line !== null,
                ];
            })->filter()->values()->all();
    }

    private static function format(int $cents, ?Currency $currency): string
    {
        return (string) new Price(['price' => $cents / 100, 'currency' => $currency], tax: 0);
    }
}
