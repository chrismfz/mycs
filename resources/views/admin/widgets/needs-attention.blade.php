<x-filament-widgets::widget>
    {{-- Counted again every minute: what waits on staff changes while the dashboard stays open. --}}
    <div wire:poll.60s>
    <x-filament::section :heading="__('Needs attention')">
        <x-slot name="afterHeader">
            <x-filament::badge :color="$rows === [] ? 'success' : $rows[0]['color']" size="sm">{{ count($rows) }}</x-filament::badge>
        </x-slot>
        @if ($rows === [])
        <p class="flex items-start gap-2 text-sm">
            <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5 shrink-0 text-success-600 dark:text-success-400" />
            <span>
                <span class="font-medium text-gray-900 dark:text-white">{{ __('Nothing needs attention.') }}</span><br>
                <span class="text-gray-500 dark:text-gray-400">{{ __('Checked just now: :what.', ['what' => mb_strtolower(implode(', ', $checked))]) }}</span>
            </span>
        </p>
        @else
        <ul class="-mx-2 divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($rows as $row)
            <li>
                <a href="{{ $row['url'] }}" class="flex items-center gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                    {{-- Inline: the theme's CSS has only the classes it was built with. --}}
                    <span aria-hidden="true" class="w-1 shrink-0 self-stretch rounded-full" style="background: var(--{{ $row['color'] }}-500)"></span>
                    <span class="min-w-0 flex-1">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $row['title'] }}</span>
                        @if ($row['detail'] !== '')
                        <span class="text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</span>
                        @endif
                    </span>
                    @if ($row['since'])
                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" title="{{ __('Oldest: :date', ['date' => $row['since']->toDateTimeString()]) }}">{{ $row['since']->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]) }}</span>
                    @endif
                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 shrink-0 text-gray-400" />
                </a>
            </li>
            @endforeach
        </ul>
        @endif
    </x-filament::section>
    </div>
</x-filament-widgets::widget>
