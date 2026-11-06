<?php

namespace App\Admin\Widgets\CronStat;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Models\Service;
use App\Services\Ops\CronDay;
use App\Services\Service\DunningForecast;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Throwable;

/**
 * The cron by day (owner 2026-11-06, ROADMAP 9β-4): a month's calendar, what it did on a day gone (CronDay), and what it
 * will suspend and terminate in the days to come if nothing is paid (DunningForecast), so staff can call first. Read
 * only: suspending stays on the service's page.
 */
class CronCalendar extends Widget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'admin.widgets.cron-calendar';

    #[Url(as: 'month')]
    public string $month = '';

    #[Url(as: 'day')]
    public string $day = '';

    public function mount(): void
    {
        $this->month = self::validMonth($this->month) ?? Carbon::today()->format('Y-m');
        $this->day = self::validDay($this->day) ?? Carbon::today()->toDateString();
    }

    public function previousMonth(): void
    {
        $this->month = Carbon::parse((self::validMonth($this->month) ?? Carbon::today()->format('Y-m')) . '-01')->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::parse((self::validMonth($this->month) ?? Carbon::today()->format('Y-m')) . '-01')->addMonth()->format('Y-m');
    }

    public function pick(string $day): void
    {
        $this->day = self::validDay($day) ?? $this->day;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $today = Carbon::today();
        $month = Carbon::parse((self::validMonth($this->month) ?? $today->format('Y-m')) . '-01');
        $day = Carbon::parse(self::validDay($this->day) ?? $today->toDateString());
        try {
            $forecast = DunningForecast::upcoming($today);
        } catch (Throwable $e) {
            report($e);
            $forecast = ['now' => ['suspend' => [], 'terminate' => []], 'days' => [],
                'suspend_on' => (bool) config('settings.cronjob_suspend_enabled', true), 'terminate_on' => (bool) config('settings.cronjob_terminate_enabled', true)];
        }
        $cells = CronDay::month($month);
        $days = [];
        $cursor = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        for (; $cursor->lessThanOrEqualTo($end); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $days[] = [
                'date' => $key, 'number' => $cursor->day, 'in_month' => $cursor->month === $month->month,
                'past' => $cursor->lessThan($today), 'today' => $cursor->isSameDay($today), 'future' => $cursor->greaterThan($today),
                'cell' => $cells[$key] ?? null, 'forecast' => $forecast['days'][$key] ?? null,
            ];
        }

        $weeks = array_chunk($days, 7);
        $isFuture = $day->greaterThan($today);
        $ids = $isFuture ? ($forecast['days'][$day->toDateString()] ?? ['suspend' => [], 'terminate' => []]) : null;

        return [
            'monthLabel' => $month->translatedFormat('F Y'),
            'weeks' => $weeks,
            'selected' => $day->toDateString(),
            'selectedLabel' => $day->translatedFormat('l j F Y'),
            'isFuture' => $isFuture,
            'isToday' => $day->isSameDay($today),
            'detail' => $isFuture ? null : CronDay::detail($day),
            'upcoming' => $ids === null ? null : ['suspend' => self::services($ids['suspend']), 'terminate' => self::services($ids['terminate'])],
            'now' => ['suspend' => self::services($forecast['now']['suspend']), 'terminate' => self::services($forecast['now']['terminate'])],
            'suspendOn' => $forecast['suspend_on'],
            'terminateOn' => $forecast['terminate_on'],
            'next' => array_slice($forecast['days'], 0, 3, true),
            // Links only to the pages the viewer may open (code review of #681).
            'serviceUrl' => fn (Service $service) => ServiceResource::canViewAny() ? ServiceResource::getUrl('edit', ['record' => $service]) : null,
            'clientUrl' => fn (?int $id) => $id === null || !ClientResource::canViewAny() ? null : ClientResource::getUrl('view', ['record' => $id]),
            'invoiceUrl' => fn (?int $id) => $id === null || !InvoiceResource::canViewAny() ? null : InvoiceResource::getUrl('edit', ['record' => $id]),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Service>
     */
    private static function services(array $ids): Collection
    {
        return $ids === [] ? collect() : Service::query()->with(['client', 'product'])->whereKey($ids)->orderBy('expires_at')->get();
    }

    private static function validMonth(string $month): ?string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : null;
    }

    private static function validDay(string $day): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)) ? $day : null;
    }
}
