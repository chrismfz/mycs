<x-filament-panels::page>
    {{ $this->form }}

    @php
        $rows = $this->rows();
        $reasons = [
            'code_signing' => __('Code signing: not a website certificate'),
            'smime' => __('S/MIME: an email certificate'),
            'multi_domain' => __('Multi-domain: not offered yet'),
            'no_price' => __('No price from Openprovider'),
            'not_website' => __('Not a website certificate'),
        ];
        $validation = ['domain' => 'DV', 'organization' => 'OV', 'extended' => 'EV'];
    @endphp
    @if ($rows !== [])
        <x-filament::section :heading="__('Openprovider SSL products')"
            :description="__('Cost is this account\'s price at Openprovider. Margin is over the cost. Tick the products to make or reprice.')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-3"></th>
                            <th class="py-2 pr-3">{{ __('Certificate') }}</th>
                            <th class="py-2 pr-3">{{ __('Can sell') }}</th>
                            <th class="py-2 pr-3">{{ __('MyCS product') }}</th>
                            <th class="py-2 pr-3">{{ __('Years') }}</th>
                            <th class="py-2 pr-3">{{ __('Cost') }}</th>
                            <th class="py-2 pr-3">{{ __('Price now') }}</th>
                            <th class="py-2">{{ __('New price') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $lines = $row['lines'] ?: [null]; @endphp
                            @foreach ($lines as $line)
                                <tr class="{{ $loop->first ? 'border-t border-gray-200 dark:border-white/10' : '' }} {{ $row['sellable'] ? '' : 'text-gray-400 dark:text-gray-500' }}">
                                    @if ($loop->first)
                                        <td class="py-2 pr-3 align-top" rowspan="{{ count($lines) }}">
                                            @if ($row['sellable'])
                                                <input type="checkbox" value="{{ $row['id'] }}" wire:model.live="selected" class="rounded" aria-label="{{ __('Import') }} {{ $row['name'] }}">
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3 align-top" rowspan="{{ count($lines) }}">
                                            <span class="font-medium">{{ $row['brand'] }} {{ $row['name'] }}</span>
                                            <span class="text-xs text-gray-500">#{{ $row['id'] }} · {{ $validation[$row['validation']] ?? $row['validation'] }}@if ($row['wildcard']) · wildcard @endif</span>
                                        </td>
                                        <td class="py-2 pr-3 align-top" rowspan="{{ count($lines) }}">
                                            @if ($row['sellable'])
                                                <span class="text-success-600">{{ __('Yes') }}</span>
                                            @else
                                                {{ $reasons[$row['reason']] ?? $row['reason'] }}
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3 align-top" rowspan="{{ count($lines) }}">
                                            @forelse ($row['products'] as $product)
                                                <div>
                                                    <a href="{{ $product['url'] }}" class="text-primary-600 hover:underline">{{ $product['name'] }}</a>
                                                    <span class="text-xs {{ $product['listed'] ? 'text-success-600' : 'text-gray-500' }}">({{ $product['listed'] ? __('listed') : __('hidden') }})</span>
                                                </div>
                                            @empty
                                                <span class="text-gray-500">—</span>
                                            @endforelse
                                        </td>
                                    @endif
                                    @if ($line)
                                        <td class="py-2 pr-3">{{ $line['years'] }} · {{ $line['currency'] }}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap">{{ $line['cost'] ?? '—' }}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap">
                                            @if ($line['now'] !== null)
                                                {{ $line['now'] }}@if ($line['now_high']) – {{ $line['now_high'] }}@endif
                                                @if ($line['now_margin'] !== null)
                                                <span class="text-xs {{ (float) $line['now_margin'] < 0 ? 'text-danger-600' : 'text-gray-500' }}">({{ $line['now_margin'] }}%)</span>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2 whitespace-nowrap font-medium">
                                            @if ($line['new'] !== null)
                                                {{ $line['new'] }} @if ($line['new_margin'] !== null)<span class="text-xs text-gray-500">({{ $line['new_margin'] }}%)</span>@endif
                                                @if ($line['kept_free'])<span class="block text-xs font-normal text-warning-600">{{ __('free or not offered prices kept') }}</span>@endif
                                            @elseif ($line['kept_free'])
                                                <span class="text-xs font-normal text-warning-600">{{ __('kept: free or not offered') }}</span>
                                            @else
                                                <span class="text-xs font-normal text-warning-600">{{ __('kept (no rate or not chosen)') }}</span>
                                            @endif
                                        </td>
                                    @else
                                        <td colspan="4" class="py-2 text-gray-500">{{ __('No currency of MyCS can be priced: give a rate.') }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
