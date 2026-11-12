<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\ServiceCancellationResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Classes\Price;
use App\Enums\DashboardPeriod;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Ticket;
use App\Services\Admin\ClientSummary;
use App\Support\AdminBadge;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

class Overview extends BaseWidget
{
    // The dashboard's order: cards and bars on top, then two columns (the lists in `Dashboard::LEFT` and `RIGHT`, owner 2026-11-04).
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
            // Both open their list (owner 2026-11-12), as the other cards do.
            // For staff who may open the list only (review of #815, REV-815-2).
            $this->getData(Ticket::class, 'Tickets', lowerIsBetter: true)->url(TicketResource::canAccess() ? TicketResource::getUrl('index') : null),
            $this->getData(Service::class, 'Services')->url(ServiceResource::canAccess() ? ServiceResource::getUrl('index') : null),
            $this->clientsWithCredit(),
            $this->pendingCancellations(),
            $this->pendingModuleActions(),
        ]);
    }

    /**
     * All the cards in one row on a desktop screen: seven, or as many as this staff member may see (review of #279).
     * By the width the dashboard has (a container query: the sidebar open or folded), from 1024px; narrower, they wrap
     * in rows of four, three, two and one. The cards are smaller for it (density.css).
     */
    protected function getColumns(): int|array
    {
        $count = max(1, count($this->getCachedStats()));

        return ['default' => 1, '@sm' => min(2, $count), '@2xl' => min(3, $count), '@4xl' => min(4, $count), '@5xl' => $count,
            // A browser without container queries: by the screen's width, as Filament's own default.
            '!@md' => min(3, $count), '!@lg' => min(4, $count), '!@xl' => $count];
    }

    /** WHMCS's «Pending Orders» counter (Σ1-b2): always there, gray at zero. */
    private function pendingOrders(): ?Stat
    {
        if (!OrderResource::canViewAny()) {
            return null;
        }
        $pending = Order::query()->where('status', Order::STATUS_PENDING)->count();

        return $this->quiet(Stat::make(__('Pending Orders'), (string) $pending), $pending === 0)
            ->description($pending === 0 ? __('Nothing waiting') : __('Waiting to be accepted or set up'))
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

        return $this->quiet(Stat::make(__('Clients with credit'), (string) $clients), $clients === 0)
            ->description($clients === 0 ? __('No unused credit') : $totals)
            ->color($clients === 0 ? 'gray' : 'info')
            ->url(ClientResource::getUrl('index', ['filters' => ['with_credit' => ['value' => '1']]]));
    }

    /**
     * Cancellation requests not handled yet (owner 2026-10-24): their service still runs. Gray at zero; the list opens
     * on those, and the subline says how many end within a week. Counted as the menu's badges are (AdminBadge), under
     * the key of the sidebar's «Pending cancellations»: one count for both.
     */
    private function pendingCancellations(): ?Stat
    {
        if (!ServiceCancellationResource::canViewAny()) {
            return null;
        }
        $pending = AdminBadge::count(ServiceCancellation::class . '.pending', fn () => ServiceCancellation::query()->pending()->count());
        // Never more than the pending ones: the two are cached apart (the sidebar fills the first on every page).
        $soon = $pending === 0 ? 0 : min($pending, AdminBadge::count(ServiceCancellation::class . '.endingWithin7', fn () => ServiceCancellation::query()->endingWithin(7)->count()));

        return $this->quiet(Stat::make(__('Pending Cancellations'), (string) $pending), $pending === 0)
            ->description(match (true) {
                $pending === 0 => __('Nothing waiting'),
                $soon > 0 => trans_choice(':count ends within 7 days|:count end within 7 days', $soon),
                default => __('None ends within 7 days'),
            })
            ->color($pending === 0 ? 'gray' : ($soon > 0 ? 'warning' : 'info'))
            ->url(ServiceCancellationResource::getUrl('index', ['filters' => ['pending' => ['isActive' => true]]]));
    }

    /** WHMCS's dashboard counter: failed server actions waiting for staff (Σ1), always there, gray at zero. */
    private function pendingModuleActions(): ?Stat
    {
        if (!ModuleActionResource::canViewAny()) {
            return null;
        }
        $open = ModuleAction::query()->open()->count();

        return $this->quiet(Stat::make(__('Pending Module Actions'), (string) $open), $open === 0)
            // A failed action, or one staff do by hand (an offline module's suspension, say): both wait here (#628).
            ->description($open === 0 ? __('Nothing waiting') : __('Server actions for staff: do, retry or resolve'))
            ->color($open === 0 ? 'gray' : 'danger')
            ->url(ModuleActionResource::getUrl());
    }

    private function invoiceTransaction(): Stat
    {
        // The chart's payments, so that the card and the chart's total agree.
        $query = Revenue::payments();

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
        $period = mb_strtolower(__($this->period()->label()));
        $description = match (true) {
            $change == 0 => __('No change') . " ({$period})",
            $previous == 0 => __('New in this period') . " ({$period})",
            default => __($change > 0 ? 'Increased by :percent% (:period)' : 'Decreased by :percent% (:period)', ['percent' => Number::format($percentage, 1, locale: app()->getLocale()), 'period' => $period]),
        };

        // Revenue in the default currency, as the invoices write it.
        $value = $money ? (new Price(['price' => $current, 'currency' => Currency::query()->find(config('settings.default_currency'))]))->formatted->price : $current;

        // Dimmed only when both periods are empty: a drop to nothing is news (review of #640).
        return $this->quiet(Stat::make(__($label), $value), $current == 0 && $previous == 0)
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

    /**
     * A card with nothing in it is dimmed, as in the mockup (B2, owner 2026-11-04): what has a number stands out
     * (density.css, `.mycs-stat-quiet`).
     */
    private function quiet(Stat $stat, bool $quiet): Stat
    {
        return $quiet ? $stat->extraAttributes(['class' => 'mycs-stat-quiet']) : $stat;
    }
}
