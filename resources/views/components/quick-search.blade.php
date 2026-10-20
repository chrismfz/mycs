<div x-data="{
        open: false,
        active: 0,
        items() { return [...this.$refs.list?.querySelectorAll('[data-result]') ?? []] },
        show() { this.open = true; this.active = 0; this.$nextTick(() => this.$refs.input.focus()) },
        hide() { this.open = false },
        move(step) {
            const items = this.items();
            if (!items.length) return;
            this.active = (this.active + step + items.length) % items.length;
            items[this.active].scrollIntoView({ block: 'nearest' });
        },
        go() { this.items()[this.active]?.click() },
    }"
    x-on:keydown.window.prevent.ctrl.k="show()" x-on:keydown.window.prevent.meta.k="show()"
    x-on:livewire:navigated.window="hide()">
    {{-- Quick search (P4d): Ctrl+K / ⌘K or this button --}}
    <button type="button" x-on:click="show()" aria-label="{{ __('search.open') }}" title="{{ __('search.open') }} (Ctrl+K)"
        class="w-10 h-10 flex items-center justify-center rounded-lg hover:bg-neutral transition">
        <x-ri-search-line class="size-4" />
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak x-on:keydown.escape.window="hide()" class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[10vh]">
            <div class="absolute inset-0 bg-black/50" x-on:click="hide()" aria-hidden="true"></div>
            <div role="dialog" aria-modal="true" aria-label="{{ __('search.title') }}"
                class="relative w-full max-w-xl bg-background-secondary border border-neutral rounded-lg shadow-xl overflow-hidden">
                <div class="flex items-center gap-2 px-4 border-b border-neutral">
                    <x-ri-search-line class="size-5 text-base/60 shrink-0" aria-hidden="true" />
                    <input x-ref="input" type="search" wire:model.live.debounce.250ms="q" autocomplete="off"
                        x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="go()"
                        x-on:input="active = 0"
                        placeholder="{{ __('search.placeholder') }}" aria-label="{{ __('search.title') }}"
                        class="w-full bg-transparent border-0 py-3 text-base focus:ring-0 focus:outline-none" />
                    <kbd class="hidden sm:block text-xs text-base/50 border border-neutral rounded px-1.5 py-0.5">Esc</kbd>
                </div>

                <div x-ref="list" class="max-h-[60vh] overflow-y-auto">
                    @if (mb_strlen(trim($q)) < 2)
                    <p class="px-4 py-6 text-sm text-base/60">{{ __('search.hint') }}</p>
                    @elseif (count($this->results) === 0)
                    <p class="px-4 py-6 text-sm text-base/60">{{ __('search.none', ['q' => trim($q)]) }}</p>
                    @else
                    <ul class="py-2" role="listbox">
                        @foreach ($this->results as $i => $result)
                        <li wire:key="result-{{ $result['kind'] }}-{{ $i }}">
                            <a href="{{ $result['url'] }}" wire:navigate data-result role="option"
                                x-bind:aria-selected="active === {{ $i }}" x-on:mouseenter="active = {{ $i }}"
                                x-bind:class="active === {{ $i }} ? 'bg-primary/15' : ''"
                                class="flex items-center gap-3 px-4 py-2">
                                <span class="size-8 shrink-0 rounded-md bg-background p-1.5 text-base/70" aria-hidden="true">
                                    @switch($result['kind'])
                                        @case('domain') <x-ri-global-line /> @break
                                        @case('service') <x-ri-server-line /> @break
                                        @case('invoice') <x-ri-bill-line /> @break
                                        @default <x-ri-customer-service-2-line />
                                    @endswitch
                                </span>
                                <span class="min-w-0">
                                    <span class="block font-medium truncate">{{ $result['title'] }}</span>
                                    <span class="block text-xs text-base/60 truncate">{{ __('search.kinds.' . $result['kind']) }} · {{ $result['detail'] }}</span>
                                </span>
                            </a>
                        </li>
                        @endforeach
                    </ul>
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>
