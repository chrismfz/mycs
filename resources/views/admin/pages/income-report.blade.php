@php
    $report = $this->report();
    $currencyModel = \App\Models\Currency::query()->find($this->code());
    $money = fn ($amount) => (new \App\Classes\Price(['price' => $amount, 'currency' => $currencyModel], tax: 0))->format($amount);
@endphp
<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            {{ __('Year') }}
            <select wire:model.live="year" class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 text-sm">
                @foreach (\App\Services\Reports\IncomeReport::years() as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            {{ __('Currency') }}
            <select wire:model.live="currency" class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 text-sm">
                @foreach ($this->currencies() as $code)
                    <option value="{{ $code }}">{{ $code }}</option>
                @endforeach
            </select>
        </label>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Invoices paid in the year, by their payment date; a refund or credit note made in the year on an earlier invoice is a row of its own; credit purchases are not income.') }}</p>
    </div>

    <x-filament::section :heading="__('By month')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b dark:border-gray-700">
                        <th class="py-2 pr-4">{{ __('Month') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Count') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Net') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Tax') }}</th>
                        <th class="py-2 text-right">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['months'] as $month)
                        <tr class="border-b dark:border-gray-800">
                            <td class="py-2 pr-4">{{ ucfirst($month['month']->translatedFormat('F')) }}</td>
                            <td class="py-2 pr-4 text-right">{{ $month['count'] }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($month['net']) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($month['tax']) }}</td>
                            <td class="py-2 text-right whitespace-nowrap">{{ $money($month['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td class="py-2 pr-4">{{ __('Total') }}</td>
                        <td class="py-2 pr-4 text-right">{{ $report['totals']['count'] }}</td>
                        <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($report['totals']['net']) }}</td>
                        <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($report['totals']['tax']) }}</td>
                        <td class="py-2 text-right whitespace-nowrap">{{ $money($report['totals']['total']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('Paid invoices') . ' (' . count($report['invoices']) . ')'" collapsible collapsed>
        @if (count($report['invoices']) > \App\Services\Reports\IncomeReport::SHOWN)
            <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">{{ __('The first :count; the export holds them all.', ['count' => \App\Services\Reports\IncomeReport::SHOWN]) }}</p>
        @endif
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b dark:border-gray-700">
                        <th class="py-2 pr-4">{{ __('Paid on') }}</th>
                        <th class="py-2 pr-4">{{ __('Invoice no.') }}</th>
                        <th class="py-2 pr-4">{{ __('Client') }}</th>
                        <th class="py-2 pr-4">{{ __('Country') }}</th>
                        <th class="py-2 pr-4">{{ __('VAT number') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Net') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Tax') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Total') }}</th>
                        <th class="py-2">{{ __('Paid with') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($report['invoices'], 0, \App\Services\Reports\IncomeReport::SHOWN) as $row)
                        <tr class="border-b dark:border-gray-800">
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['paid_at']->format('d/m/Y') }}</td>
                            <td class="py-2 pr-4">{{ $row['number'] }}</td>
                            <td class="py-2 pr-4">{{ $row['client'] }}</td>
                            <td class="py-2 pr-4">{{ $row['country'] }}</td>
                            <td class="py-2 pr-4">{{ $row['vat'] }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['net']) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['tax']) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['total']) }}</td>
                            <td class="py-2">{{ $row['paid_with'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
