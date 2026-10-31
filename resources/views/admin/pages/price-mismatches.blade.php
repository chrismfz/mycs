@php
    $rows = $this->rows();
    $pages = max(1, (int) ceil(count($rows) / \App\Admin\Pages\PriceMismatches::PER_PAGE));
    $current = min(max(1, (int) $this->page), $pages);
    $shown = array_slice($rows, ($current - 1) * \App\Admin\Pages\PriceMismatches::PER_PAGE, \App\Admin\Pages\PriceMismatches::PER_PAGE);
    $url = fn (array $row) => $row['type'] === 'service'
        ? \App\Admin\Resources\ServiceResource::getUrl('edit', ['record' => $row['id']])
        : \App\Admin\Resources\DomainResource::getUrl('edit', ['record' => $row['id']]);
@endphp
<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('Services and domains that renew at a price other than the catalog\'s: the service\'s own price against what its plan, options, coupon and group discount make now; a domain\'s own renewal price against its extension\'s. Net amounts. Change them on the service or the domain.') }}
    </p>
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            {{ __('Type') }}
            <select wire:model.live="type" class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 text-sm">
                <option value="">{{ __('All') }}</option>
                <option value="service">{{ __('Services') }}</option>
                <option value="domain">{{ __('Domains') }}</option>
            </select>
        </label>
        <label class="text-sm">
            {{ __('Renews') }}
            <select wire:model.live="direction" class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700 text-sm">
                <option value="">{{ __('Above or below the catalog') }}</option>
                <option value="below">{{ __('Below the catalog') }}</option>
                <option value="above">{{ __('Above the catalog') }}</option>
            </select>
        </label>
        <span class="text-sm text-gray-600 dark:text-gray-400">{{ trans_choice(':count item|:count items', count($rows)) }}</span>
    </div>

    @if ($rows === [])
        <x-filament::section>{{ __('Everything renews at its catalog price.') }}</x-filament::section>
    @else
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-price-mismatches>
                    <thead>
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">{{ __('Item') }}</th>
                            <th class="py-2 pr-4">{{ __('Client') }}</th>
                            <th class="py-2 pr-4 text-right">{{ __('Renews at') }}</th>
                            <th class="py-2 pr-4 text-right">{{ __('Catalog') }}</th>
                            <th class="py-2 text-right">{{ __('Difference') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shown as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="{{ $row['type'] }}-{{ $row['id'] }}">
                                <td class="py-2 pr-4">
                                    <a class="text-primary-600 hover:underline" href="{{ $url($row) }}">{{ $row['type'] === 'service' ? __('Service') : __('Domain') }} #{{ $row['id'] }}</a>
                                    <span class="text-gray-600 dark:text-gray-400">{{ $row['name'] }}</span>
                                    @if ($row['locked'])<x-filament::badge color="gray" size="sm" class="inline-flex ms-1">{{ __('Price locked') }}</x-filament::badge>@endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($row['client_id'])
                                        <a class="hover:underline" href="{{ \App\Admin\Resources\ClientResource::getUrl('edit', ['record' => $row['client_id']]) }}">{{ $row['client'] }}</a>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['price'] }} {{ $row['currency'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['catalog'] }} {{ $row['currency'] }}</td>
                                <td class="py-2 text-right tabular-nums {{ (float) $row['difference'] < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-700 dark:text-success-400' }}">{{ (float) $row['difference'] > 0 ? '+' : '' }}{{ $row['difference'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($pages > 1)
                <div class="flex items-center gap-2 mt-4 text-sm">
                    <x-filament::button size="sm" color="gray" wire:click="$set('page', {{ max(1, $current - 1) }})" :disabled="$current <= 1">{{ __('Previous') }}</x-filament::button>
                    <span>{{ $current }} / {{ $pages }}</span>
                    <x-filament::button size="sm" color="gray" wire:click="$set('page', {{ min($pages, $current + 1) }})" :disabled="$current >= $pages">{{ __('Next') }}</x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
