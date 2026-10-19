@props(['trail' => null])
@php
    // The way back (P4a, owner 2026-10-19: «it was hard to go back a page»): always from Home, every step but the last a
    // link. A page with its own way (a domain, an invoice, a ticket) passes `trail` = [['name', 'url'?], …] after Home;
    // else it is found in the menus by the current address.
    $currentRoute = request()->livewireUrl();
    $home = ['name' => __('navigation.home'), 'url' => auth('web')->check() ? route('dashboard') : route('home')];

    if (is_array($trail)) {
        $breadcrumbs = array_values(array_filter($trail, fn ($step) => is_array($step) && ($step['name'] ?? '') !== ''));
    } else {
        $navigation = [
            \App\Classes\Navigation::getDashboardLinks(),
            \App\Classes\Navigation::getLinks(),
            \App\Classes\Navigation::getAccountDropdownLinks(),
        ];

        // A closure, not a named function: a page renders this more than once in one process (a Livewire update, tests).
        $findBreadcrumb = function ($items, $currentRoute) use (&$findBreadcrumb) {
            foreach ($items as $item) {
                if (isset($item['url']) && $item['url'] === $currentRoute) {
                    return [$item];
                }

                if (!empty($item['children'])) {
                    $childTrail = $findBreadcrumb($item['children'], $currentRoute);
                    if (!empty($childTrail)) {
                        return array_merge([$item], $childTrail);
                    }
                }
            }

            return [];
        };

        $breadcrumbs = [];
        foreach ($navigation as $group) {
            $breadcrumbs = $findBreadcrumb($group, $currentRoute);
            if (!empty($breadcrumbs)) {
                break;
            }
        }
    }
    // Home itself is not a step after Home.
    $breadcrumbs = array_values(array_filter($breadcrumbs, fn ($step) => ($step['url'] ?? null) !== $home['url']));
    $onHome = $breadcrumbs === [] && $currentRoute === $home['url'];
@endphp

<nav aria-label="{{ __('navigation.breadcrumb') }}" class="flex flex-row flex-wrap items-center gap-y-1 pb-4 text-sm">
    @if ($onHome)
        <span class="text-2xl font-bold">{{ $home['name'] }}</span>
    @else
        <a href="{{ $home['url'] }}" wire:navigate class="text-base/70 hover:text-primary">{{ $home['name'] }}</a>
        @foreach ($breadcrumbs as $index => $breadcrumb)
            <x-ri-arrow-right-s-line class="size-4 text-base/50 mx-1" aria-hidden="true" />
            @if ($index === count($breadcrumbs) - 1)
                <span class="text-base font-semibold" aria-current="page">{{ $breadcrumb['name'] }}</span>
            @elseif (!empty($breadcrumb['url']))
                <a href="{{ $breadcrumb['url'] }}" wire:navigate class="text-base/70 hover:text-primary">{{ $breadcrumb['name'] }}</a>
            @else
                <span class="text-base/70">{{ $breadcrumb['name'] }}</span>
            @endif
        @endforeach
    @endif
</nav>
