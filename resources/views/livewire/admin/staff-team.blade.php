<div wire:poll.30s.visible>
    <ul class="flex flex-col gap-1">
        @foreach ($roster as $member)
        <li class="flex items-center gap-2 text-sm">
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
            <span @class([
                'inline-block size-2 shrink-0 rounded-full',
                'bg-success-500' => $member['online'] && $member['status']->color() === 'success',
                'bg-warning-500' => $member['online'] && $member['status']->color() === 'warning',
                'bg-gray-400' => !$member['online'] || $member['status']->color() === 'gray',
            ])></span>
        </li>
        @endforeach
    </ul>
</div>
