@php
    $forecast = $this->forecast();
    $titles = \App\Admin\Pages\RevenueForecast::cycleTitles();
    $currencyModel = \App\Models\Currency::query()->find($this->code());
    $money = fn ($amount) => (new \App\Classes\Price(['price' => $amount, 'currency' => $currencyModel], tax: 0))->format($amount);
    // Display only (the amounts are decimal strings): a whole percent, never «-0» (ADV-501-5).
    $diff = fn ($now, $then) => (float) $then > 0 ? (int) round(((float) $now - (float) $then) / (float) $then * 100) : null;
    $plan = $this->plan();
    $summary = $this->summary();
    $year = $this->chosenYear();
    // The sign before the currency, for a fall as for a rise (ADV-501-4).
    $signed = fn ($amount) => ((float) $amount > 0 ? '+' : ((float) $amount < 0 ? '-' : '')) . $money(ltrim((string) $amount, '-'));
    $percent = fn ($now, $then) => ($change = $diff($now, $then)) === null ? '—' : ($change > 0 ? '+' : '') . $change . '%';
    $tone = fn ($now, $then) => (float) $now < (float) $then ? 'text-danger-600 dark:text-danger-400' : 'text-success-700 dark:text-success-400';
    $monthName = fn ($month) => ucfirst($month->translatedFormat('F Y'));
    // The chart's scale (display only): the highest month, this year or the last.
    $top = max(1.0, ...array_map(fn ($row) => max((float) $row['total'], (float) $row['last_year']), $plan['months']));
    $height = fn ($amount) => round((float) $amount / $top * 100, 1) . '%';
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
                @foreach ($this->years() as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
        <p class="text-sm text-gray-500">{{ __('Net amounts, without tax. Received: the invoices paid in the month. Expected: what is left on the open invoices due in the month, and the renewals of services and domains falling due in it not invoiced yet, as the billing renews them.') }}</p>
    </div>


    @if ($summary)
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ __('This year to :date', ['date' => $summary['year']['until']->format('d/m')]) }}</div>
                <div class="text-2xl font-semibold">{{ $money($summary['year']['received']) }}</div>
                <div class="text-sm text-gray-500">{{ __('same period :year: :amount', ['year' => $year - 1, 'amount' => $money($summary['year']['last_year'])]) }}
                    · <span class="font-semibold {{ $tone($summary['year']['received'], $summary['year']['last_year']) }}">{{ $percent($summary['year']['received'], $summary['year']['last_year']) }}</span></div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ __(':month: received / expected', ['month' => $monthName($summary['this_month']['month'])]) }}</div>
                <div class="text-2xl font-semibold">{{ $money($summary['this_month']['received']) }} <span class="font-medium text-gray-500">/ {{ $money($summary['this_month']['expected']) }}</span></div>
                <div class="text-sm text-gray-500">{{ __('total :total · last year :amount', ['total' => $money($summary['this_month']['total']), 'amount' => $money($summary['this_month']['last_year'])]) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ trans_choice('Next month|Next :count months', count($summary['ahead'])) }}</div>
                <div class="text-2xl font-semibold">{{ $money($summary['ahead_total']) }}</div>
                <div class="text-sm text-gray-500">{{ __('the same months last year: :amount', ['amount' => $money($summary['ahead_last_year'])]) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ __('All :year, if everyone pays', ['year' => $year]) }}</div>
                <div class="text-2xl font-semibold">{{ $money($plan['totals']['total']) }}</div>
                <div class="text-sm text-gray-500">{{ $year - 1 }}: {{ $money($plan['totals']['last_year']) }}
                    · <span class="font-semibold {{ $tone($plan['totals']['total'], $plan['totals']['last_year']) }}">{{ $percent($plan['totals']['total'], $plan['totals']['last_year']) }}</span></div>
            </x-filament::section>
        </div>
    @endif

    <x-filament::section :heading="__(':year month by month, beside :last', ['year' => $year, 'last' => $year - 1])">
        <div class="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-300 mb-3">
            <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-gray-300 dark:bg-gray-600"></span>{{ $year - 1 }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-primary-600"></span>{{ __(':year received', ['year' => $year]) }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-primary-300"></span>{{ __(':year expected', ['year' => $year]) }}</span>
        </div>
        <div class="overflow-x-auto">
            <div class="grid grid-cols-12 gap-2 items-end h-56 min-w-[640px] border-b border-gray-200 dark:border-gray-700" role="img"
                 aria-label="{{ __(':year month by month, beside :last', ['year' => $year, 'last' => $year - 1]) }}">
                @foreach ($plan['months'] as $row)
                    <div class="flex gap-0.5 items-end justify-center h-full" title="{{ $monthName($row['month']) }}: {{ $money($row['total']) }} · {{ $year - 1 }}: {{ $money($row['last_year']) }}">
                        <div class="w-2/5 rounded-t-sm bg-gray-300 dark:bg-gray-600" style="height: {{ $height($row['last_year']) }}"></div>
                        <div class="w-2/5 h-full flex flex-col justify-end">
                            <div class="rounded-t-sm bg-primary-300" style="height: {{ $height($row['expected']) }}"></div>
                            <div class="bg-primary-600" style="height: {{ $height($row['received']) }}"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="grid grid-cols-12 gap-2 min-w-[640px] pt-1 text-xs text-gray-500 text-center">
                @foreach ($plan['months'] as $row)
                    <div>{{ ucfirst($row['month']->translatedFormat('M')) }}</div>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('Month by month')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-right border-b dark:border-gray-700">
                        <th class="py-2 pr-4 text-left">{{ __('Month') }}</th>
                        <th class="py-2 pr-4">{{ __('Received') }}</th>
                        <th class="py-2 pr-4">{{ __('Expected') }}</th>
                        <th class="py-2 pr-4">{{ __('Total') }}</th>
                        <th class="py-2 pr-4">{{ __('Same month last year') }}</th>
                        <th class="py-2 pr-4">{{ __('Difference') }}</th>
                        <th class="py-2 pr-4">%</th>
                        <th class="py-2 pr-4">{{ __('Cumulative this year') }}</th>
                        <th class="py-2">{{ __('Cumulative last year') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plan['months'] as $row)
                        <tr class="text-right border-b dark:border-gray-800 {{ (float) $row['expected'] > 0 ? 'bg-gray-50 dark:bg-white/5' : '' }}">
                            <td class="py-2 pr-4 text-left whitespace-nowrap">{{ $monthName($row['month']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ (float) $row['received'] ? $money($row['received']) : '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-500">{{ (float) $row['expected'] ? $money($row['expected']) : '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap font-semibold">{{ $money($row['total']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $money($row['last_year']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap {{ $tone($row['total'], $row['last_year']) }}">{{ $signed($row['difference']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap {{ $tone($row['total'], $row['last_year']) }}">{{ $percent($row['total'], $row['last_year']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $money($row['cumulative']) }}</td>
                            <td class="py-2 whitespace-nowrap">{{ $money($row['cumulative_last_year']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="font-semibold text-right">
                    @if ($summary)
                        <tr class="border-t-2 dark:border-gray-700">
                            <td class="py-2 pr-4 text-left">{{ __('To :date (the same days)', ['date' => $summary['year']['until']->format('d/m')]) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $money($summary['year']['received']) }}</td>
                            <td colspan="2"></td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $money($summary['year']['last_year']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap {{ $tone($summary['year']['received'], $summary['year']['last_year']) }}">{{ $signed($summary['year_difference']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap {{ $tone($summary['year']['received'], $summary['year']['last_year']) }}">{{ $percent($summary['year']['received'], $summary['year']['last_year']) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    @endif
                    <tr class="{{ $summary ? '' : 'border-t-2 dark:border-gray-700' }}">
                        <td class="py-2 pr-4 text-left">{{ __('The whole year') }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $money($plan['totals']['received']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap text-gray-500">{{ $money($plan['totals']['expected']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $money($plan['totals']['total']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $money($plan['totals']['last_year']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap {{ $tone($plan['totals']['total'], $plan['totals']['last_year']) }}">{{ $signed($plan['totals']['difference']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap {{ $tone($plan['totals']['total'], $plan['totals']['last_year']) }}">{{ $percent($plan['totals']['total'], $plan['totals']['last_year']) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @if ($plan['after'] !== [])
            <div class="mt-4 text-sm">
                <div class="font-semibold">{{ __('After the end of the year') }}</div>
                <div class="text-gray-700 dark:text-gray-300">
                    @foreach ($plan['after'] as $row)
                        {{ $monthName($row['month']) }}: {{ $money($row['total']) }} ({{ __('last year :amount', ['amount' => $money($row['last_year'])]) }})@if (!$loop->last) · @endif
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>

    @if ($summary && $summary['overdue']['count'] > 0)
        <div class="rounded-xl border border-warning-200 bg-warning-50 dark:bg-warning-400/10 dark:border-warning-400/30 p-4 text-sm text-warning-800 dark:text-warning-300">
            {{ trans_choice('Overdue from earlier months: :amount (:count invoice)|Overdue from earlier months: :amount (:count invoices)', $summary['overdue']['count'], ['amount' => $money($summary['overdue']['amount'])]) }}
            {{ __('In no month above.') }}
        </div>
    @endif

    <h2 class="text-base font-semibold">{{ __('Forecast by billing cycle, the next 12 months') }}</h2>
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b dark:border-gray-700">
                        <th class="py-2 pr-4">{{ __('Month') }}</th>
                        @foreach ($titles as $title)
                            <th class="py-2 pr-4 text-right">{{ $title }}</th>
                        @endforeach
                        <th class="py-2 pr-4 text-right">{{ __('Domains') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Expected') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Same month last year') }}</th>
                        <th class="py-2 text-right">±</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($forecast['months'] as $row)
                        @php $change = $diff($row['total'], $row['last_year']); @endphp
                        <tr class="border-b dark:border-gray-800">
                            <td class="py-2 pr-4 whitespace-nowrap">{{ ucfirst($row['month']->translatedFormat('F Y')) }}</td>
                            @foreach (array_keys($titles) as $column)
                                <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['cycles'][$column]) }}</td>
                            @endforeach
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['domains']) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap font-semibold">{{ $money($row['total']) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['last_year']) }}</td>
                            <td class="py-2 text-right whitespace-nowrap {{ $change === null ? '' : ($change >= 0 ? 'text-success-600' : 'text-danger-600') }}">{{ $change === null ? '—' : ($change > 0 ? '+' : '') . $change . '%' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td class="py-2 pr-4">{{ __('Total') }}</td>
                        <td colspan="{{ count($titles) + 1 }}"></td>
                        <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($forecast['total']) }}</td>
                        <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($forecast['last_year']) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
