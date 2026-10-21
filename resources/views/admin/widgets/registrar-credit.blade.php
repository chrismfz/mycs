{{--
    «Registrar credit» (App\Admin\Widgets\RegistrarCredit), tiny (owner 2026-10-21): one line under the cards, each
    registrar's balance; when it was read on hover; a problem in red.
--}}
<x-filament-widgets::widget>
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl bg-white px-4 py-2 text-sm shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @if ($url)
        <a href="{{ $url }}" wire:navigate title="{{ __('Registrars') }}" class="text-xs font-semibold uppercase tracking-wide text-gray-500 hover:text-primary-600 dark:text-gray-400">{{ __('Registrar credit') }}</a>
        @else
        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Registrar credit') }}</span>
        @endif
        @foreach ($rows as $row)
        <span class="whitespace-nowrap" title="{{ $row['problem'] ?? $row['read'] }}{{ $row['reserved'] ? ' · ' . $row['reserved'] : '' }}">
            <span class="text-gray-600 dark:text-gray-300">{{ $row['label'] }}</span>
            <span @class(['font-semibold tabular-nums', 'text-danger-600 dark:text-danger-400' => $row['problem'], 'text-gray-950 dark:text-white' => !$row['problem']])>
                @if ($row['problem'])⚠ @endif{{ $row['amount'] }}
            </span>
            {{-- What is wrong, on screen too (a phone has no hover; review of #283). --}}
            @if ($row['problem'])
            <span class="text-xs text-danger-600 dark:text-danger-400 whitespace-normal">{{ $row['problem'] }}</span>
            @endif
        </span>
        @endforeach
    </div>
</x-filament-widgets::widget>
