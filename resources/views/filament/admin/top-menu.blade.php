{{-- WHMCS-like top menu (App\Admin\TopMenu): desktop only; the sidebar remains for everything. --}}
@php($groups = \App\Admin\TopMenu::groups())
@if ($groups !== [])
    <ul class="fi-topbar-nav-groups mycs-top-menu">
        @foreach ($groups as $group)
            <x-filament::dropdown placement="bottom-start" teleport>
                <x-slot name="trigger">
                    <x-filament-panels::topbar.item :active="$group['active']">
                        {{ $group['label'] }}
                    </x-filament-panels::topbar.item>
                </x-slot>

                <x-filament::dropdown.list>
                    @foreach ($group['items'] as $item)
                        <x-filament::dropdown.list.item
                            tag="a"
                            :href="$item['url']"
                            :color="$item['active'] ? 'primary' : 'gray'"
                            :aria-current="$item['active'] ? 'page' : null"
                        >
                            {{ $item['label'] }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        @endforeach
    </ul>
@endif
