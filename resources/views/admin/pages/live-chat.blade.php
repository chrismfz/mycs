<x-filament-panels::page>
    {{-- This browser's alerts for the staff chat (§7γ): the operating system's permission, a test, the sound. --}}
    <div class="flex flex-wrap items-center gap-2 text-sm"
        x-data="{
            permission: 'Notification' in window ? Notification.permission : 'unsupported',
            muted: (() => { try { return localStorage.getItem('mycs-chat-muted') === '1' } catch (e) { return false } })(),
            toggleSound() { this.muted = !this.muted; try { localStorage.setItem('mycs-chat-muted', this.muted ? '1' : '0') } catch (e) {} },
            async allow() { if ('Notification' in window) this.permission = await Notification.requestPermission() },
            async test() {
                try { await (window.mycsChatAudio ??= new AudioContext()).resume() } catch (e) {}
                window.mycsChatAlert?.(@js(__('Test notification of the staff chat')), window.location.href, true);
            },
        }">
        <x-filament::button size="sm" color="gray" x-show="permission === 'default'" x-on:click="allow()">{{ __('Turn on notifications') }}</x-filament::button>
        <span class="text-xs text-gray-500 dark:text-gray-400" x-show="permission === 'granted'">{{ __('Notifications are on in this browser.') }}</span>
        <span class="text-xs text-warning-600" x-show="permission === 'denied'">{{ __('Notifications are blocked in this browser\'s settings.') }}</span>
        <x-filament::button size="sm" color="gray" x-on:click="toggleSound()">
            <span x-show="!muted">{{ __('Sound: on') }}</span><span x-show="muted">{{ __('Sound: off') }}</span>
        </x-filament::button>
        <x-filament::button size="sm" color="gray" x-on:click="test()">{{ __('Test notification') }}</x-filament::button>
    </div>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <div class="flex flex-col gap-6 lg:col-span-1">
            {{-- Customers' chats in the member's departments (docs/livechat.md §7α, step 3α). --}}
            @if (\App\Support\LiveChats::isOperator(auth('staff')->user()))
            <x-filament::section :heading="__('Customers')">
                @livewire('admin.chat-queue', key('mycs-chat-queue'))
            </x-filament::section>
            @endif
            <x-filament::section :heading="__('Team')">
                @livewire('admin.staff-team', key('mycs-staff-team'))
            </x-filament::section>
        </div>
        <x-filament::section class="lg:col-span-3">
            @if ($chat)
            @livewire('admin.customer-chat', ['chatId' => $chat], key('mycs-customer-chat-' . $chat))
            @else
            @livewire('admin.staff-room', ['with' => $staffWith], key('mycs-staff-room'))
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
