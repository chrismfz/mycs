<div class="flex flex-col gap-3">
    <ul class="divide-y divide-gray-200 dark:divide-white/10">
        @forelse ($sessions as $session)
        <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="{{ $session->ulid }}">
            <div class="min-w-0">
                <div class="truncate font-medium text-gray-950 dark:text-white" title="{{ $session->user_agent }}">
                    {{ \Illuminate\Support\Str::limit((string) $session->user_agent, 90) ?: __('Unknown device') }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $session->ip_address }}
                    · {{ __('Started') }} {{ $session->created_at?->toDateTimeString() }}
                    · {{ __('Last seen') }} {{ $session->last_seen_at?->diffForHumans() }}
                    @if ($session->remembered) · {{ __('Remember me') }} @endif
                </div>
            </div>
            @if ($session->ulid === $current)
                <span class="text-xs font-semibold text-success-600 dark:text-success-400">{{ __('This browser') }}</span>
            @else
                <x-filament::button size="xs" color="danger" outlined wire:click="end('{{ $session->ulid }}')"
                    wire:confirm="{{ __('End this session? That browser is logged out at once.') }}">
                    {{ __('End this session') }}
                </x-filament::button>
            @endif
        </li>
        @empty
        <li class="py-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No open sessions.') }}</li>
        @endforelse
    </ul>
    @if ($sessions->contains(fn ($session) => $session->ulid !== $current))
    <div>
        <x-filament::button size="sm" color="danger" wire:click="endOthers"
            wire:confirm="{{ $own ? __('End all your other sessions? Your other browsers are logged out, and «Remember me» on them ends.') : __('End all of this member\'s sessions? Every browser of theirs is logged out, and «Remember me» on them ends.') }}">
            {{ $own ? __('End all other sessions') : __('End all sessions') }}
        </x-filament::button>
    </div>
    @endif
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Ending a session also ends «Remember me» on every browser of this member: each one asks for the password and 2FA again.') }}</p>
</div>
