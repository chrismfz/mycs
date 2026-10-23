<div wire:poll.10s.visible>
    <ul class="flex flex-col gap-1">
        @if ($canChat)
        <li>
            <button type="button" wire:click="$dispatch('staff-chat-open', { with: null })"
                class="flex w-full items-center gap-2 rounded-lg px-1 py-1 text-start text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                <x-filament::icon icon="heroicon-o-user-group" class="size-7 p-1 text-gray-400" />
                <span class="min-w-0 flex-1 truncate font-medium text-gray-800 dark:text-gray-100">{{ __('Staff room') }}</span>
                @if (($unread['room'] ?? 0) > 0)
                <x-filament::badge color="danger" size="sm">{{ $unread['room'] > \App\Support\StaffChat::UNREAD_CAP ? \App\Support\StaffChat::UNREAD_CAP . '+' : $unread['room'] }}</x-filament::badge>
                @endif
            </button>
        </li>
        @endif
        @foreach ($roster as $member)
        @php $mine = $member['staff']->id === $me?->id; $count = $unread[$member['staff']->id] ?? 0; @endphp
        <li>
            <button type="button" @disabled($mine || !$canChat)
                @if (!$mine && $canChat) wire:click="$dispatch('staff-chat-open', { with: {{ (int) $member['staff']->id }} })" title="{{ __('Message') }}" @endif
                class="flex w-full items-center gap-2 rounded-lg px-1 py-1 text-start text-sm enabled:hover:bg-gray-50 dark:enabled:hover:bg-white/5">
                <img src="{{ $member['staff']->avatar }}" alt="" class="size-7 rounded-full">
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium text-gray-800 dark:text-gray-100">{{ $member['staff']->name }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                        @if ($member['online'])
                        {{ $member['status']->label() }}
                        @else
                        {{ $member['last_seen'] ? $member['last_seen']->diffForHumans() : __('Never') }}
                        @endif
                    </span>
                </span>
                @if ($count > 0)
                <x-filament::badge color="danger" size="sm">{{ $count > \App\Support\StaffChat::UNREAD_CAP ? \App\Support\StaffChat::UNREAD_CAP . '+' : $count }}</x-filament::badge>
                @endif
                <span @class([
                    'inline-block size-2 shrink-0 rounded-full',
                    'bg-success-500' => $member['online'] && $member['status']->color() === 'success',
                    'bg-warning-500' => $member['online'] && $member['status']->color() === 'warning',
                    'bg-gray-400' => !$member['online'] || $member['status']->color() === 'gray',
                ])></span>
            </button>
        </li>
        @endforeach
    </ul>
</div>
