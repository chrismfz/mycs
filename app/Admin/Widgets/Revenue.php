<?php

namespace App\Admin\Widgets;

use App\Classes\Price;
use App\Enums\DashboardPeriod;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Order;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Database\Eloquent\Builder;

class Revenue extends ChartWidget
{
    // The dashboard's order: cards and bars on top, then two columns (the lists in `Dashboard::LEFT` and `RIGHT`, owner 2026-11-04).
    protected static ?int $sort = 3;

    // About half the height it had (owner 2026-10-21).
    protected ?string $maxHeight = '160px';

    public function getHeading(): string
    {
        return __('Revenue');
    }

    public ?string $filter = 'month';

    protected ?string $pollingInterval = null;

    /** @var array{orders: int, revenue: float, net: float}|null */
    private ?array $totals = null;

    protected function getFilters(): ?array
    {
        return DashboardPeriod::options();
    }

    /**
     * Let the overview stats follow the period chosen here.
     */
    public function updatedFilter(): void
    {
        $this->dispatch('dashboard-period-updated', period: $this->filter);
    }

    /**
     * The payments the chart and the Revenue card add up: in the default currency only, since a sum of euros and
     * dollars is no amount.
     *
     * @return Builder<InvoiceTransaction>
     */
    public static function payments(): Builder
    {
        return InvoiceTransaction::query()
            ->where('status', InvoiceTransactionStatus::Succeeded)
            ->where('is_credit_transaction', false)
            ->whereIn('invoice_id', Invoice::query()->where('currency_code', (string) config('settings.default_currency'))->select('id'));
    }

    protected function getData(): array
    {
        $period = DashboardPeriod::fromValue($this->filter);

        $start = $period->start();

        $end = now();

        $interval = $period->interval();

        $revenue = Trend::query(self::payments())
            ->between(
                start: $start,
                end: $end,
            )
            ->interval($interval)
            ->sum('amount');

        $netRevenue = Trend::query(self::payments())
            ->between(
                start: $start,
                end: $end,
            )
            ->interval($interval)
            ->sum('amount - COALESCE(fee, 0)');

        $newOrders = Trend::model(Order::class)
            ->between(
                start: $start,
                end: $end,
            )
            ->interval($interval)
            ->count();

        $this->totals = [
            'orders' => (int) $newOrders->sum('aggregate'),
            'revenue' => (float) $revenue->sum('aggregate'),
            'net' => (float) $netRevenue->sum('aggregate'),
        ];

        // B2's chart (owner 2026-11-04): the orders as bars on the left, a count; the two amounts as lines on the right.
        return [
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => __('New orders'),
                    'yAxisID' => 'orders',
                    'data' => $newOrders->map(fn (TrendValue $value) => $value->aggregate)->toArray(),
                    'backgroundColor' => '#a9c1ff',
                    'borderColor' => '#a9c1ff',
                    'order' => 2,
                ],
                [
                    'label' => __('Revenue'),
                    'data' => $revenue->map(fn (TrendValue $value) => $value->aggregate)->toArray(),
                    'backgroundColor' => '#d97706',
                    'borderColor' => '#d97706',
                    'fill' => false,
                    'pointRadius' => 0,
                    'order' => 1,
                ],
                [
                    'label' => __('Net revenue'),
                    'data' => $netRevenue->map(fn (TrendValue $value) => $value->aggregate)->toArray(),
                    'backgroundColor' => '#059669',
                    'borderColor' => '#059669',
                    'fill' => false,
                    'pointRadius' => 0,
                    'order' => 1,
                ],
            ],
            'labels' => $revenue->map(fn (TrendValue $value) => Carbon::parse($value->date)->format($period->dateFormat()))->toArray(),
        ];
    }

    /** The period's totals under the chart, as the mockup's last line. */
    public function getDescription(): ?string
    {
        if ($this->totals === null) {
            $this->getCachedData();
        }
        $currency = Currency::query()->find((string) config('settings.default_currency'));
        $money = fn (float $amount) => (new Price(['price' => $amount, 'currency' => $currency], tax: 0))->formatted->price;

        return __('New orders :orders · Revenue :revenue · Net revenue :net', [
            'orders' => $this->totals['orders'],
            'revenue' => $money($this->totals['revenue']),
            'net' => $money($this->totals['net']),
        ]);
    }

    protected function getOptions(): array|RawJs|null
    {
        return [
            'scales' => [
                'orders' => [
                    'position' => 'left',
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
                'y' => [
                    'position' => 'right',
                    'beginAtZero' => true,
                    'grid' => ['drawOnChartArea' => false],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public static function canView(): bool
    {
        return auth()->user()->hasPermission('admin.widgets.revenue');
    }
}
