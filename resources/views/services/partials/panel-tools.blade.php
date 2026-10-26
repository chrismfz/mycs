{{-- The panel's tools (cPanel, as WHMCS's product details): shortcuts signed in, a new password, a new mailbox. --}}
@if(count($shortcuts) > 0)
<div class="bg-background-secondary rounded-lg p-4 mt-2">
    <h4 class="text-lg font-semibold">{{ __('services.cpanel.shortcuts_title') }}</h4>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 mt-3">
        @foreach($shortcuts as $shortcut)
        {{-- In a new tab (owner 2026-10-26): a form, CSRF (ServicePanelLoginController). --}}
        <form method="POST" action="{{ route('services.panel-login', $service) }}" target="_blank" rel="noopener noreferrer" class="contents">
            @csrf
            <input type="hidden" name="function" value="getLoginUrl">
            <input type="hidden" name="app" value="{{ $shortcut['app'] }}">
            <button type="submit" class="flex flex-col items-center gap-2 p-3 rounded-lg hover:bg-primary/5 text-center">
                <x-dynamic-component :component="'ri-' . $shortcut['icon']" class="size-7 text-primary" aria-hidden="true" />
                <span class="text-sm">{{ $shortcut['label'] }}</span>
            </button>
        </form>
        @endforeach
    </div>
</div>
@endif

@if($canChangePassword || $mailboxDomain)
<div class="grid md:grid-cols-2 gap-2 mt-2">
    @if($mailboxDomain)
    <div class="bg-background-secondary rounded-lg p-4">
        <h4 class="text-lg font-semibold">{{ __('services.cpanel.mailbox_title') }}</h4>
        <form wire:submit="createMailbox" class="flex flex-col gap-3 mt-3">
            <div class="flex items-end gap-2">
                <div class="grow">
                    <x-form.input wire:model="mailboxName" name="mailboxName" :label="__('services.cpanel.mailbox_name')" autocomplete="off" maxlength="64" required />
                </div>
                <span class="pb-2 text-base/50">&#64;{{ $mailboxDomain }}</span>
            </div>
            <x-form.input wire:model="mailboxPassword" name="mailboxPassword" type="password" :label="__('services.password')" autocomplete="new-password" maxlength="128" required />
            <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ __('services.cpanel.mailbox_create') }}</x-button.primary>
        </form>
    </div>
    @endif
    @if($canChangePassword)
    <div class="bg-background-secondary rounded-lg p-4">
        <h4 class="text-lg font-semibold">{{ __('services.cpanel.password_title') }}</h4>
        <form wire:submit="changeServicePassword" class="flex flex-col gap-3 mt-3">
            <x-form.input wire:model="newPassword" name="newPassword" type="password" :label="__('services.cpanel.new_password')" autocomplete="new-password" maxlength="128" required />
            <x-form.input wire:model="newPasswordConfirmation" name="newPasswordConfirmation" type="password" :label="__('services.cpanel.confirm_password')" autocomplete="new-password" maxlength="128" required />
            @if($passwordHasMysql)
            <x-form.checkbox wire:model="changeMysqlToo" name="changeMysqlToo">{{ __('services.cpanel.mysql_too') }}</x-form.checkbox>
            @endif
            <p class="text-xs text-base/50">{{ __('services.cpanel.password_hint') }}</p>
            <x-button.primary type="submit" class="!w-fit" wire:loading.attr="disabled">{{ __('services.cpanel.password_change') }}</x-button.primary>
        </form>
    </div>
    @endif
</div>
@endif
