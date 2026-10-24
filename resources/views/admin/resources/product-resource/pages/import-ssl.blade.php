<x-filament-panels::page>
    {{ $this->form }}

    @php
        $grid = $this->grid();
        $reasons = [
            'code_signing' => __('Code signing: not a website certificate'),
            'smime' => __('S/MIME: an email certificate'),
            'multi_domain' => __('Multi-domain: not offered yet'),
            'no_price' => __('No price from Openprovider'),
            'not_website' => __('Not a website certificate'),
        ];
        $validation = ['domain' => 'DV', 'organization' => 'OV', 'extended' => 'EV'];
        // As the TLD import's grid (owner 2026-10-24): below the cost, or within 10% over it.
        $tones = [
            'loss' => 'bg-danger-50 text-danger-700 dark:bg-danger-500/15 dark:text-danger-400',
            'thin' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400',
        ];
        $newTones = ['loss' => 'text-danger-600 dark:text-danger-400', 'thin' => 'text-warning-600 dark:text-warning-400'];
        $cell = 'border border-gray-200 px-2 py-1 dark:border-white/10';
        $head = 'border border-gray-200 bg-gray-50 px-2 py-1 font-medium text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300';
        $arrow = fn (string $column) => $this->sort === $column ? ($this->direction === 'asc' ? ' ↑' : ' ↓') : '';
        $years = max(1, min(\App\Services\Ssl\SslCatalog::MAX_YEARS, $this->gridYears));
    @endphp

    @if ($grid['total'] > 0)
        <x-filament::section :heading="__('Openprovider SSL products')"
            :description="__('Cost is this account\'s price at Openprovider; margin is over the cost. :total products, :alerts with a price below or near its cost. Tick the products to make or reprice; a price typed in «Your price» is written instead of the margin\'s.', ['total' => $grid['total'], 'alerts' => $grid['alerts']])">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <x-filament::input.wrapper class="w-56" prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" :placeholder="__('Search certificates')" :aria-label="__('Search certificates')" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="filter" :aria-label="__('Show')">
                        <option value="all">{{ __('All') }}</option>
                        <option value="sellable">{{ __('Can sell') }}</option>
                        <option value="mycs">{{ __('In MyCS') }}</option>
                        <option value="new">{{ __('New') }}</option>
                        <option value="alerts">{{ __('Alerts') }}</option>
                        <option value="typed">{{ __('Typed prices') }} ({{ $grid['typed'] }})</option>
                        <option value="unsellable">{{ __('Cannot sell') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="currency" :aria-label="__('Currency')">
                        @foreach ($this->currencies() as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="gridYears" :aria-label="__('Years')">
                        @foreach (range(1, \App\Services\Ssl\SslCatalog::MAX_YEARS) as $y)
                            <option value="{{ $y }}">{{ trans_choice(':count year|:count years', $y, ['count' => $y]) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @if ($grid['typed'] > 0)
                    <x-filament::button size="sm" color="gray" wire:click="clearTyped">{{ __('Clear typed prices') }} ({{ $grid['typed'] }})</x-filament::button>
                @endif
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
                            <th class="{{ $head }} w-8"></th>
                            <th class="{{ $head }} text-left"><button type="button" wire:click="sortBy('name')" class="hover:underline">{{ __('Certificate') }}{{ $arrow('name') }}</button></th>
                            <th class="{{ $head }} text-left">{{ __('Can sell') }}</th>
                            <th class="{{ $head }} text-right whitespace-nowrap">
                                <button type="button" wire:click="sortBy('now')" class="hover:underline">{{ __('Price') }}{{ $arrow('now') }}</button>
                                → <button type="button" wire:click="sortBy('new')" class="hover:underline">{{ __('new') }}{{ $arrow('new') }}</button>
                                / <button type="button" wire:click="sortBy('cost')" class="hover:underline">{{ __('Cost') }}{{ $arrow('cost') }}</button>
                            </th>
                            <th class="{{ $head }} text-center"><button type="button" wire:click="sortBy('margin')" class="hover:underline">{{ __('Margin') }}{{ $arrow('margin') }}</button></th>
                            <th class="{{ $head }} w-32 text-left">{{ __('Your price') }}</th>
                            <th class="{{ $head }} w-24"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($grid['rows'] as $row)
                            @php
                                $tone = $tones[$row['alert']] ?? '';
                                $key = \App\Admin\Resources\ProductResource\Pages\ImportSsl::overrideKey((int) $row['id'], $this->currency, $years);
                            @endphp
                            <tr wire:key="ssl-{{ $row['id'] }}" class="hover:bg-gray-50 dark:hover:bg-white/5 {{ $row['sellable'] ? '' : 'text-gray-400 dark:text-gray-500' }}">
                                <td class="{{ $cell }} text-center">
                                    @if ($row['sellable'])
                                        <x-filament::input.checkbox value="{{ $row['id'] }}" wire:model.live="selected" :aria-label="__('Import') . ' ' . $row['name']" />
                                    @endif
                                </td>
                                <td class="{{ $cell }}">
                                    <span class="font-medium">{{ $row['brand'] }} {{ $row['name'] }}</span>
                                    @if ($row['sellable'] && $row['products'] === [])
                                        <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('new') }}</span>
                                    @endif
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        #{{ $row['id'] }} · {{ $validation[$row['validation']] ?? $row['validation'] }}@if ($row['wildcard']) · wildcard @endif
                                        @foreach ($row['products'] as $product)
                                            · <a href="{{ $product['url'] }}" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">{{ $product['name'] }}</a>
                                            <span class="{{ $product['listed'] ? 'text-success-600 dark:text-success-400' : '' }}">({{ $product['listed'] ? __('listed') : __('hidden') }})</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="{{ $cell }} text-xs">
                                    @if ($row['sellable'])
                                        <x-filament::icon icon="heroicon-m-check" class="size-4 text-success-600 dark:text-success-400" :label="__('Yes')" />
                                    @else
                                        {{ $reasons[$row['reason']] ?? $row['reason'] }}
                                    @endif
                                </td>
                                @if ($row['line'] === null && $row['typed'] !== null)
                                    {{-- No cost in this currency (no rate), yet a price typed: it is what the import writes (ADV-6 of #442). --}}
                                    <td class="{{ $cell }} text-right tabular-nums whitespace-nowrap">
                                        <div class="font-medium">— <span class="text-xs font-normal text-primary-600 dark:text-primary-400">→ {{ $row['typed'] }} ✎</span></div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">—</div>
                                    </td>
                                    <td class="{{ $cell }} text-center text-xs">—</td>
                                @elseif ($row['line'] === null)
                                    <td colspan="2" class="{{ $cell }} text-xs text-gray-500 dark:text-gray-400">
                                        {{ isset($row['costs'][$years]) ? __('No :currency price: give its rate.', ['currency' => $this->currency]) : __('Not sold for :count year(s).', ['count' => $years]) }}
                                    </td>
                                @else
                                    <td class="{{ $cell }} {{ $tone }} text-right tabular-nums whitespace-nowrap">
                                        <div class="{{ $tone === '' ? 'text-gray-950 dark:text-white' : '' }} font-medium">
                                            {{ $row['now'] ?? '—' }}@if ($row['line']['now_high']) – {{ $row['line']['now_high'] }}@endif
                                            @if ($row['new'] !== null && $row['new'] !== $row['now'])
                                                <span class="text-xs font-normal {{ $newTones[$row['new_alert']] ?? 'text-primary-600 dark:text-primary-400' }}">→ {{ $row['new'] }}@if ($row['typed'] !== null) ✎@endif</span>
                                            @endif
                                        </div>
                                        <div class="text-xs {{ $tone === '' ? 'text-gray-500 dark:text-gray-400' : 'opacity-80' }}">{{ $row['cost'] ?? '—' }}</div>
                                        @if ($row['line']['kept_free'] && $row['typed'] === null)
                                            <div class="text-xs text-warning-600 dark:text-warning-400">{{ __('free or not offered prices kept') }}</div>
                                        @endif
                                    </td>
                                    <td class="{{ $cell }} {{ $tone }} text-center text-xs tabular-nums whitespace-nowrap">
                                        {{ $row['now_margin'] !== null ? $row['now_margin'] . '%' : '—' }}
                                        @if ($row['new_margin'] !== null && $row['new_margin'] !== $row['now_margin'])
                                            <span class="{{ $newTones[$row['new_alert']] ?? 'text-primary-600 dark:text-primary-400' }}">→ {{ $row['new_margin'] }}%</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="{{ $cell }}">
                                    @if ($row['priceable'])
                                        <input type="text" inputmode="decimal" wire:model.live.debounce.500ms="overrides.{{ $key }}" placeholder="{{ $row['line']['new'] ?? '' }}"
                                            aria-label="{{ __('Your price for :name', ['name' => $row['name']]) }}"
                                            class="w-28 rounded-md border px-2 py-1 text-right text-sm tabular-nums dark:bg-white/5 {{ $row['typed_invalid'] ? 'border-danger-500' : 'border-gray-300 dark:border-white/10' }}" />
                                        @if ($row['typed_invalid'])
                                            <div class="text-xs text-danger-600 dark:text-danger-400">{{ __('Not a price') }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="{{ $cell }} text-center">
                                    @foreach ($row['products'] as $product)
                                        <x-filament::button size="xs" color="gray" tag="a" target="_blank" :href="$product['url']" :tooltip="$product['name']">
                                            {{ __('Pricing') }}
                                        </x-filament::button>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="{{ $cell }} text-center text-gray-500 dark:text-gray-400">{{ __('No certificate matches.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
