<?php

namespace App\Admin\Widgets;

use App\Admin\Pages\RevenueForecast;
use App\Admin\Resources\InvoiceResource;
use App\Classes\Price;
use App\Models\Currency;
use App\Services\Reports\IncomePlan;
use App\Services\Reports\RevenueForecast as Forecast;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/**
 * Income for cash planning (owner 2026-10-28, on WHMCS's Billing and Income Forecast widgets of 2026-10-21): this month
 * received, still expected and their total against the same month a year before; the last month and the year so far
 * against a year before; the overdue apart; the next months if everyone pays (IncomePlan); today and ever. Then per
 * currency each billing cycle's active services (amount and count, zero ones too) and the estimate for a year (monthly
 * × 12 + quarterly × 4 + …). Net, as every MyCS figure (the tax ones are Ekdosi's). Income in the default currency;
 * the cycles in it always, in another when a service is billed in it.
 */
class EstimatedIncome extends Widget
{
    protected static ?int $sort = 12;

    protected string $view = 'admin.widgets.estimated-income';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $default = (string) config('settings.default_currency');
        $estimates = self::estimate();
        $estimates += [$default => null];
        ksort($estimates);

        $currencies = [];
        foreach ($estimates as $code => $estimate) {
            $currency = Currency::query()->find($code);
            $money = fn ($amount) => (new Price(['price' => $amount, 'currency' => $currency], tax: 0))->format($amount);
            $lines = [];
            foreach (RevenueForecast::cycleTitles() as $column => $title) {
                $cycle = $estimate['cycles'][$column] ?? ['amount' => '0.00', 'count' => 0];
                if ($column === 'other' && $cycle['count'] === 0) {
                    continue; // WHMCS has no «other»: shown only when there is one
                }
                $lines[] = ['title' => $title, 'amount' => $money($cycle['amount']), 'count' => $cycle['count']];
            }
            $currencies[] = ['code' => $code, 'lines' => $lines, 'annual' => $money($estimate['annual'] ?? '0.00')];
        }

        $now = CarbonImmutable::now();
        $currency = Currency::query()->find($default);
        $money = fn ($amount) => (new Price(['price' => $amount, 'currency' => $currency], tax: 0))->format($amount);
        // Keyed by the day (F3 of #278): «today», «this month» and «this year» all turn at midnight.
        $data = Cache::remember('reports.income_plan.' . $default . '.' . $now->toDateString(), 300, fn () => [
            'plan' => IncomePlan::summary($default),
            'today' => Forecast::collected($default, $now->startOfDay(), $now->addDay()->startOfDay()),
            'all' => Forecast::collected($default, null, null),
        ]);
        $plan = $data['plan'];
        $month = $plan['this_month'];

        return ['currencies' => $currencies, 'url' => RevenueForecast::getUrl(), 'billingCurrency' => $default, 'money' => $money,
            'month' => $month + ['percent' => self::percent($month['received'], $month['total']),
                'change' => self::change($month['total'], $month['last_year'])],
            'lastMonth' => $plan['last_month'] + ['change' => self::change($plan['last_month']['received'], $plan['last_month']['last_year'])],
            'year' => $plan['year'] + ['change' => self::change($plan['year']['received'], $plan['year']['last_year'])],
            'overdue' => $plan['overdue'], 'ahead' => $plan['ahead'],
            // The overdue invoices (this month's past due too: the list's filter), for staff who may see them.
            'overdueUrl' => InvoiceResource::canViewAny() ? InvoiceResource::getUrl('index', ['filters' => ['overdue' => ['isActive' => true]]]) : null,
            'today' => $money($data['today']), 'all' => $money($data['all'])];
    }

    /** How much of $whole $part is, in whole percent (0 to 100), for the bar. */
    private static function percent(string $part, string $whole): int
    {
        $whole = BigDecimal::of($whole);

        return $whole->isZero() ? 0 : min(100, max(0, BigDecimal::of($part)->multipliedBy(100)->dividedBy($whole, 0, RoundingMode::Down)->toInt()));
    }

    /**
     * $now against $then: the difference and the change in whole percent (none when there was nothing then).
     *
     * @return array{difference: string, percent: int|null}
     */
    public static function change(string $now, string $then): array
    {
        $then = BigDecimal::of($then);
        $difference = BigDecimal::of($now)->minus($then);

        return ['difference' => (string) $difference->toScale(2),
            'percent' => $then->isZero() ? null : $difference->multipliedBy(100)->dividedBy($then, 0, RoundingMode::HalfUp)->toInt()];
    }

    public static function canView(): bool
    {
        return RevenueForecast::canAccess();
    }

    /**
     * Read at most every 5 minutes (F10 of #275): the dashboard opens often, the services change seldom.
     *
     * @return array<string, array{cycles: array<int|string, array{amount: string, count: int}>, annual: string}>
     */
    private static function estimate(): array
    {
        return Cache::remember('reports.annual_estimate', 300, fn () => Forecast::annualEstimate());
    }
}
