<?php

namespace App\Admin\Pages;

use App\Models\Currency;
use App\Services\Reports\IncomeReport as Report;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * Income of a year (ROADMAP 6), for the accountant: the months and every invoice paid in it, net, tax and total, its
 * customer and how it was paid; refunds made in it on earlier invoices as rows of their own. The page shows the months
 * and the first rows; the file (XLSX, a sheet each; CSV) holds them all, downloaded from a signed link.
 */
class IncomeReport extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-file-list-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-file-list-3-fill';

    protected string $view = 'admin.pages.income-report';

    public string $currency = '';

    public int|string $year = 0;

    public function mount(): void
    {
        $this->currency = (string) config('settings.default_currency');
        $this->year = (int) now()->format('Y');
    }

    public function getTitle(): string
    {
        return __('Income of the year');
    }

    public static function getNavigationLabel(): string
    {
        return __('Income of the year');
    }

    /** Every customer's name, VAT number and payments: staff who may see invoices too (F9 of #280). */
    public static function canAccess(): bool
    {
        $staff = Auth::user();

        return (bool) $staff?->hasPermission('admin.reports.view') && (bool) $staff->hasPermission('admin.invoices.viewAny');
    }

    /** @return array<string, string> */
    public function currencies(): array
    {
        return Currency::query()->orderBy('code')->pluck('code', 'code')->all();
    }

    /** The currency and year shown: as chosen when they exist, else the default currency and this year. */
    public function code(): string
    {
        return array_key_exists($this->currency, $this->currencies()) ? $this->currency : (string) config('settings.default_currency');
    }

    public function chosenYear(): int
    {
        return in_array((int) $this->year, Report::years(), true) ? (int) $this->year : (int) now()->format('Y');
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return Report::year($this->code(), $this->chosenYear());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('xlsx')->label(__('Export XLSX'))->icon('ri-file-excel-2-line')->url(fn () => $this->exportUrl('xlsx')),
            Action::make('csv')->label(__('Export CSV'))->icon('ri-download-line')->url(fn () => $this->exportUrl('csv')),
        ];
    }

    public function exportUrl(string $format): string
    {
        return URL::temporarySignedRoute('admin.reports.income.export', now()->addMinutes(10),
            ['year' => $this->chosenYear(), 'currency' => $this->code(), 'format' => $format]);
    }
}
