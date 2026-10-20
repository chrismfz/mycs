<?php

namespace App\Admin\Pages;

use App\Models\Currency;
use App\Services\Reports\IncomeReport as Report;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Income of a year (ROADMAP 6), for the accountant: the months and every invoice paid in it, net, tax and total, its
 * customer and how it was paid; to XLSX (a sheet each) or CSV. As every MyCS figure: refunds and notes in, credit
 * purchases never income.
 */
class IncomeReport extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-file-list-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-file-list-3-fill';

    protected string $view = 'admin.pages.income-report';

    public string $currency = '';

    public int $year = 0;

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

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.reports.view');
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
        return in_array($this->year, Report::years(), true) ? $this->year : (int) now()->format('Y');
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return Report::year($this->code(), $this->chosenYear());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('xlsx')->label(__('Export XLSX'))->icon('ri-file-excel-2-line')->action(fn () => $this->xlsx()),
            Action::make('csv')->label(__('Export CSV'))->icon('ri-download-line')->action(fn () => $this->csv()),
        ];
    }

    /** @return list<string> */
    private static function invoiceHeadings(): array
    {
        return [__('Paid on'), __('Invoice'), __('Client'), __('Country'), __('VAT number'), __('Net'), __('Tax'), __('Total'), __('Paid with')];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|float>
     */
    private static function invoiceRow(array $row): array
    {
        return [$row['paid_at']->format('Y-m-d'), $row['number'], $row['client'], $row['country'], $row['vat'],
            (float) $row['net'], (float) $row['tax'], (float) $row['total'], $row['paid_with']];
    }

    /**
     * A sheet row of plain cells: text always text (OpenSpout reads a string starting with «=» as a formula: a customer
     * named so would run in the accountant's spreadsheet), numbers numbers.
     *
     * @param  list<string|int|float>  $values
     */
    private static function row(array $values): Row
    {
        return new Row(array_map(fn ($value) => is_string($value) ? new StringCell($value, null) : new NumericCell($value, null), $values));
    }

    private function name(string $extension): string
    {
        return 'income-' . $this->chosenYear() . '-' . $this->code() . '.' . $extension;
    }

    private function xlsx(): BinaryFileResponse
    {
        $report = $this->report();
        $path = tempnam(sys_get_temp_dir(), 'income');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(__('Months'));
        $writer->addRow(self::row([__('Month'), __('Invoices'), __('Net'), __('Tax'), __('Total')]));
        foreach ($report['months'] as $month) {
            $writer->addRow(self::row([$month['month']->format('Y-m'), $month['count'], (float) $month['net'], (float) $month['tax'], (float) $month['total']]));
        }
        $writer->addRow(self::row([__('Total'), $report['totals']['count'], (float) $report['totals']['net'], (float) $report['totals']['tax'], (float) $report['totals']['total']]));
        $writer->addNewSheetAndMakeItCurrent()->setName(__('Invoices'));
        $writer->addRow(self::row(self::invoiceHeadings()));
        foreach ($report['invoices'] as $row) {
            $writer->addRow(self::row(self::invoiceRow($row)));
        }
        $writer->close();

        return response()->download($path, $this->name('xlsx'))->deleteFileAfterSend();
    }

    private function csv(): StreamedResponse
    {
        $report = $this->report();

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::invoiceHeadings(), escape: '\\');
            foreach ($report['invoices'] as $row) {
                // Text a spreadsheet could read as a formula (a customer's name) starts with a quote.
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell, self::invoiceRow($row)), escape: '\\');
            }
            fclose($out);
        }, $this->name('csv'), ['Content-Type' => 'text/csv']);
    }
}
