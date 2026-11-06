<x-filament-widgets::widget>
    {{-- A plain widget has no polling of its own: every 30 seconds, as often as the heartbeat, and only while the tab is seen. --}}
    <div wire:poll.30s.visible>
    <x-filament::section :heading="__('Staff and sessions')">
        <x-slot name="afterHeader">
            <span class="text-sm text-gray-500 dark:text-gray-400">
                {{ collect([
                    $staff !== null ? trans_choice(':count online', $online, ['count' => $online]) : null,
                    $sessions !== null ? trans_choice(':count customer session|:count customer sessions', $sessionCount, ['count' => $sessionCount]) : null,
                ])->filter()->implode(' · ') }}
            </span>
        </x-slot>

        @php($scroll = 'max-height: ' . (\App\Admin\Widgets\StaffAndSessions::VISIBLE_ROWS * 2.125) . 'rem; overflow-y: auto')

        @if ($staff !== null)
        {{-- Who is in, never from where (docs/livechat.md §7β): no email, IP or device here. --}}
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Staff') }}</h3>
        {{-- About five rows, then it scrolls, as Servers does (owner 2026-11-04: «small like Servers»). --}}
        <ul class="-me-2 mb-3 divide-y divide-gray-100 pe-2 dark:divide-white/5" style="{{ $scroll }}">
            @foreach ($staff as $member)
            <li class="flex items-center gap-3 py-1.5 text-sm">
                <span aria-hidden="true" class="size-2 shrink-0 rounded-full" style="background: var(--{{ $member['online'] ? $member['status']->color() : 'gray' }}-{{ $member['online'] ? '500' : '300' }})"></span>
                <span class="min-w-0 flex-1 truncate font-medium text-gray-900 dark:text-white">{{ $member['staff']->name }}</span>
                <span class="w-24 shrink-0 truncate text-gray-700 dark:text-gray-200">{{ $member['online'] ? $member['status']->label() : __('Offline') }}</span>
                <span class="w-32 shrink-0 truncate text-end text-gray-500 dark:text-gray-400">
                    @if ($member['online'])
                    {{ __('Connected now') }}
                    @else
                    {{ $member['last_seen'] ? $member['last_seen']->diffForHumans() : __('Never') }}
                    @endif
                </span>
            </li>
            @endforeach
        </ul>
        @endif

        @if ($sessions !== null)
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ trans_choice('Customer sessions, last :count minute|Customer sessions, last :count minutes', \App\Admin\Widgets\StaffAndSessions::SESSION_MINUTES, ['count' => \App\Admin\Widgets\StaffAndSessions::SESSION_MINUTES]) }}</h3>
        @if ($sessions === [])
        <p class="py-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('No customer is signed in.') }}</p>
        @else
        <ul class="-me-2 divide-y divide-gray-100 pe-2 dark:divide-white/5" style="{{ $scroll }}">
            @foreach ($sessions as $session)
            <li class="flex items-center gap-3 py-1.5 text-sm">
                {{-- The email in the name's tooltip: the column is half the page now. --}}
                <a href="{{ $session['url'] }}" wire:navigate title="{{ $session['email'] }}" class="min-w-0 flex-1 truncate font-medium text-gray-900 hover:underline dark:text-white">{{ $session['name'] }}
                    <span class="font-normal text-gray-500 dark:text-gray-400">{{ $session['email'] }}</span></a>
                <span class="w-24 shrink-0 truncate text-gray-700 dark:text-gray-200">{{ $session['last_activity']->diffForHumans(short: true) }}</span>
                {{-- The address through a data attribute, never inside the script: a click copies it. --}}
                <button type="button" class="w-32 shrink-0 cursor-pointer select-none truncate text-end font-mono text-xs text-gray-500 hover:text-primary-500 dark:text-gray-400"
                    data-ip="{{ $session['ip'] }}" title="{{ $session['ip'] }} · {{ __('Copy') }}"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText($el.dataset.ip); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-show="!copied">{{ $session['ip'] }}</span><span x-show="copied" x-cloak>{{ __('Copied!') }}</span>
                </button>
            </li>
            @endforeach
        </ul>
        @endif
        @endif
    </x-filament::section>
    </div>
</x-filament-widgets::widget>
