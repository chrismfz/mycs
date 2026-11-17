@php
    $report = $this->report();
    $year = $this->chosenYear();
    $currencyModel = \App\Models\Currency::query()->find($this->code());
    $cents = fn (int $cents) => \App\Services\Reports\RevenueForecast::money($cents);
    $money = fn (int $amount) => (new \App\Classes\Price(['price' => $cents($amount), 'currency' => $currencyModel], tax: 0))->format($cents(abs($amount)));
    // The sign before the currency (ADV-501-4); display only, the amounts are whole cents.
    $signed = fn (int $amount) => ($amount > 0 ? '+' : ($amount < 0 ? '-' : '')) . $money(abs($amount));
    $percent = fn (int $now, int $then) => $then > 0 ? (($change = (int) round(($now - $then) * 100 / $then)) > 0 ? '+' : '') . $change . '%' : null;
    $tone = fn (int $amount) => $amount < 0 ? 'text-danger-600 dark:text-danger-400' : ($amount > 0 ? 'text-success-700 dark:text-success-400' : '');
    $monthName = fn ($month) => ucfirst($month->translatedFormat('F Y'));
    $totals = $report['totals'];
    $last = $report['last_totals'];
    $lost = $totals['cancelled'] + $totals['terminated'];
    $lostCount = $totals['cancelled_count'] + $totals['terminated_count'];
    $lastLost = $last['cancelled'] + $last['terminated'];
    $lastLostCount = $last['cancelled_count'] + $last['terminated_count'];
    $period = $report['current']
        ? __(':year to :date, against the same days of :last', ['year' => $year, 'date' => $report['until']->format('d/m'), 'last' => $year - 1])
        : __(':year, against :last', ['year' => $year, 'last' => $year - 1]);
    // The chart's scale (display only): the largest month above or below zero.
    $top = max(1, ...array_map(fn ($row) => max($row['new'] + $row['up'], $row['cancelled'] + $row['terminated'] - $row['down']), $report['months'] ?: [['new' => 1, 'up' => 0, 'cancelled' => 0, 'terminated' => 0, 'down' => 0]]));
    $height = fn (int $amount) => round(max(0, $amount) * 100 / $top, 1) . '%';
    $legend = [
        ['bg-success-500', __('New services')],
        ['bg-primary-500', __('Upgrades')],
        ['bg-warning-500', __('Downgrades')],
        ['bg-danger-500', __('Cancellations')],
        ['bg-danger-300', __('Terminations')],
    ];
@endphp
<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            {{ __('Currency') }}
            <select wire:model.live="currency" class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 text-sm">
                @foreach ($this->currencies() as $code)
                    <option value="{{ $code }}">{{ $code }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            {{ __('Year') }}
            <select wire:model.live="year" class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 text-sm">
                @foreach (\App\Services\Reports\IncomeReport::years() as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
        <p class="text-sm text-gray-500">{{ __('Recurring income a month, net, without tax: what each service brings in a month at its price and cycle. New: services started. Lost: services that ended, at their last price. Free and one-time services are not counted.') }}</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ $report['current'] ? __('Recurring a month now') : __('Recurring a month at the end of :year', ['year' => $year]) }}</div>
            <div class="text-2xl font-semibold">{{ $money($report['mrr_now']) }}</div>
            <div class="text-sm text-gray-500">{{ __('a year: :amount', ['amount' => $money($report['mrr_now'] * 12)]) }} · {{ __('a year before: :amount', ['amount' => $money($report['mrr_year_ago'])]) }}
                @if (($change = $percent($report['mrr_now'], $report['mrr_year_ago'])) !== null)
                    · <span class="font-semibold {{ $tone($report['mrr_now'] - $report['mrr_year_ago']) }}">{{ $change }}</span>
                @endif
            </div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('New services') }}</div>
            <div class="text-2xl font-semibold">{{ $totals['new_count'] }} <span class="text-base font-medium text-success-700 dark:text-success-400">{{ __(':amount a month', ['amount' => $signed($totals['new'])]) }}</span></div>
            <div class="text-sm text-gray-500">{{ __(':year: :count, :amount a month', ['year' => $year - 1, 'count' => $last['new_count'], 'amount' => $signed($last['new'])]) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('Services lost') }}</div>
            <div class="text-2xl font-semibold">{{ $lostCount }} <span class="text-base font-medium text-danger-600 dark:text-danger-400">{{ __(':amount a month', ['amount' => $signed(-$lost)]) }}</span></div>
            <div class="text-sm text-gray-500">{{ __(':year: :count, :amount a month', ['year' => $year - 1, 'count' => $lastLostCount, 'amount' => $signed(-$lastLost)]) }}
                @if ($report['churn'] !== null)
                    · {{ __('churn :percent a month', ['percent' => \Illuminate\Support\Number::format($report['churn'] * 100, 1) . '%']) }}
                @endif
            </div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('New clients') }}</div>
            <div class="text-2xl font-semibold">{{ $totals['clients'] }}</div>
            <div class="text-sm text-gray-500">{{ __(':year: :count · their first payment', ['year' => $year - 1, 'count' => $last['clients']]) }}</div>
        </x-filament::section>
    </div>

    <x-filament::section :heading="__(':year month by month', ['year' => $year])" :description="$period">
        <div class="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-300 mb-3">
            @foreach ($legend as [$class, $label])
                <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm {{ $class }}"></span>{{ $label }}</span>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <div class="min-w-[640px]" role="img" aria-label="{{ __(':year month by month', ['year' => $year]) }}">
                <div class="grid grid-cols-12 gap-2 items-end h-32 border-b border-gray-300 dark:border-gray-600">
                    @foreach ($report['months'] as $row)
                        <div class="flex flex-col justify-end items-center h-full" title="{{ $monthName($row['month']) }}: {{ __('New services') }} {{ $signed($row['new']) }} · {{ __('Upgrades') }} {{ $signed($row['up']) }} · {{ __('Net change') }} {{ $signed($row['net']) }}">
                            <div class="w-3/5 rounded-t-sm bg-primary-500" style="height: {{ $height($row['up']) }}"></div>
                            <div class="w-3/5 bg-success-500" style="height: {{ $height($row['new']) }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="grid grid-cols-12 gap-2 items-start h-32">
                    @foreach ($report['months'] as $row)
                        <div class="flex flex-col items-center h-full" title="{{ $monthName($row['month']) }}: {{ __('Downgrades') }} {{ $signed($row['down']) }} · {{ __('Cancellations') }} {{ $signed(-$row['cancelled']) }} · {{ __('Terminations') }} {{ $signed(-$row['terminated']) }}">
                            <div class="w-3/5 bg-warning-500" style="height: {{ $height(-$row['down']) }}"></div>
                            <div class="w-3/5 bg-danger-500" style="height: {{ $height($row['cancelled']) }}"></div>
                            <div class="w-3/5 rounded-b-sm bg-danger-300" style="height: {{ $height($row['terminated']) }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="grid grid-cols-12 gap-2 pt-1 text-xs text-center">
                    @foreach ($report['months'] as $row)
                        <div>
                            <div class="text-gray-500">{{ ucfirst($row['month']->translatedFormat('M')) }}</div>
                            <div class="font-semibold {{ $tone($row['net']) }}">{{ $signed($row['net']) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('Month by month')" :description="__('Amounts a month. At the start + the month\'s movements = at the end.')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-right border-b dark:border-gray-700">
                        <th class="py-2 pr-4 text-left">{{ __('Month') }}</th>
                        <th class="py-2 pr-4">{{ __('At the start') }}</th>
                        <th class="py-2 pr-4">{{ __('New services') }}</th>
                        <th class="py-2 pr-4">{{ __('Upgrades') }}</th>
                        <th class="py-2 pr-4">{{ __('Downgrades') }}</th>
                        <th class="py-2 pr-4">{{ __('Cancellations') }}</th>
                        <th class="py-2 pr-4">{{ __('Terminations') }}</th>
                        <th class="py-2 pr-4">{{ __('Net change') }}</th>
                        <th class="py-2 pr-4">{{ __('At the end') }}</th>
                        <th class="py-2 pr-4">{{ __('Net last year') }}</th>
                        <th class="py-2 pr-4">{{ __('New clients') }}</th>
                        <th class="py-2">{{ __('Domains +/−') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['months'] as $row)
                        <tr class="text-right border-b dark:border-gray-800">
                            <td class="py-2 pr-4 text-left whitespace-nowrap">{{ $monthName($row['month']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $money($row['start']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $signed($row['new']) }} <span class="text-gray-500">({{ $row['new_count'] }})</span></td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['up_count'] ? $signed($row['up']) . ' (' . $row['up_count'] . ')' : '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['down_count'] ? $signed($row['down']) . ' (' . $row['down_count'] . ')' : '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['cancelled_count'] ? $signed(-$row['cancelled']) . ' (' . $row['cancelled_count'] . ')' : '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['terminated_count'] ? $signed(-$row['terminated']) . ' (' . $row['terminated_count'] . ')' : '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap font-semibold {{ $tone($row['net']) }}">{{ $signed($row['net']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $money($row['end_mrr']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap {{ $tone($row['last_year']['net']) }}">{{ $signed($row['last_year']['net']) }}</td>
                            <td class="py-2 pr-4">{{ $row['clients'] }} <span class="text-gray-500">/ {{ $row['last_year']['clients'] }}</span></td>
                            <td class="py-2 whitespace-nowrap">+{{ $row['domains_new'] }} / −{{ $row['domains_lost'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="font-semibold text-right">
                    <tr class="border-t-2 dark:border-gray-700">
                        <td class="py-2 pr-4 text-left">{{ $report['current'] ? __('To :date', ['date' => $report['until']->format('d/m')]) : __('The whole year') }}</td>
                        <td></td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $signed($totals['new']) }} ({{ $totals['new_count'] }})</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $signed($totals['up']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $signed($totals['down']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $signed(-$totals['cancelled']) }} ({{ $totals['cancelled_count'] }})</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $signed(-$totals['terminated']) }} ({{ $totals['terminated_count'] }})</td>
                        <td class="py-2 pr-4 whitespace-nowrap {{ $tone($totals['net']) }}">{{ $signed($totals['net']) }}</td>
                        <td></td>
                        <td class="py-2 pr-4 whitespace-nowrap {{ $tone($last['net']) }}">{{ $signed($last['net']) }}</td>
                        <td class="py-2 pr-4">{{ $totals['clients'] }} / {{ $last['clients'] }}</td>
                        <td class="py-2 whitespace-nowrap">+{{ $totals['domains_new'] }} / −{{ $totals['domains_lost'] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @if ($report['unknown']['count'] > 0)
            <p class="mt-3 text-sm text-warning-700 dark:text-warning-400">{{ trans_choice(':count service ended with no known date (WHMCS kept none): left out, :amount a month when it ran. Give its date on the service (Ended On) to count it.|:count services ended with no known date (WHMCS kept none): left out, :amount a month when they ran. Give their date on the service (Ended On) to count them.', $report['unknown']['count'], ['amount' => $money($report['unknown']['cents'])]) }}</p>
        @endif
    </x-filament::section>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-filament::section :heading="__('By product')" :description="__('What each product gained and lost a month, in the same period. A change of package moves its value from one product to the other.')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-right border-b dark:border-gray-700">
                            <th class="py-2 pr-4 text-left">{{ __('Product') }}</th>
                            <th class="py-2 pr-4">{{ __('New services') }}</th>
                            <th class="py-2 pr-4">{{ __('Changes of package') }}</th>
                            <th class="py-2 pr-4">{{ __('Lost') }}</th>
                            <th class="py-2 pr-4">{{ __('Net change') }}</th>
                            <th class="py-2">{{ __('Now') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['products'] as $product)
                            <tr class="text-right border-b dark:border-gray-800">
                                <td class="py-2 pr-4 text-left">{{ $product['name'] }} <span class="text-gray-500">{{ $product['category'] }}</span></td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $product['new'] ? $signed($product['new']) : '—' }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $product['moved'] ? $signed($product['moved']) : '—' }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $product['lost'] ? $signed(-$product['lost']) : '—' }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap font-semibold {{ $tone($product['net']) }}">{{ $signed($product['net']) }}</td>
                                <td class="py-2 whitespace-nowrap">{{ $money($product['now']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-4 text-center text-gray-500">{{ __('Nothing came or went.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('Why they left')" :description="__('The reasons of the cancellation requests, as written; the endings with no request apart.')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-right border-b dark:border-gray-700">
                            <th class="py-2 pr-4 text-left">{{ __('Reason') }}</th>
                            <th class="py-2 pr-4">{{ __('Services') }}</th>
                            <th class="py-2">{{ __('A month') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['reasons'] as $reason)
                            <tr class="text-right border-b dark:border-gray-800">
                                <td class="py-2 pr-4 text-left">{{ \App\Admin\Pages\GrowthAndChurn::reasonLabel($reason) }}</td>
                                <td class="py-2 pr-4">{{ $reason['count'] }}</td>
                                <td class="py-2 whitespace-nowrap">{{ $signed(-$reason['cents']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-4 text-center text-gray-500">{{ __('No service ended.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
