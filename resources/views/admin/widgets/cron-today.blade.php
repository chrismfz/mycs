<x-filament-widgets::widget>
    <x-filament::section :heading="__('Cron')">
        <x-slot name="afterHeader">
            <x-filament::link :href="$yesterdayUrl" size="sm">{{ __('Calendar') }}</x-filament::link>
        </x-slot>
        <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
            <li class="flex justify-between gap-3 py-1.5">
                <a href="{{ $yesterdayUrl }}" class="font-medium hover:underline">{{ __('Yesterday') }}</a>
                <span class="text-end">
                    @if ($yesterday === null)
                    <span style="color:var(--danger-600)">{{ __('the cron did not run') }}</span>
                    @else
                    {{ trans_choice(':count invoice|:count invoices', $yesterday['invoices']) }} · {{ __(':ok/:declined cards', ['ok' => $yesterday['charged'], 'declined' => $yesterday['declined']]) }}
                    · {{ __(':s susp. · :t term.', ['s' => $yesterday['suspended'], 't' => $yesterday['terminated']]) }}
                    @if ($yesterday['attention'])<span style="color:var(--danger-600)"> · {{ __('something to look at') }}</span>@endif
                    @endif
                </span>
            </li>
            @if ($now !== null)
            <li class="flex justify-between gap-3 py-1.5">
                <a href="{{ $nowUrl }}" class="font-medium hover:underline">{{ $netOn ? __('Waiting (safety net)') : __('Due now') }}</a>
                <span>{{ __(':s to suspend · :t to terminate', ['s' => $now['suspend'], 't' => $now['terminate']]) }}</span>
            </li>
            @endif
            @foreach ($next as $day)
            <li class="flex justify-between gap-3 py-1.5">
                <a href="{{ $day['url'] }}" class="font-medium hover:underline">{{ $day['label'] }}</a>
                <span style="{{ $day['suspend'] + $day['terminate'] > 0 ? 'color:var(--warning-600)' : '' }}">{{ __(':s to suspend · :t to terminate', ['s' => $day['suspend'], 't' => $day['terminate']]) }}</span>
            </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
