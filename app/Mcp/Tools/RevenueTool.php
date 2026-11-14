<?php

namespace App\Mcp\Tools;

use App\Models\Currency;
use App\Services\Reports\IncomeReport;
use App\Services\Reports\ProductIncome;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * «How did we do this month against last month, or the same month last year?» (owner 2026-11-14): the admin's Income
 * report (IncomeReport, cash: invoices paid, refunds and credit notes, credit given back) month by month for a year and
 * the one before, with the differences; optionally Income by product (ProductIncome). Nothing computed anew: the same
 * figures as the admin's pages. No customer is named, so the reports' permission is enough (as Income by product).
 */
#[Name('revenue')]
#[Description('Income month by month, as the admin\'s Income report (cash: invoices paid, less refunds, credit notes and credit given back; net, tax, total; informative, the fiscal books are Ekdosi\'s). For a year (default this one) and the year before: each month against the month before and the same month a year earlier, and the running month against the same days a year earlier. `by_product` adds Income by product (per category, product and TLD, against last year). One currency (default the default one). No customer is named. Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class RevenueTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer()->description('The year (default this one).'),
            'currency' => $schema->string()->description('Currency code (default the default currency).'),
            'by_product' => $schema->boolean()->description('Add Income by product for the year (default no).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.reports.view';
    }

    protected function run(Request $request): array
    {
        $now = CarbonImmutable::now();
        $year = (int) ($request->get('year') ?? $now->format('Y'));
        if ($year < 2000 || $year > (int) $now->format('Y')) {
            throw new \InvalidArgumentException('Give a year from 2000 to this one.');
        }
        $currency = strtoupper(trim((string) ($request->get('currency') ?? ''))) ?: (string) config('settings.default_currency');
        if (!Currency::query()->where('code', $currency)->exists()) {
            throw new \InvalidArgumentException("No currency {$currency}.");
        }

        $current = IncomeReport::year($currency, $year);
        $last = IncomeReport::year($currency, $year - 1);
        $months = [];
        foreach ($current['months'] as $i => $month) {
            if ($month['month']->greaterThan($now)) {
                break; // months to come: nothing yet
            }
            $before = $i > 0 ? $current['months'][$i - 1] : $last['months'][11];
            $yearAgo = $last['months'][$i];
            $months[] = [
                'month' => $month['month']->format('Y-m'), 'invoices' => $month['count'],
                'net' => $month['net'], 'tax' => $month['tax'], 'total' => $month['total'],
                'vs_previous_month' => self::change($month['net'], $before['net']),
                'vs_same_month_last_year' => self::change($month['net'], $yearAgo['net']),
            ];
        }

        $result = [
            'currency' => $currency, 'year' => $year, 'basis' => 'net (without VAT) unless named; as the admin\'s Income report',
            'notice' => IncomeReport::notice(),
            'months' => $months,
            'totals' => $current['totals'], 'last_year_totals' => $last['totals'],
        ];
        // The running month is not whole: the same days a year earlier as well, so «this month against last year» is fair.
        if ($year === (int) $now->format('Y')) {
            $from = $now->startOfMonth()->subYearNoOverflow();
            $until = $now->addDay()->startOfDay()->subYearNoOverflow();
            $cents = 0;
            foreach ($last['invoices'] as $row) {
                if ($row['paid_at'] >= $from && $row['paid_at'] < $until) {
                    $cents += self::cents($row['net']);
                }
            }
            $sameDays = (string) BigDecimal::of($cents)->dividedBy(100, 2);
            $running = $current['months'][(int) $now->format('n') - 1];
            $result['running_month'] = ['month' => $now->format('Y-m'), 'to' => $now->toDateString(), 'net' => $running['net'],
                'same_days_last_year' => $sameDays, 'change' => self::change($running['net'], $sameDays)];
            $yearToDate = 0;
            foreach ($last['invoices'] as $row) {
                if ($row['paid_at'] < $until) {
                    $yearToDate += self::cents($row['net']);
                }
            }
            $lastToDate = (string) BigDecimal::of($yearToDate)->dividedBy(100, 2);
            $result['year_to_date'] = ['net' => $current['totals']['net'], 'same_days_last_year' => $lastToDate,
                'change' => self::change($current['totals']['net'], $lastToDate)];
        }

        if ($request->get('by_product')) {
            $report = ProductIncome::year($currency, $year);
            $money = fn (int $cents) => (string) BigDecimal::of($cents)->dividedBy(100, 2);
            $result['by_product'] = [
                'basis' => 'net received; last_year is the same days a year earlier when the year is running',
                'to_date' => $report['to_date'],
                'total' => $money($report['total']), 'last_year_total' => $money($report['last_year_total']),
                'groups' => array_map(fn (array $group) => [
                    'name' => $group['name'], 'received' => $money($group['received']), 'last_year' => $money($group['last_year']),
                    'active' => $group['services'], 'new' => $group['new'], 'ended' => $group['ended'],
                    'items' => array_map(fn (array $item) => ['name' => $item['name'], 'received' => $money($item['received']),
                        'last_year' => $money($item['last_year']), 'active' => $item['services'], 'new' => $item['new'], 'ended' => $item['ended']], $group['items']),
                ], $report['groups']),
            ];
        }

        return $result;
    }

    /** @return array{amount: string, percent: string|null} the difference, and in % of $before (none when it was 0) */
    private static function change(string $now, string $before): array
    {
        $now = BigDecimal::of($now);
        $before = BigDecimal::of($before);
        $diff = $now->minus($before);

        return ['amount' => (string) $diff->toScale(2), 'percent' => $before->isZero() ? null
            : (string) $diff->multipliedBy(100)->dividedBy($before->abs(), 1, RoundingMode::HalfUp)];
    }

    private static function cents(string $amount): int
    {
        return BigDecimal::of($amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
