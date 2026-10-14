<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2 space-y-4">
        <div>
            <h3 class="text-2xl font-bold">{{ __('account.accounts') }}</h3>
            <p class="text-sm text-base/70">{{ __('account.accounts_description') }}</p>
        </div>
        @foreach($accounts as $account)
        <div class="bg-background-secondary border border-neutral rounded-lg p-4 flex flex-wrap items-center justify-between gap-2" wire:key="account-{{ $account->id }}">
            <div>
                <div class="font-semibold">{{ $account->name }}</div>
                <div class="text-xs text-base/50">#{{ $account->id }} @if($account->pivot->owner) · {{ __('account.owner') }} @endif</div>
            </div>
            @if($account->id === $current)
            <span class="text-sm font-semibold text-primary">{{ __('account.current_account') }}</span>
            @elseif(!$impersonating)
            <x-button.primary class="h-fit !w-fit" wire:click="switch({{ $account->id }})">{{ __('account.switch') }}</x-button.primary>
            @endif
        </div>
        @endforeach
    </div>
</div>
