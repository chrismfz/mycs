<x-filament-widgets::widget>
    <x-filament::section :heading="__('Staff online')">
        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($roster as $member)
            <li class="flex flex-col items-center gap-1 text-center">
                <img src="{{ $member['staff']->avatar }}" alt="" class="size-12 rounded-full">
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $member['staff']->name }}</span>
                @if ($member['online'])
                <x-filament::badge :color="$member['status']->color()" size="sm">{{ $member['status']->label() }}</x-filament::badge>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Connected now') }}</span>
                @else
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $member['last_seen'] ? $member['last_seen']->diffForHumans() : __('Never') }}
                </span>
                @endif
            </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
