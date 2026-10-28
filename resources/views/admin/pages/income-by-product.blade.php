@php
    $report = $this->report();
    $groups = $this->sorted($report);
    $highlights = $this->highlights($report);
    $currencyModel = \App\Models\Currency::query()->find($this->code());
    $cents = fn (int $cents) => \App\Services\Reports\RevenueForecast::money($cents);
    $money = fn (int $amount) => (new \App\Classes\Price(['price' => $cents($amount), 'currency' => $currencyModel], tax: 0))->format($cents($amount));
    // Display only (the amounts are whole cents): whole percents, never «-0»; a share to a tenth, in the staff's locale.
    $percent = fn (int $now, int $then) => $then > 0 ? (($change = (int) round(($now - $then) * 100 / $then)) > 0 ? '+' : '') . $change . '%' : null;
    $tone = fn (int $now, int $then) => $now < $then ? 'text-danger-600 dark:text-danger-400' : 'text-success-700 dark:text-success-400';
    // Out of what the groups brought in: one netting below zero («Other»: refunds, credits) never makes another weigh
    // more than all (ADV-504-2).
    $positive = array_sum(array_map(fn ($group) => max(0, $group['received']), $groups));
    $tenth = fn (int $part) => \Illuminate\Support\Number::format($positive > 0 ? $part * 100 / $positive : 0, 1) . '%';
    $perService = fn (array $row) => $row['services'] ? $money(intdiv($row['received'], $row['services'])) : '—';
    $period = $report['to_date']
        ? __(':year to :date, against the same days of :last', ['year' => $this->chosenYear(), 'date' => $report['until']->subDay()->format('d/m'), 'last' => $this->chosenYear() - 1])
        : __(':year, against :last', ['year' => $this->chosenYear(), 'last' => $this->chosenYear() - 1]);
    // The chart's scale (display only): the largest group, this year or the last.
    $top = max(1, ...array_map(fn ($group) => max($group['received'], $group['last_year']), $groups ?: [['received' => 1, 'last_year' => 1]]));
    $width = fn (int $amount) => round(max(0, $amount) * 100 / $top, 1) . '%';
    $sortHeader = function (string $key, string $label) {
        $active = $this->chosenSort() === $key;

        return '<button type="button" wire:click="sortBy(\'' . $key . '\')" class="font-semibold hover:underline ' . ($active ? 'text-primary-600 dark:text-primary-400' : '') . '"'
            . ($active ? ' aria-sort="descending"' : '') . '>' . e($label) . ($active ? ' ▾' : '') . '</button>';
    };
    $change = fn (array $row) => (($diff = $row['received'] - $row['last_year']) > 0 ? '+' : ($diff < 0 ? '-' : '')) . $money(abs($diff));
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
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('The largest income') }}</div>
            <div class="text-xl font-semibold">{{ $highlights['largest']['name'] ?? '—' }}</div>
            @if ($highlights['largest'])
                <div class="text-sm text-gray-500">{{ trans_choice(':percent of the income with :count service|:percent of the income with :count services', (int) $highlights['largest']['services'], ['percent' => $tenth($highlights['largest']['received'])]) }}</div>
            @endif
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('The most per service') }}</div>
            <div class="text-xl font-semibold">{{ $highlights['best'] ? $money($highlights['best_per']) : '—' }} <span class="text-base font-medium text-gray-500">{{ $highlights['best']['name'] ?? '' }}</span></div>
            @if ($highlights['least'])
                <div class="text-sm text-gray-500">{{ __(':name: :amount per service', ['name' => $highlights['least']['name'], 'amount' => $money($highlights['least_per'])]) }}</div>
            @endif
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('The largest rise') }}</div>
            <div class="text-xl font-semibold text-success-700 dark:text-success-400">{{ $highlights['rise'] ? $highlights['rise']['name'] . ' ' . ($percent($highlights['rise']['received'], $highlights['rise']['last_year']) ?? '') : '—' }}</div>
            @if ($highlights['rise'])
                <div class="text-sm text-gray-500">{{ __(':amount against last year', ['amount' => $change($highlights['rise'])]) }}</div>
            @endif
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('The largest fall') }}</div>
            <div class="text-xl font-semibold text-danger-600 dark:text-danger-400">{{ $highlights['fall'] ? $highlights['fall']['name'] . ' ' . ($percent($highlights['fall']['received'], $highlights['fall']['last_year']) ?? '') : '—' }}</div>
            @if ($highlights['fall'])
                <div class="text-sm text-gray-500">{{ trans_choice(':count service, :amount|:count services, :amount', (int) $highlights['fall']['services'], ['amount' => $change($highlights['fall'])]) }}</div>
            @endif
        </x-filament::section>
    </div>

    @if ($groups !== [])
        <x-filament::section :heading="__('By category, :year beside :last', ['year' => $this->chosenYear(), 'last' => $this->chosenYear() - 1])" :description="$period">
            <div class="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-300 mb-3">
                <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-primary-600"></span>{{ $this->chosenYear() }}</span>
                <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-gray-300 dark:bg-gray-600"></span>{{ $this->chosenYear() - 1 }}</span>
            </div>
            <div class="flex flex-col gap-2" role="img" aria-label="{{ __('By category, :year beside :last', ['year' => $this->chosenYear(), 'last' => $this->chosenYear() - 1]) }}">
                @foreach ($groups as $group)
                    <div class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_6rem] gap-3 items-center text-sm">
                        <div class="truncate">{{ $group['name'] }}</div>
                        <div class="flex flex-col gap-0.5">
                            <div class="h-3 rounded-sm bg-primary-600" style="width: {{ $width($group['received']) }}"></div>
                            <div class="h-3 rounded-sm bg-gray-300 dark:bg-gray-600" style="width: {{ $width($group['last_year']) }}"></div>
                        </div>
                        <div class="text-right font-semibold whitespace-nowrap">{{ $money($group['received']) }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('Categories and products')" :description="__('Click a category for its products.')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-right border-b dark:border-gray-700">
                        <th class="py-2 pr-4 text-left">{{ __('Category / product') }}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('received', __('Income')) !!}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('last_year', __('Same period last year')) !!}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('change', __('Difference')) !!}</th>
                        <th class="py-2 pr-4">{{ __('Share') }}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('services', __('Active services')) !!}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('per_service', __('Income per service')) !!}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('annual', __('Yearly value of renewals')) !!}</th>
                        <th class="py-2">{{ __('New / cancelled') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($groups as $group)
                        @php $isOpen = in_array($group['key'], $this->open, true); @endphp
                        <tr class="text-right border-b dark:border-gray-800 font-semibold">
                            <td class="py-2 pr-4 text-left whitespace-nowrap">
                                @if ($group['items'] !== [])
                                    <button type="button" wire:click="toggle(@js($group['key']))" class="hover:underline" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">{{ $isOpen ? '▾' : '▸' }} {{ $group['name'] }}</button>
                                @else
                                    {{ $group['name'] }}
                                @endif
                            </td>
                            @include('admin.pages.partials.income-by-product-cells', ['row' => $group])
                        </tr>
                        @if ($isOpen)
                            @foreach ($group['items'] as $item)
                                <tr class="text-right border-b dark:border-gray-800 bg-gray-50 dark:bg-white/5">
                                    <td class="py-2 pr-4 pl-6 text-left whitespace-nowrap">{{ $item['name'] }}</td>
                                    @include('admin.pages.partials.income-by-product-cells', ['row' => $item])
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr><td colspan="9" class="py-6 text-center text-gray-500">{{ __('No income in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="font-semibold text-right">
                    <tr class="border-t-2 dark:border-gray-700">
                        <td class="py-2 pr-4 text-left">{{ __('Total') }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $money($report['total']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $money($report['last_year_total']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap {{ $tone($report['total'], $report['last_year_total']) }}">{{ $percent($report['total'], $report['last_year_total']) ?? '—' }}</td>
                        <td class="py-2 pr-4">100%</td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>

    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Net amounts, without tax, in one currency. Each paid invoice counts for the product of its service (domains: under Domains, by extension), in its lines\' net share; lines with no service (setup, by hand) under «Other». The same total as Income & forecast. Income per service: the year\'s income over the active services today.') }}</p>
</x-filament-panels::page>
