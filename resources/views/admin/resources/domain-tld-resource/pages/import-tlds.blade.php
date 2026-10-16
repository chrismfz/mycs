<x-filament-panels::page>
    {{ $this->form }}

    @php $rows = $this->rows(); @endphp
    @if ($rows !== [])
        <x-filament::section :heading="__('Costs and prices (1 year)')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-3"></th><th class="py-2 pr-3">TLD</th><th class="py-2 pr-3">{{ __('Operation') }}</th>
                            <th class="py-2 pr-3">{{ __('Cost') }}</th><th class="py-2 pr-3">{{ __('Currency') }}</th>
                            <th class="py-2 pr-3">{{ __('Price now') }}</th><th class="py-2">{{ __('New price') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $tld => $row)
                            @if (isset($row['error']))
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-3"></td><td class="py-2 pr-3">.{{ $tld }}</td>
                                    <td colspan="5" class="py-2 text-danger-600">{{ $row['error'] }}</td>
                                </tr>
                            @else
                                @foreach ($row['currencies'] as $currency => $operations)
                                    @foreach ($operations as $operation => $price)
                                        <tr class="border-t border-gray-100 dark:border-white/5">
                                            <td class="py-2 pr-3">
                                                @if ($loop->parent->first && $loop->first)
                                                    <input type="checkbox" value="{{ $tld }}" wire:model.live="selected" class="rounded" aria-label="{{ __('Import') }} .{{ $tld }}">
                                                @endif
                                            </td>
                                            <td class="py-2 pr-3">@if ($loop->parent->first && $loop->first).{{ $tld }}@if (!$row['exists']) <span class="text-gray-500">({{ __('new') }})</span>@endif @endif</td>
                                            <td class="py-2 pr-3">{{ $operation }}</td>
                                            <td class="py-2 pr-3 whitespace-nowrap">{{ $row['costs'][$operation]['cost'] ?? '—' }} {{ $row['costs'][$operation]['currency'] ?? '' }}</td>
                                            <td class="py-2 pr-3">{{ $currency }}</td>
                                            <td class="py-2 pr-3">{{ $price['now'] ?? '—' }}</td>
                                            <td class="py-2 font-medium">{{ $price['new'] }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                                @if ($row['currencies'] === [])
                                    <tr class="border-t border-gray-100 dark:border-white/5">
                                        <td class="py-2 pr-3"></td><td class="py-2 pr-3">.{{ $tld }}</td>
                                        <td colspan="5" class="py-2 text-gray-500">{{ __('No currency of MyCS can be priced: give a rate.') }}</td>
                                    </tr>
                                @endif
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
