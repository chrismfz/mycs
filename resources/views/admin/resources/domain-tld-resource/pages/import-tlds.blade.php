<x-filament-panels::page>
    {{ $this->form }}

    @php
        $grid = $this->grid();
        $operations = $this->operations();
        $labels = ['register' => __('Register'), 'renew' => __('Renew'), 'transfer' => __('Transfer'), 'grace' => __('Grace'), 'redemption' => __('Redemption')];
        $tones = [
            'loss' => 'bg-danger-50 text-danger-700 dark:bg-danger-500/15 dark:text-danger-400',
            'thin' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
        ];
        $cell = 'border border-gray-200 px-2 py-1 dark:border-white/10';
        $head = 'border border-gray-200 bg-gray-50 px-2 py-1 font-medium text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300';
        $arrow = fn (string $column) => $this->sort === $column ? ($this->direction === 'asc' ? ' ↑' : ' ↓') : '';
        $first = ($this->pageNo - 1) * $this->perPage;
    @endphp

    @if ($this->fetchExpired())
        <x-filament::section>
            <p class="text-sm text-warning-600 dark:text-warning-400">{{ __('The costs fetched are no longer kept: fetch them again.') }}</p>
        </x-filament::section>
    @elseif ($grid['total'] > 0)
        <x-filament::section :heading="__('Costs and prices (1 year)')"
            :description="__(':total TLDs, :alerts with a price below or near its cost.', ['total' => number_format($grid['total']), 'alerts' => $grid['alerts']])">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <x-filament::input.wrapper class="w-56" prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" :placeholder="__('Search TLDs')" :aria-label="__('Search TLDs')" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="filter" :aria-label="__('Show')">
                        <option value="all">{{ __('All') }}</option>
                        <option value="mycs">{{ __('In MyCS') }}</option>
                        <option value="new">{{ __('New') }}</option>
                        <option value="alerts">{{ __('Alerts') }}</option>
                        <option value="geo">{{ __('Geographic') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="currency" :aria-label="__('Currency')">
                        @foreach ($this->currencies() as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <span class="ms-auto flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <span class="inline-flex items-center gap-1"><span class="inline-block size-3 rounded-sm border border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/15"></span>{{ __('Below cost') }}</span>
                    <span class="inline-flex items-center gap-1"><span class="inline-block size-3 rounded-sm border border-warning-300 bg-warning-50 dark:border-warning-500/40 dark:bg-warning-500/15"></span>{{ __('Up to 10% above cost') }}</span>
                    <span><span class="text-primary-600 dark:text-primary-400">→ 9.99</span> {{ __('what the import would write') }}</span>
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th rowspan="2" class="{{ $head }} w-8">
                                <x-filament::input.checkbox wire:click="togglePage" :aria-label="__('Tick the TLDs of this page')" />
                            </th>
                            <th rowspan="2" class="{{ $head }} text-left">
                                <button type="button" wire:click="sortBy('tld')" class="hover:underline">TLD{{ $arrow('tld') }}</button>
                            </th>
                            <th rowspan="2" class="{{ $head }} w-8" title="{{ __('In MyCS') }}">
                                <button type="button" wire:click="sortBy('default')" class="hover:underline" aria-label="{{ __('In MyCS') }}">
                                    <x-filament::icon icon="heroicon-m-circle-stack" class="mx-auto size-4" />
                                </button>
                            </th>
                            @foreach ($operations as $operation)
                                <th colspan="2" class="{{ $head }} text-center">{{ $labels[$operation] }}</th>
                            @endforeach
                            <th rowspan="2" class="{{ $head }} w-20"></th>
                        </tr>
                        <tr class="text-xs">
                            @foreach ($operations as $operation)
                                <th class="{{ $head }} text-right whitespace-nowrap">
                                    <button type="button" wire:click="sortBy('{{ $operation }}.new')" class="hover:underline">{{ __('Price') }}{{ $arrow("{$operation}.new") }}</button>
                                    /
                                    <button type="button" wire:click="sortBy('{{ $operation }}.cost')" class="hover:underline">{{ __('Cost') }}{{ $arrow("{$operation}.cost") }}</button>
                                </th>
                                <th class="{{ $head }} w-16 text-center">
                                    <button type="button" wire:click="sortBy('{{ $operation }}.margin')" class="hover:underline">{{ __('Margin') }}{{ $arrow("{$operation}.margin") }}</button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grid['rows'] as $tld => $row)
                            <tr wire:key="tld-{{ $tld }}" class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="{{ $cell }} text-center">
                                    @if ($row['importable'])
                                        <x-filament::input.checkbox value="{{ $tld }}" wire:model.live="selected" :aria-label="__('Import') . ' .' . $tld" />
                                    @endif
                                </td>
                                <td class="{{ $cell }} font-medium whitespace-nowrap">
                                    .{{ $tld }}
                                    @if (!$row['exists'] && $row['importable'])
                                        <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('new') }}</span>
                                    @endif
                                </td>
                                <td class="{{ $cell }} text-center">
                                    @if ($row['exists'])
                                        <x-filament::icon icon="heroicon-m-check" class="mx-auto size-4 text-success-600 dark:text-success-400" :label="__('In MyCS')" />
                                    @else
                                        <x-filament::icon icon="heroicon-m-x-mark" class="mx-auto size-4 text-gray-400 dark:text-gray-500" :label="__('Not in MyCS')" />
                                    @endif
                                </td>
                                @if ($row['error'] !== null || $row['not_offered'])
                                    <td colspan="{{ count($operations) * 2 }}" class="{{ $cell }} {{ $row['not_offered'] ? 'text-gray-500 dark:text-gray-400' : 'text-danger-600 dark:text-danger-400' }}">
                                        {{ $row['not_offered'] ? __('Not offered by this registrar.') : $row['error'] }}
                                    </td>
                                @else
                                    @foreach ($operations as $operation)
                                        @php $c = $row['ops'][$operation]; $tone = $tones[$c['alert']] ?? ''; @endphp
                                        <td class="{{ $cell }} {{ $tone }} text-right tabular-nums whitespace-nowrap">
                                            <div class="{{ $tone === '' ? 'text-gray-950 dark:text-white' : '' }} font-medium">
                                                {{ $c['now'] ?? '—' }}
                                                @if ($c['new'] !== null && $c['new'] !== $c['now'])
                                                    <span class="text-xs font-normal text-primary-600 dark:text-primary-400">→ {{ $c['new'] }}</span>
                                                @endif
                                            </div>
                                            <div class="text-xs {{ $tone === '' ? 'text-gray-500 dark:text-gray-400' : 'opacity-80' }}" @if ($c['cost_own'] !== null && $c['cost'] === null) title="{{ __('No rate for :currency: the cost in its own currency.', ['currency' => $this->currency]) }}" @endif>
                                                {{ $c['cost'] ?? $c['cost_own'] ?? '—' }}
                                            </div>
                                        </td>
                                        <td class="{{ $cell }} {{ $tone }} text-center text-xs tabular-nums whitespace-nowrap">
                                            {{ $c['margin'] !== null ? $c['margin'] . '%' : '—' }}
                                        </td>
                                    @endforeach
                                @endif
                                <td class="{{ $cell }} text-center">
                                    @if ($row['id'] !== null)
                                        <x-filament::button size="xs" color="gray" tag="a" target="_blank"
                                            :href="\App\Admin\Resources\DomainTldResource::getUrl('edit', ['record' => $row['id']])">
                                            {{ __('Pricing') }}
                                        </x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 4 + count($operations) * 2 }}" class="{{ $cell }} py-4 text-center text-gray-500 dark:text-gray-400">{{ __('No TLD matches.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                <span>{{ __(':from–:to of :count', ['from' => $grid['shown'] === 0 ? 0 : $first + 1, 'to' => min($first + $this->perPage, $grid['shown']), 'count' => number_format($grid['shown'])]) }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="perPage" :aria-label="__('Per page')">
                        @foreach (\App\Admin\Resources\DomainTldResource\Pages\ImportTlds::PER_PAGE as $n)
                            <option value="{{ $n }}">{{ $n }} / {{ __('page') }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @if ($grid['pages'] > 1)
                    <span class="ms-auto flex items-center gap-2">
                        <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" :label="__('Previous')" wire:click="$set('pageNo', {{ max(1, $this->pageNo - 1) }})" :disabled="$this->pageNo <= 1" />
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="pageNo" :aria-label="__('Page')">
                                @foreach (range(1, $grid['pages']) as $p)
                                    <option value="{{ $p }}">{{ $p }} / {{ $grid['pages'] }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" :label="__('Next')" wire:click="$set('pageNo', {{ min($grid['pages'], $this->pageNo + 1) }})" :disabled="$this->pageNo >= $grid['pages']" />
                    </span>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
