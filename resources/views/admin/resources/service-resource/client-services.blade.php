{{-- This client's services (EditService::getSubheading): ‹ › to the previous and next, a list of all with their status. --}}
<span class="inline-flex flex-wrap items-center gap-2 text-sm font-normal">
    @if ($previous)
    <a href="{{ $previous }}" wire:navigate aria-label="{{ __('Previous service') }}" class="rounded-md px-2 py-0.5 ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-white/20 dark:hover:bg-white/5">‹</a>
    @endif
    <x-filament::dropdown placement="bottom-start" max-height="24rem">
        <x-slot name="trigger">
            <button type="button" class="rounded-md px-3 py-0.5 ring-1 ring-primary-600 hover:bg-gray-50 dark:hover:bg-white/5">
                {{ __("This client's services") }} ({{ $total }}) ▾
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($services as $service)
            <x-filament::dropdown.list.item :href="$service['url']" tag="a" :color="$service['ended'] ? 'danger' : ($service['current'] ? 'primary' : 'gray')">
                <span class="flex w-96 justify-between gap-3">
                    <span @class(['truncate', 'font-semibold' => $service['current']])>{{ $service['label'] }}</span>
                    <span class="shrink-0 text-xs">{{ $service['status'] }}</span>
                </span>
            </x-filament::dropdown.list.item>
            @endforeach
            @if ($tab)
            <x-filament::dropdown.list.item :href="$tab" tag="a" color="gray">{{ __("On the client's Products/Services tab") }} →</x-filament::dropdown.list.item>
            @endif
        </x-filament::dropdown.list>
    </x-filament::dropdown>
    @if ($next)
    <a href="{{ $next }}" wire:navigate aria-label="{{ __('Next service') }}" class="rounded-md px-2 py-0.5 ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-white/20 dark:hover:bg-white/5">›</a>
    @endif
</span>
