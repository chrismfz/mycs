@php
    $forecast = $this->forecast();
    $titles = \App\Admin\Pages\RevenueForecast::cycleTitles();
    $money = fn ($amount) => (new \App\Classes\Price(['price' => $amount, 'currency' => \App\Models\Currency::query()->find($this->currency)], tax: 0))->format($amount);
    $diff = fn ($now, $then) => (float) $then > 0 ? round(((float) $now - (float) $then) / (float) $then * 100) : null;
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
        <p class="text-sm text-gray-500">{{ __('Net amounts, without tax. Every active service and auto-renewing domain renews at its price on its due date.') }}</p>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach (['expected' => __('Expected this month'), 'invoiced' => __('Invoiced this month'), 'collected' => __('Collected this month')] as $key => $label)
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="text-2xl font-semibold">{{ $money($forecast['current'][$key]) }}</div>
            </x-filament::section>
        @endforeach
    </div>

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
