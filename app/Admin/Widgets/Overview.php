<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\OrderResource;
use App\Classes\Price;
use App\Enums\DashboardPeriod;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\InvoiceTransaction;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\Admin\ClientSummary;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

class Overview extends BaseWidget
{
    // The dashboard's order (owner 2026-10-21): cards, registrar credit, revenue | support, income | renewals.
    protected static ?int $sort = 0;

    // Poll every 5 minutes (5m doesn't work somehow)
    protected ?string $pollingInterval = '600s';

    public ?string $period = null;

    #[On('dashboard-period-updated')]
    public function updatePeriod(string $period): void
    {
        $this->period = $period;
    }

    protected function getStats(): array
    {
        // WHMCS's order (owner 2026-10-21): Pending Orders first, Pending Module Actions last, both always there.
        return array_filter([
            $this->pendingOrders(),
            $this->invoiceTransaction(),
            // Fewer new tickets is the good outcome, so the trend color is inverted
            $this->getData(Ticket::class, 'Tickets', lowerIsBetter: true),
            $this->getData(Service::class, 'Services'),
            $this->clientsWithCredit(),
            $this->pendingModuleActions(),
        ]);
    }

    /** All the cards in one row on a wide screen: five, or as many as this staff member may see (review of #279). */
    protected function getColumns(): int|array
    {
        $count = max(1, count($this->getCachedStats()));

        return ['default' => 1, 'md' => min(3, $count), 'xl' => $count];
    }

    /** WHMCS's «Pending Orders» counter (Σ1-b2): always there, gray at zero. */
    private function pendingOrders(): ?Stat
    {
        if (!OrderResource::canViewAny()) {
            return null;
        }
        $pending = Order::query()->where('status', Order::STATUS_PENDING)->count();

        return Stat::make('Pending Orders', (string) $pending)
            ->description($pending === 0 ? 'Nothing waiting' : 'Waiting to be accepted or set up')
            ->color($pending === 0 ? 'gray' : 'warning')
            ->url(OrderResource::getUrl('index', ['tab' => 'pending']));
    }

    /**
     * Account credit nobody uses yet (owner 2026-10-23): a double transfer recorded twice, an overpayment, credit kept by
     * the customer's choice. Gray at zero; the list opens on the accounts that have it.
     */
    private function clientsWithCredit(): ?Stat
    {
        if (!ClientResource::canViewAny() || !auth()->user()?->hasPermission('admin.credits.viewAny')) {
            return null;
        }
        $credits = Credit::query()->where('amount', '>', 0)
            ->selectRaw('currency_code, COUNT(DISTINCT client_id) as clients, SUM(amount) as total')->groupBy('currency_code')->get();
        $clients = Credit::query()->where('amount', '>', 0)->distinct()->count('client_id');
        $totals = $credits->map(fn (Credit $row) => ClientSummary::money((int) round((float) $row->getAttribute('total') * 100), (string) $row->currency_code))->implode(', ');

        return Stat::make(__('Clients with credit'), (string) $clients)
            ->description($clients === 0 ? __('No unused credit') : $totals)
            ->color($clients === 0 ? 'gray' : 'info')
            ->url(ClientResource::getUrl('index', ['filters' => ['with_credit' => ['value' => '1']]]));
    }

    /** WHMCS's dashboard counter: failed server actions waiting for staff (Σ1), always there, gray at zero. */
    private function pendingModuleActions(): ?Stat
    {
        if (!ModuleActionResource::canViewAny()) {
            return null;
        }
        $open = ModuleAction::query()->open()->count();

        return Stat::make('Pending Module Actions', (string) $open)
            ->description($open === 0 ? 'Nothing failed' : 'Failed server actions: retry or resolve')
            ->color($open === 0 ? 'gray' : 'danger')
            ->url(ModuleActionResource::getUrl());
    }

    private function invoiceTransaction(): Stat
    {
        $query = InvoiceTransaction::query()
            ->where('status', InvoiceTransactionStatus::Succeeded)
            ->where('is_credit_transaction', false);

        $chart = $this->trend(Trend::query(clone $query))->sum('amount');

        $previous = $this->previousPeriod(clone $query)->sum('amount');

        return $this->stat('Revenue', $chart, $previous, money: true);
    }

    private function getData(string $model, string $name, bool $lowerIsBetter = false): Stat
    {
        $chart = $this->trend(Trend::model($model))->count();

        $previous = $this->previousPeriod($model::query())->count();

        return $this->stat($name, $chart, $previous, $lowerIsBetter);
    }

    private function period(): DashboardPeriod
    {
        return DashboardPeriod::fromValue($this->period);
    }

    private function trend(Trend $trend): Trend
    {
        return $trend
            ->between(
                start: $this->period()->start(),
                end: now(),
            )
            ->interval($this->period()->interval());
    }

    /**
     * Scope the query to the period directly before the one shown, without overlapping it.
     */
    private function previousPeriod(Builder $query): Builder
    {
        $start = $this->period()->start();

        return $query
            ->where('created_at', '>=', $this->period()->start($start))
            ->where('created_at', '<', $start);
    }

    private function stat(string $label, Collection $chart, float|int $previous, bool $lowerIsBetter = false, bool $money = false): Stat
    {
        $current = $chart->sum('aggregate');

        $change = $current - $previous;

        $percentage = $previous > 0 ? (abs($change) / $previous) * 100 : 0;

        // No change is neither good nor bad news (owner 2026-10-21: «Increased by 0.00%» in green, or red).
        $color = $change == 0 ? 'gray' : (($lowerIsBetter ? $change < 0 : $change > 0) ? 'success' : 'danger');
        $period = strtolower($this->period()->label());
        $description = match (true) {
            $change == 0 => __('No change') . " ({$period})",
            $previous == 0 => __('New in this period') . " ({$period})",
            default => ($change > 0 ? 'Increased by ' : 'Decreased by ') . number_format($percentage, 1) . "% ({$period})",
        };

        // Revenue in the default currency, as the invoices write it.
        $value = $money ? (new Price(['price' => $current, 'currency' => Currency::query()->find(config('settings.default_currency'))]))->formatted->price : $current;

        return Stat::make($label, $value)
            // The sparkline only reads its color when Alpine initializes it, so key the stat on the
            // color to make Livewire replace the element when it changes (filamentphp/filament#13518)
            ->key("{$label}-{$color}")
            ->description($description)
            ->descriptionIcon($change == 0 ? null : ($change > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'))
            ->chart($chart->map(fn (TrendValue $value) => $value->aggregate)->toArray())
            ->color($color);
    }

    public static function canView(): bool
    {
        return auth()->user()->hasPermission('admin.widgets.overview');
    }
}
