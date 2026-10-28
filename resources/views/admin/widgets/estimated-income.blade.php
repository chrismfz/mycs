{{-- Income for cash planning (owner 2026-10-28, the approved mockup), then WHMCS's Income Forecast by cycle: net amounts. --}}
@php
    $signed = fn (array $change) => ((float) $change['difference'] > 0 ? '+' : '') . $money($change['difference'])
        . ($change['percent'] === null ? '' : ' (' . ($change['percent'] > 0 ? '+' : '') . $change['percent'] . '%)');
    $tone = fn (array $change) => (float) $change['difference'] < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-700 dark:text-success-400';
    $percent = fn (array $change) => ($change['percent'] > 0 ? '+' : '') . $change['percent'] . '%';
    $monthName = fn ($month) => ucfirst($month->translatedFormat('F Y'));
@endphp
<x-filament-widgets::widget>
    {{-- In the default currency (F5 of #278: said, not implied). --}}
    <x-filament::section :heading="__('Income') . ' · ' . $billingCurrency">
        <x-slot name="afterHeader">
            <a href="{{ $url }}" wire:navigate class="text-sm text-primary-600 dark:text-primary-400 hover:underline">{{ __('Details') }}</a>
        </x-slot>

        <div class="flex flex-col gap-3">
            <div class="flex justify-between items-baseline">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $monthName($month['month']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('so far, :date', ['date' => $year['until']->format('d/m')]) }}</div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Received') }}</div>
                    <div class="text-2xl font-semibold text-primary-600 dark:text-primary-400">{{ $money($month['received']) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Expected') }}</div>
                    <div class="text-2xl font-semibold text-gray-600 dark:text-gray-300">{{ $money($month['expected']) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Month total') }}</div>
                    <div class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $money($month['total']) }}</div>
                </div>
            </div>
            <div class="h-2.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden" role="img"
                 aria-label="{{ __(':percent% of the month received', ['percent' => $month['percent']]) }}">
                <div class="h-full bg-primary-600 dark:bg-primary-400" style="width: {{ $month['percent'] }}%"></div>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Expected: :invoices on open invoices due this month + :renewals in renewals not invoiced yet', ['invoices' => $money($month['invoices']), 'renewals' => $money($month['renewals'])]) }}
            </div>
            <div class="flex justify-between items-center gap-2 rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm">
                <span>{{ $monthName($month['month']->subYear()) }}: {{ $money($month['last_year']) }}</span>
                <span class="font-semibold {{ $tone($month['change']) }}">{{ $signed($month['change']) }}</span>
            </div>
            @if ($overdue['count'] > 0)
                <div class="flex justify-between items-center gap-2 rounded-lg bg-warning-50 dark:bg-warning-400/10 px-3 py-2 text-sm text-warning-800 dark:text-warning-300">
                    <span>{{ trans_choice('Overdue from earlier months: :amount (:count invoice)|Overdue from earlier months: :amount (:count invoices)', $overdue['count'], ['amount' => $money($overdue['amount'])]) }}</span>
                    @if ($overdueUrl)
                        <a href="{{ $overdueUrl }}" wire:navigate class="hover:underline">{{ __('View') }}</a>
                    @endif
                </div>
            @endif
        </div>

        <div class="grid grid-cols-2 mt-4 border-t border-gray-200 dark:border-gray-700">
            <div class="pt-3 pr-3 border-r border-gray-200 dark:border-gray-700">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __(':month (last month)', ['month' => $monthName($lastMonth['month'])]) }}</div>
                <div class="text-xl font-semibold text-gray-950 dark:text-white">{{ $money($lastMonth['received']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('last year :amount', ['amount' => $money($lastMonth['last_year'])]) }}
                    @if ($lastMonth['change']['percent'] !== null)
                        · <span class="font-semibold {{ $tone($lastMonth['change']) }}">{{ $percent($lastMonth['change']) }}</span>
                    @endif
                </div>
            </div>
            <div class="pt-3 pl-3">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('This year to :date', ['date' => $year['until']->format('d/m')]) }}</div>
                <div class="text-xl font-semibold text-gray-950 dark:text-white">{{ $money($year['received']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('same period :year: :amount', ['year' => $year['until']->subYear()->format('Y'), 'amount' => $money($year['last_year'])]) }}
                    @if ($year['change']['percent'] !== null)
                        · <span class="font-semibold {{ $tone($year['change']) }}">{{ $percent($year['change']) }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-gray-200 dark:border-gray-700">
            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Next months, if everyone pays') }}</div>
            <div class="grid grid-cols-3 gap-3 mt-2">
                @foreach ($ahead as $next)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2">
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $monthName($next['month']) }}</div>
                        <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $money($next['total']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('last year :amount', ['amount' => $money($next['last_year'])]) }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-gray-200 dark:border-gray-700">
            <div class="flex justify-between items-baseline">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Active services by cycle') }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('amount per cycle (services)') }}</div>
            </div>
            <div class="grid gap-4 mt-2 {{ count($currencies) > 1 ? 'md:grid-cols-2' : '' }}">
                @foreach ($currencies as $currency)
                    <div>
                        @if (count($currencies) > 1)
                            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200 mb-1">{{ __(':code Currency', ['code' => $currency['code']]) }}</div>
                        @endif
                        <div class="grid grid-cols-3 gap-2">
                            @foreach ($currency['lines'] as $line)
                                <div class="rounded-lg bg-gray-50 dark:bg-white/5 px-2.5 py-2">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $line['title'] }} ({{ $line['count'] }})</div>
                                    <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $line['amount'] }}</div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex justify-between items-center mt-2 rounded-lg bg-success-50 dark:bg-success-400/10 px-3 py-2 text-sm text-success-800 dark:text-success-300">
                            <span>{{ __('Est. Annual') }}</span>
                            <span class="font-bold">{{ $currency['annual'] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex justify-between mt-4 pt-3 border-t border-gray-200 dark:border-gray-700 text-sm text-gray-700 dark:text-gray-300">
            <span>{{ __('Today') }}: {{ $today }}</span>
            <span>{{ __('All Time') }}: {{ $all }}</span>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Net amounts, without tax.') }}</p>
    </x-filament::section>
</x-filament-widgets::widget>
