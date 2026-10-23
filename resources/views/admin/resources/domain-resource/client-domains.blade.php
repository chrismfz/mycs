{{-- This client's domains (ViewDomain::getSubheading): ‹ › to the previous and next, a list of all with their status. --}}
<span class="inline-flex flex-wrap items-center gap-2 text-sm font-normal">
    @if ($previous)
    <a href="{{ $previous }}" wire:navigate aria-label="{{ __('Previous domain') }}" class="rounded-md px-2 py-0.5 ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-white/20 dark:hover:bg-white/5">‹</a>
    @endif
    <x-filament::dropdown placement="bottom-start" max-height="24rem">
        <x-slot name="trigger">
            <button type="button" class="rounded-md px-3 py-0.5 ring-1 ring-primary-600 hover:bg-gray-50 dark:hover:bg-white/5">
                {{ __("This client's domains") }} ({{ count($domains) }}) ▾
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($domains as $domain)
            <x-filament::dropdown.list.item :href="$domain['url']" tag="a" :color="$domain['ended'] ? 'danger' : ($domain['current'] ? 'primary' : 'gray')">
                <span class="flex w-72 justify-between gap-3">
                    <span @class(['font-semibold' => $domain['current']])>{{ $domain['fqdn'] }}</span>
                    <span class="text-xs">{{ $domain['status'] }}</span>
                </span>
            </x-filament::dropdown.list.item>
            @endforeach
            <x-filament::dropdown.list.item :href="$tab" tag="a" color="gray">{{ __("On the client's Domains tab") }} →</x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
    @if ($next)
    <a href="{{ $next }}" wire:navigate aria-label="{{ __('Next domain') }}" class="rounded-md px-2 py-0.5 ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-white/20 dark:hover:bg-white/5">›</a>
    @endif
</span>
