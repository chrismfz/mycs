<?php

namespace App\Support\Portal;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\Service;
use App\Services\Order\OrderPricing;
use App\Support\CurrentClient;
use Carbon\CarbonImmutable;
use MyCS\Pricing\LinePricer;

/**
 * «Upcoming renewals» on the home page (P4d, owner 2026-10-19): what renews this month and the next two, by month, with
 * what it will cost, so a customer sees beforehand what they will pay. A forecast with the rules of the renewal billing
 * (RenewalBilling): active services of a recurring plan not being cancelled, at their price with tax; active domains
 * renewing (auto renew on), for their period at their renewal price with tax. Each only within the member's grant.
 */
final class UpcomingRenewals
{
    public const MONTHS = 3;

    public function __construct(private readonly Client $client) {}

    /**
     * @return list<array{month: string, label: string, items: list<array{name: string, date: string, amount: ?string, url: string}>, totals: string}>
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
                ], $in),
                'totals' => implode(' + ', array_map(fn (array $total) => self::format($total[0], $total[1]), $totals)),
            ];
        }

        return $months;
    }

    /** @return list<array{name: string, date: CarbonImmutable, cents: ?int, currency: ?Currency, url: string}> */
    private function services(CarbonImmutable $from, CarbonImmutable $until): array
    {
        return $this->client->services()->where('status', Service::STATUS_ACTIVE)
            ->whereBetween('expires_at', [$from->toDateString(), $until->toDateString()])
            ->whereHas('plan', fn ($plan) => $plan->whereNotIn('type', ['one-time', 'free']))
            ->whereDoesntHave('cancellation')
            ->with(['plan', 'product', 'currency'])->get()
            ->map(fn (Service $service) => [
                'name' => (string) $service->label,
                'date' => CarbonImmutable::parse($service->expires_at),
                'cents' => $service->recurringGrossCents(),
                'currency' => $service->currency,
                'url' => route('services.show', $service),
            ])->all();
    }

    /** @return list<array{name: string, date: CarbonImmutable, cents: ?int, currency: ?Currency, url: string}> */
    private function domains(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rate = OrderPricing::taxRate($this->client);

        return $this->client->domains()->where('status', DomainStatus::Active->value)->where('auto_renew', true)
            ->whereBetween('expires_at', [$from->startOfDay(), $until->endOfDay()])
            ->with(['domainTld.prices', 'currency'])->get()
            ->map(function (Domain $domain) use ($rate) {
                // At its renewal price for its period (RenewalBilling); none (staff price it): no amount, still listed.
                $price = $domain->renewalPriceFor($domain->nextRenewalYears());
                $cents = $price === null ? null
                    : (int) round((float) LinePricer::invoiceLine(OrderPricing::toNet($price, $this->client), 1, $rate)['total'] * 100);

                return [
                    'name' => $domain->fqdn,
                    'date' => CarbonImmutable::parse($domain->expires_at),
                    'cents' => $cents,
                    'currency' => $domain->currency,
                    'url' => route('domains.show', $domain),
                ];
            })->all();
    }

    private static function format(int $cents, ?Currency $currency): string
    {
        return (string) new Price(['price' => $cents / 100, 'currency' => $currency], tax: 0);
    }
}
