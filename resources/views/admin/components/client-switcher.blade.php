{{--
    A client's other domains or services, under a page's title (ViewDomain / EditService::getSubheading): ‹ ▾ › on one
    line. Inline elements only: Filament prints the subheading inside a <p>, where a <div> (its own dropdown) closes the
    paragraph and drops the arrows to a line of their own (owner 2026-10-23).
--}}
<span x-data="{ open: false }" x-on:keydown.escape.window="if (open) { open = false; $refs.toggle.focus() }" class="relative inline-flex flex-wrap items-center gap-1.5 text-sm font-normal">
    {{-- Back to its client (owner 2026-10-23: «no easy way back to the client's page»). --}}
    @if ($client)
    <a href="{{ $client['url'] }}" wire:navigate class="me-2 inline-flex h-7 items-center gap-1 rounded-md px-2.5 font-medium text-primary-600 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-primary-400 dark:ring-white/20 dark:hover:bg-white/5">← {{ $client['label'] }}</a>
    @endif
    @if (count($items) > 1)
    @if ($previous)
    <a href="{{ $previous }}" wire:navigate aria-label="{{ $previousLabel }}" title="{{ $previousLabel }}" class="inline-flex h-7 w-7 items-center justify-center rounded-md ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-white/20 dark:hover:bg-white/5">‹</a>
    @endif
    <button type="button" x-ref="toggle" x-on:click="open = !open" x-bind:aria-expanded="open"
        class="inline-flex h-7 items-center gap-1 rounded-md px-3 ring-1 ring-primary-600 hover:bg-gray-50 dark:hover:bg-white/5">
        {{ $title }} ({{ $total }}) <span aria-hidden="true">▾</span>
    </button>
    @if ($next)
    <a href="{{ $next }}" wire:navigate aria-label="{{ $nextLabel }}" title="{{ $nextLabel }}" class="inline-flex h-7 w-7 items-center justify-center rounded-md ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-white/20 dark:hover:bg-white/5">›</a>
    @endif
    <span x-show="open" x-cloak x-on:click.outside="open = false" aria-label="{{ $title }}"
        class="absolute start-0 top-8 z-30 flex max-h-96 w-[28rem] max-w-[90vw] flex-col overflow-y-auto rounded-lg bg-white p-1 shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
        @foreach ($items as $item)
        <a href="{{ $item['url'] }}" wire:navigate @if ($item['current']) aria-current="page" @endif
            @class(['flex items-center justify-between gap-3 rounded-md px-2.5 py-1.5 hover:bg-gray-50 dark:hover:bg-white/5',
                'bg-primary-50 font-semibold dark:bg-primary-500/10' => $item['current'],
                'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => $item['ended'] && !$item['current']])>
            <span class="truncate">{{ $item['label'] }}</span>
            <span class="shrink-0 text-xs">{{ $item['status'] }}</span>
        </a>
        @endforeach
        @if ($tab)
        <a href="{{ $tab }}" wire:navigate class="mt-1 border-t border-gray-100 px-2.5 py-1.5 text-xs text-primary-600 hover:underline dark:border-white/5 dark:text-primary-400">{{ $tabLabel }} →</a>
        @endif
    </span>
    @endif
</span>
