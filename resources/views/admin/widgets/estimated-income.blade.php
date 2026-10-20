{{-- WHMCS's Billing and Income Forecast widgets in one (owner 2026-10-21): net amounts. --}}
<x-filament-widgets::widget>
    {{-- Billing in the default currency (F5 of #278: said, not implied). --}}
    <x-filament::section :heading="__('Income') . ' · ' . $billingCurrency">
        <x-slot name="afterHeader">
            <a href="{{ $url }}" wire:navigate class="text-sm text-primary-600 dark:text-primary-400 hover:underline">{{ __('Details') }}</a>
        </x-slot>
        <div class="grid grid-cols-2 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            @foreach ([['today', __('Today'), 'text-success-600 dark:text-success-400'], ['month', __('This Month'), 'text-warning-600 dark:text-warning-400'],
                       ['year', __('This Year'), 'text-danger-500 dark:text-danger-400'], ['all', __('All Time'), '']] as [$key, $label, $colour])
                <div class="p-4 {{ $loop->index % 2 === 0 ? 'border-r' : '' }} {{ $loop->index < 2 ? 'border-b' : '' }} border-gray-200 dark:border-gray-700">
                    <div class="text-2xl {{ $colour }}">{{ $billing[$key] }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('Income Forecast') }}</div>
        <div class="grid gap-6 mt-2 {{ count($currencies) > 1 ? 'md:grid-cols-2' : '' }}">
            @foreach ($currencies as $currency)
                <div class="text-center text-sm leading-6">
                    <div class="font-semibold text-danger-600 dark:text-danger-400">{{ __(':code Currency', ['code' => $currency['code']]) }}</div>
                    @foreach ($currency['lines'] as $line)
                        <div>{{ $line['title'] }}: {{ $line['amount'] }} ({{ $line['count'] }})</div>
                    @endforeach
                    <div class="font-semibold text-success-600 dark:text-success-400">{{ __('Est. Annual') }}: {{ $currency['annual'] }}</div>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400 text-center">{{ __('Net amounts, without tax.') }}</p>
    </x-filament::section>
</x-filament-widgets::widget>
