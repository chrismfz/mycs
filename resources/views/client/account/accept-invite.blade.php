<div class="container mt-14 max-w-xl">
    <div class="bg-background-secondary border border-neutral rounded-lg p-6 space-y-4">
        <h3 class="text-2xl font-bold">{{ __('account.invite_title') }}</h3>
        @if($invite)
        <p>{{ __('account.invite_to', ['name' => $invite->client->name]) }}</p>
        @if($loggedIn)
        <x-button.primary class="w-full" wire:click="accept">{{ __('account.invite_accept') }}</x-button.primary>
        @else
        <div class="flex flex-col gap-2">
            <x-button.primary class="w-full" wire:click="login">{{ __('account.invite_login') }}</x-button.primary>
            <x-button.secondary class="w-full" wire:click="register">{{ __('account.invite_register') }}</x-button.secondary>
        </div>
        @endif
        @else
        <p class="text-red-500">{{ __('account.invite_invalid') }}</p>
        @endif
    </div>
</div>
