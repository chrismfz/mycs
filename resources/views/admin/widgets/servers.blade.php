<x-filament-widgets::widget>
    <x-filament::section :heading="__('Servers')">
        <x-slot name="afterHeader">
            <x-filament::link :href="$url" size="sm">{{ __('View all') }}</x-filament::link>
        </x-slot>
        @if ($rows === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No servers yet') }}</p>
        @else
        <ul class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($rows as $row)
            <li class="flex items-center gap-3 py-1.5 text-sm">
                <span class="min-w-0 flex-1 truncate">
                    @if ($row['url'])
                    <a href="{{ $row['url'] }}" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $row['name'] }}</a>
                    @else
                    <span class="font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</span>
                    @endif
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['module'] }}</span>
                </span>
                @if ($row['max'] !== null)
                {{-- Inline: the theme's CSS has only the classes it was built with. --}}
                <span class="h-1.5 w-32 shrink-0 overflow-hidden rounded-full" style="background: var(--gray-200)" role="meter" aria-valuemin="0" aria-valuemax="{{ $row['max'] }}" aria-valuenow="{{ $row['used'] }}">
                    <span class="block h-full rounded-full" style="width: {{ $row['percent'] }}%; background: var(--{{ $row['color'] }}-500)"></span>
                </span>
                @endif
                <span class="w-16 shrink-0 text-end tabular-nums text-gray-700 dark:text-gray-200">
                    {{ $row['used'] }}@if ($row['max'] !== null)<span class="text-gray-400"> / {{ $row['max'] }}</span>@endif
                </span>
            </li>
            @endforeach
        </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
