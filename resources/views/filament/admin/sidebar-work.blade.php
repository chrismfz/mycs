{{--
    The sidebar on a desktop (App\Admin\AdminSidebar, owner 2026-10-21): what needs staff today, quick create, the
    records last opened. The full menu is the top menu there; on a phone, the sidebar's own groups stay below these.
--}}
@php
    $queues = \App\Admin\AdminSidebar::queues();
    $creates = \App\Admin\AdminSidebar::creates();
    $recent = \App\Admin\AdminSidebar::recent();
@endphp
<div class="mycs-work-sidebar flex flex-col gap-5 pb-4" x-show="$store.sidebar.isOpen" x-cloak>
    <a href="{{ \App\Admin\Pages\Dashboard::getUrl() }}" wire:navigate
        @class(['flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium hover:bg-gray-100 dark:hover:bg-white/5',
            'bg-gray-100 text-primary-600 dark:bg-white/5 dark:text-primary-400' => request()->routeIs('filament.admin.pages.dashboard'),
            'text-gray-700 dark:text-gray-200' => !request()->routeIs('filament.admin.pages.dashboard')])>
        <x-filament::icon icon="heroicon-o-home" class="size-5" />
        {{ __('filament-panels::pages/dashboard.title') }}
    </a>
    @if ($queues !== [])
    <section>
        <h3 class="px-3 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('To handle') }}</h3>
        <ul class="flex flex-col">
            @foreach ($queues as $queue)
            <li>
                <a href="{{ $queue['url'] }}" wire:navigate
                    @class([
                        'flex items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-white/5',
                        'text-gray-400 dark:text-gray-500' => $queue['badge'] === null,
                        'font-medium text-gray-700 dark:text-gray-200' => $queue['badge'] !== null,
                    ])>
                    <span>{{ __($queue['label']) }}</span>
                    @if ($queue['badge'] !== null)
                    <x-filament::badge :color="$queue['color']" size="sm">{{ $queue['badge'] }}</x-filament::badge>
                    @endif
                </a>
            </li>
            @endforeach
        </ul>
    </section>
    @endif

    @if ($creates !== [])
    <section>
        <h3 class="px-3 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Create') }}</h3>
        <ul class="flex flex-col">
            @foreach ($creates as $create)
            <li>
                <a href="{{ $create['url'] }}" wire:navigate class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5">
                    <x-filament::icon icon="heroicon-m-plus" class="size-4 text-gray-400" />
                    {{ __($create['label']) }}
                </a>
            </li>
            @endforeach
        </ul>
    </section>
    @endif

    @if ($recent !== [])
    <section>
        <h3 class="px-3 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Recently viewed') }}</h3>
        <ul class="flex flex-col">
            @foreach ($recent as $item)
            <li>
                <a href="{{ $item['url'] }}" wire:navigate class="block rounded-lg px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5 truncate" title="{{ $item['label'] }}">
                    <span class="text-xs text-gray-400">{{ $item['kind'] }}</span> {{ $item['label'] }}
                </a>
            </li>
            @endforeach
        </ul>
    </section>
    @endif

    {{-- Who of the staff is in (docs/livechat.md §7β). --}}
    @livewire('admin.staff-online')
</div>
