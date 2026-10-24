{{--
    «Bank totals» (App\Admin\Widgets\BankTotals): one thin line under Registrar credit, in its style (owner 2026-10-24):
    each bank's, Stripe's and PayPal's balance, their sum in EUR; when it was read on hover, a problem in red.
--}}
<x-filament-widgets::widget>
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl bg-white px-4 py-2 text-sm shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Bank totals') }}</span>
        @foreach ($rows as $row)
        <span class="whitespace-nowrap" title="{{ $row['error'] ?? $row['note'] }}">
            <span class="text-gray-600 dark:text-gray-300">{{ $row['label'] }}</span>
            <span @class(['font-semibold tabular-nums', 'text-danger-600 dark:text-danger-400' => $row['error'], 'text-gray-950 dark:text-white' => !$row['error']])>
                @if ($row['error'])⚠ @endif{{ $row['money'] }}
            </span>
            @if ($row['error'])
            <span class="text-xs text-danger-600 dark:text-danger-400 whitespace-normal">{{ $row['error'] }}</span>
            @endif
        </span>
        @endforeach
        <span class="whitespace-nowrap ms-auto">
            <span class="text-gray-600 dark:text-gray-300">{{ __('Total') }}</span>
            <span class="font-semibold tabular-nums text-success-600 dark:text-success-400">{{ $total }}</span>
        </span>
    </div>
</x-filament-widgets::widget>
