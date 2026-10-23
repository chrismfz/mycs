<div wire:poll.30s.visible class="mycs-staff-online">
    @if ($canView && $me)
    <section>
        <h3 class="px-3 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Staff online') }}</h3>
        <ul class="flex flex-col">
            @foreach ($online as $member)
            <li class="flex items-center gap-2 px-3 py-1 text-sm text-gray-700 dark:text-gray-200" title="{{ $member['status']->label() }}">
                <span @class([
                    'inline-block size-2 shrink-0 rounded-full',
                    'bg-success-500' => $member['status']->color() === 'success',
                    'bg-warning-500' => $member['status']->color() === 'warning',
                    'bg-gray-400' => $member['status']->color() === 'gray',
                ])></span>
                <span class="truncate">{{ $member['staff']->name }}</span>
            </li>
            @endforeach
        </ul>
        <label class="mt-2 flex items-center gap-2 px-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('My status') }}
            <select wire:change="setStatus($event.target.value)" class="rounded-md border-gray-300 bg-white py-0.5 text-xs dark:border-white/10 dark:bg-gray-900">
                @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected($myStatus === $status)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
    </section>
    @endif
</div>
