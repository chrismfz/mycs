<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Pages\IncomeReport as Page;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\Reports\IncomeReport;
use App\Support\StaffTwoFactor;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The year's income as a file (review F2 of #280): a plain download for staff who may open the report, from a signed
 * link the page makes; not through Livewire (a year of invoices base64 in its JSON).
 */
class IncomeExportController extends Controller
{
    public function __invoke(Request $request, int $year, string $currency, string $format): BinaryFileResponse|StreamedResponse
    {
        abort_unless(Page::canAccess(), 403);
        // Outside the panel: its 2FA requirement, here (review of #344), when staff 2FA is required (StaffTwoFactor).
        $staff = auth('staff')->user();
        abort_unless($staff instanceof Staff && StaffTwoFactor::satisfied($staff), 403);
        abort_unless(in_array($year, IncomeReport::years(), true), 404);
        abort_unless(array_key_exists($currency, (new Page)->currencies()), 404);
        $report = IncomeReport::year($currency, $year);
        $name = 'income-' . $year . '-' . $currency . '.' . $format;

        return $format === 'xlsx' ? self::xlsx($report, $name) : self::csv($report, $name);
    }

    /** @return list<string> */
    public static function headings(): array
    {
        return [__('Paid on'), __('Invoice no.'), __('Client'), __('Country'), __('VAT number'), __('Net'), __('Tax'), __('Total'), __('Paid with')];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|float>
     */
    private static function values(array $row): array
    {
        return [$row['paid_at']->format('Y-m-d'), $row['number'], $row['client'], $row['country'], $row['vat'],
            (float) $row['net'], (float) $row['tax'], (float) $row['total'], $row['paid_with']];
    }

    /** @param  array<string, mixed>  $report */
    private static function xlsx(array $report, string $name): BinaryFileResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'income');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName(__('Months'));
            $writer->addRow(self::row([__('Month'), __('Count'), __('Net'), __('Tax'), __('Total')]));
            foreach ($report['months'] as $month) {
                $writer->addRow(self::row([$month['month']->format('Y-m'), $month['count'], (float) $month['net'], (float) $month['tax'], (float) $month['total']]));
            }
            $writer->addRow(self::row([__('Total'), $report['totals']['count'], (float) $report['totals']['net'], (float) $report['totals']['tax'], (float) $report['totals']['total']]));
            $writer->addNewSheetAndMakeItCurrent()->setName(__('Paid invoices'));
            $writer->addRow(self::row(self::headings()));
            foreach ($report['invoices'] as $row) {
                $writer->addRow(self::row(self::values($row)));
            }
            $writer->close();
        } catch (Throwable $e) {
            @unlink($path); // never left behind (F6 of #280)

            throw $e;
        }

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    /**
     * For a European Excel (F7 of #280): UTF-8 with its BOM, «;» between fields and a decimal comma; text a spreadsheet
     * could read as a formula (a customer's name) starts with a quote.
     *
     * @param  array<string, mixed>  $report
     */
    private static function csv(array $report, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::headings(), ';', '"', '');
            foreach ($report['invoices'] as $row) {
                fputcsv($out, array_map(fn ($cell) => match (true) {
                    is_float($cell) => number_format($cell, 2, ',', ''),
                    preg_match('/^[=+\-@\t\r]/', $cell) === 1 => "'" . $cell,
                    default => $cell,
                }, self::values($row)), ';', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Text always text (OpenSpout reads a string starting with «=» as a formula), numbers numbers.
     *
     * @param  list<string|int|float>  $values
     */
    private static function row(array $values): Row
    {
        return new Row(array_map(fn ($value) => is_string($value) ? new StringCell($value, null) : new NumericCell($value, null), $values));
    }
}
