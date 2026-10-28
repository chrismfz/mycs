@php
    $report = $this->report();
    $rows = $this->rows($report);
    $pages = max(1, (int) ceil(count($rows) / \App\Admin\Pages\IncomeByClient::PER_PAGE));
    $current = min(max(1, (int) $this->page), $pages);
    $shown = $this->withClients(array_slice($rows, ($current - 1) * \App\Admin\Pages\IncomeByClient::PER_PAGE, \App\Admin\Pages\IncomeByClient::PER_PAGE));
    $currencyModel = \App\Models\Currency::query()->find($this->code());
    $cents = fn (int $cents) => \App\Services\Reports\RevenueForecast::money($cents);
    $money = fn (int $amount) => (new \App\Classes\Price(['price' => $cents($amount), 'currency' => $currencyModel], tax: 0))->format($cents($amount));
    // Shares and changes in whole percent, for display only (the amounts are whole cents): never «-0».
    $share = fn (int $part, int $whole) => $whole > 0 ? (int) round($part * 100 / $whole) : 0;
    $percent = fn (int $now, int $then) => $then > 0 ? (($change = (int) round(($now - $then) * 100 / $then)) > 0 ? '+' : '') . $change . '%' : null;
    // A share of the total to a tenth of a percent, in the staff's locale (display only).
    $tenth = fn (int $part) => \Illuminate\Support\Number::format($report['total'] > 0 ? $part * 100 / $report['total'] : 0, 1) . '%';
    $tone = fn (int $now, int $then) => $now < $then ? 'text-danger-600 dark:text-danger-400' : 'text-success-700 dark:text-success-400';
    $period = $report['to_date']
        ? __(':year to :date, against the same days of :last', ['year' => $this->chosenYear(), 'date' => $report['until']->subDay()->format('d/m'), 'last' => $this->chosenYear() - 1])
        : __(':year, against :last', ['year' => $this->chosenYear(), 'last' => $this->chosenYear() - 1]);
    $rest = max(0, $report['paid'] - 50);
    $sortHeader = function (string $key, string $label) {
        $active = $this->chosenSort() === $key;

        return '<button type="button" wire:click="sortBy(\'' . $key . '\')" class="font-semibold hover:underline ' . ($active ? 'text-primary-600 dark:text-primary-400' : '') . '"'
            . ($active ? ' aria-sort="descending"' : '') . '>' . e($label) . ($active ? ' ▾' : '') . '</button>';
    };
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
        @if ($this->groups() !== [])
            <label class="text-sm">
                {{ __('Client group') }}
                <select wire:model.live="group" class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 text-sm">
                    <option value="">{{ __('All groups') }}</option>
                    @foreach ($this->groups() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="text-sm">
            {{ __('Search') }}
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Name, email, VAT number') }}"
                   class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 text-sm">
        </label>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('Clients who paid') }}</div>
            <div class="text-2xl font-semibold">{{ $report['paid'] }}</div>
            <div class="text-sm text-gray-500">{{ __('on average :amount each', ['amount' => $money($report['paid'] > 0 ? intdiv($report['total'], $report['paid']) : 0)]) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('The 10 largest') }}</div>
            <div class="text-2xl font-semibold">{{ $share($report['top10'], $report['positive']) }}% <span class="text-base font-medium text-gray-500">· {{ $money($report['top10']) }}</span></div>
            <div class="text-sm text-gray-500">{{ __('the 50 largest: :percent%', ['percent' => $share($report['top50'], $report['positive'])]) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('New clients') }}</div>
            <div class="text-2xl font-semibold">{{ $report['new']['count'] }}</div>
            <div class="text-sm text-gray-500">{{ __('brought in :amount', ['amount' => $money($report['new']['amount'])]) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">{{ __('Paid the year before, not this one') }}</div>
            <div class="text-2xl font-semibold text-warning-700 dark:text-warning-400">{{ $report['lost']['count'] }}</div>
            <div class="text-sm text-gray-500">{{ __('the year before :amount', ['amount' => $money($report['lost']['amount'])]) }}</div>
        </x-filament::section>
    </div>

    @if ($report['positive'] > 0)
        <x-filament::section :heading="__('How much depends on a few')">
            @php
                // Out of what the clients brought in (never past 100%: ADV-503-7).
                $top = $share($report['top10'], $report['positive']);
                $next = $share($report['top50'], $report['positive']) - $top;
            @endphp
            <div class="flex h-7 rounded-md overflow-hidden text-xs font-semibold" role="img"
                 aria-label="{{ __('The 10 largest :a%, the next 40 :b%, the other :count :c%', ['a' => $top, 'b' => $next, 'count' => $rest, 'c' => 100 - $top - $next]) }}">
                <div class="flex items-center px-2 bg-primary-600 text-white whitespace-nowrap overflow-hidden" style="width: {{ $top }}%">Top 10 · {{ $top }}%</div>
                <div class="flex items-center px-2 bg-primary-300 text-gray-950 whitespace-nowrap overflow-hidden" style="width: {{ $next }}%">11–50 · {{ $next }}%</div>
                <div class="flex items-center px-2 bg-gray-300 dark:bg-gray-600 text-gray-950 dark:text-white whitespace-nowrap overflow-hidden" style="width: {{ 100 - $top - $next }}%">{{ __('The other :count', ['count' => $rest]) }} · {{ 100 - $top - $next }}%</div>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('Clients, the largest first')" :description="$period">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-right border-b dark:border-gray-700">
                        <th class="py-2 pr-2 text-left">#</th>
                        <th class="py-2 pr-4 text-left">{{ __('Client') }}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('received', __('Income')) !!}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('last_year', __('Same period last year')) !!}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('change', __('Difference')) !!}</th>
                        <th class="py-2 pr-4">{{ __('Share') }}</th>
                        <th class="py-2 pr-4">{{ __('Cumulative') }}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('services', __('Active services')) !!}</th>
                        <th class="py-2 pr-4">{!! $sortHeader('annual', __('Yearly value of renewals')) !!}</th>
                        <th class="py-2">{!! $sortHeader('since', __('Client since')) !!}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shown as $row)
                        @php $change = $percent($row['received'], $row['last_year']); @endphp
                        <tr class="text-right border-b dark:border-gray-800">
                            <td class="py-2 pr-2 text-left text-gray-500">{{ $row['rank'] }}</td>
                            <td class="py-2 pr-4 text-left whitespace-nowrap">
                                <a href="{{ \App\Admin\Pages\IncomeByClient::clientUrl($row['client_id']) }}" class="text-primary-600 dark:text-primary-400 hover:underline">{{ $row['client']?->name ?? __('Client') . ' #' . $row['client_id'] }}</a>
                                @if ($row['fallen'])
                                    <span class="ml-1 rounded-full bg-warning-50 dark:bg-warning-400/10 px-2 py-0.5 text-xs text-warning-800 dark:text-warning-300">{{ __('fell :percent', ['percent' => ltrim((string) $change, '-+')]) }}</span>
                                @elseif ($row['new'])
                                    <span class="ml-1 rounded-full bg-primary-50 dark:bg-primary-400/10 px-2 py-0.5 text-xs text-primary-700 dark:text-primary-300">{{ __('new client') }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap font-semibold">{{ $money($row['received']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['last_year'] ? $money($row['last_year']) : '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap {{ $change === null ? '' : $tone($row['received'], $row['last_year']) }}">{{ $change ?? '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $tenth($row['received']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-500">{{ $this->chosenSort() === 'received' && trim($this->search) === '' ? $tenth($row['cumulative']) : '' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['services'] }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row['annual'] ? $money($row['annual']) : '—' }}</td>
                            <td class="py-2 whitespace-nowrap">{{ $row['client']?->created_at?->format('Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-6 text-center text-gray-500">{{ __('No income in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="font-semibold text-right">
                    <tr class="border-t-2 dark:border-gray-700">
                        <td></td>
                        <td class="py-2 pr-4 text-left">{{ __('All clients (:count)', ['count' => count($report['rows'])]) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $money($report['total']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $money($report['last_year_total']) }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap {{ $tone($report['total'], $report['last_year_total']) }}">{{ $percent($report['total'], $report['last_year_total']) ?? '—' }}</td>
                        <td class="py-2 pr-4">100%</td>
                        <td colspan="4"></td>
                    </tr>
                    @if ($report['unassigned']['received'] !== 0 || $report['unassigned']['last_year'] !== 0)
                        <tr class="font-normal text-gray-500">
                            <td></td>
                            <td class="py-2 pr-4 text-left">{{ __('Of which with no client (deleted)') }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $money($report['unassigned']['received']) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $money($report['unassigned']['last_year']) }}</td>
                            <td colspan="6"></td>
                        </tr>
                    @endif
                </tfoot>
            </table>
        </div>
        @if ($pages > 1)
            <div class="flex items-center gap-3 mt-3 text-sm">
                <span class="text-gray-500">{{ __(':from–:to of :count', ['from' => ($current - 1) * \App\Admin\Pages\IncomeByClient::PER_PAGE + 1, 'to' => ($current - 1) * \App\Admin\Pages\IncomeByClient::PER_PAGE + count($shown), 'count' => count($rows)]) }}</span>
                @if ($current > 1)
                    <button type="button" wire:click="goToPage({{ $current - 1 }})" class="text-primary-600 dark:text-primary-400 hover:underline">{{ __('Previous') }}</button>
                @endif
                @if ($current < $pages)
                    <button type="button" wire:click="goToPage({{ $current + 1 }})" class="text-primary-600 dark:text-primary-400 hover:underline">{{ __('Next') }}</button>
                @endif
            </div>
        @endif
    </x-filament::section>

    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Net amounts, without tax, in one currency. Income: the client\'s invoices paid in the period, as on Income & forecast (the same total). Yearly value of renewals: what its active services and auto-renewing domains bring in a year if all renewed. «fell»: a top-50 client down over 30%.') }}</p>
</x-filament-panels::page>
