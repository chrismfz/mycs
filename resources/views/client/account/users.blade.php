<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2 space-y-8">
        <div>
            <h3 class="text-2xl font-bold">{{ __('account.users') }}</h3>
            <p class="text-sm text-base/70">{{ __('account.users_description') }}</p>
        </div>

        <div class="space-y-3">
            @foreach($grants as $grant)
            <div class="bg-background-secondary border border-neutral rounded-lg p-4" wire:key="grant-{{ $grant->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="font-semibold">
                            {{ $grant->user->name }}
                            @if($grant->user_id === auth()->id())
                            <span class="text-base/50">({{ __('account.you') }})</span>
                            @endif
                        </div>
                        <div class="text-sm text-base/70">{{ $grant->user->email }}</div>
                        <div class="text-xs text-base/50">
                            {{ __('account.last_login') }}: {{ $grant->last_login_at?->format('d/m/Y H:i') ?? __('account.never') }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($grant->owner)
                        <span class="text-sm font-semibold text-primary">{{ __('account.owner') }}</span>
                        @else
                        <x-button.secondary class="h-fit !w-fit" wire:click="edit({{ $grant->id }})">
                            {{ __('account.permissions') }}
                        </x-button.secondary>
                        <x-button.danger class="h-fit !w-fit" wire:click="revoke({{ $grant->id }})"
                            wire:confirm="{{ __('account.remove_access_confirm', ['name' => $grant->user->name]) }}">
                            {{ __('account.remove_access') }}
                        </x-button.danger>
                        @endif
                    </div>
                </div>
                @unless($grant->owner)
                <div class="text-xs text-base/60 mt-2">
                    {{ $grant->permissions === null ? __('account.all_permissions') : collect($grant->permissions)->map(fn ($name) => __('account.permission.' . $name))->join(', ') }}
                </div>
                @endunless

                @if($editing === $grant->id)
                <div class="mt-4 space-y-2">
                    <x-form.checkbox name="editAll" id="editAll-{{ $grant->id }}" wire:model.live="editAll" :label="__('account.all_permissions')" />
                    @unless($editAll)
                    <div class="grid md:grid-cols-2 gap-2">
                        @foreach($names as $name)
                        <x-form.checkbox name="editPermissions" id="edit-{{ $grant->id }}-{{ $name }}" value="{{ $name }}" wire:model="editPermissions" :label="__('account.permission.' . $name)" />
                        @endforeach
                    </div>
                    @endunless
                    <x-button.primary class="h-fit !w-fit" wire:click="savePermissions">{{ __('general.update') }}</x-button.primary>
                </div>
                @endif
            </div>
            @endforeach
        </div>

        @if($invites->isNotEmpty())
        <div class="space-y-3">
            <h4 class="text-lg font-semibold">{{ __('account.pending_invites') }}</h4>
            @foreach($invites as $invite)
            <div class="bg-background-secondary border border-neutral rounded-lg p-4 flex flex-wrap items-center justify-between gap-2" wire:key="invite-{{ $invite->id }}">
                <div>
                    <div class="font-semibold">{{ $invite->email }}</div>
                    <div class="text-xs text-base/50">{{ __('account.expires') }}: {{ $invite->expires_at->format('d/m/Y H:i') }}</div>
                </div>
                <x-button.danger class="h-fit !w-fit" wire:click="cancelInvite({{ $invite->id }})">{{ __('account.cancel_invite') }}</x-button.danger>
            </div>
            @endforeach
        </div>
        @endif

        <div class="space-y-3">
            <h4 class="text-lg font-semibold">{{ __('account.invite_user') }}</h4>
            <x-form.input name="email" type="email" :label="__('account.invite_email')" wire:model="email" required />
            <x-form.checkbox name="allPermissions" wire:model.live="allPermissions" :label="__('account.all_permissions')" />
            @unless($allPermissions)
            <div class="grid md:grid-cols-2 gap-2">
                @foreach($names as $name)
                <x-form.checkbox name="permissions" id="invite-{{ $name }}" value="{{ $name }}" wire:model="permissions" :label="__('account.permission.' . $name)" />
                @endforeach
            </div>
            @endunless
            <x-button.primary class="w-full" wire:click="invite">{{ __('account.invite_user') }}</x-button.primary>
        </div>
    </div>
</div>
