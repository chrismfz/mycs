<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2 space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-2xl font-bold">{{ __('account.contacts') }}</h3>
                <p class="text-sm text-base/70">{{ __('account.contacts_description') }}</p>
            </div>
            <x-button.primary class="h-fit !w-fit" wire:click="create">{{ __('account.add_contact') }}</x-button.primary>
        </div>

        @if($showForm)
        <div class="bg-background-secondary border border-neutral rounded-lg p-4 space-y-3">
            <x-contact-fields :locked="$identityLocked" />
            <x-button.primary class="w-full" wire:click="save">{{ __('general.update') }}</x-button.primary>
        </div>
        @endif

        @forelse($contacts as $contact)
        <div class="bg-background-secondary border border-neutral rounded-lg p-4 flex flex-wrap items-center justify-between gap-2" wire:key="contact-{{ $contact->id }}">
            <div>
                <div class="font-semibold">{{ $contact->label() }}</div>
                @if ($contact->email && $contact->label() !== $contact->email)
                <div class="text-sm text-base/70">{{ $contact->email }}</div>
                @endif
                <div class="text-xs text-base/50">{{ implode(', ', $contact->addressLines()) }}</div>
                @if ($uses[$contact->id]->isNotEmpty())
                <div class="text-xs text-base/70 mt-1">
                    {{ __('domains.contact_used_by') }}:
                    {{ $uses[$contact->id]->map(fn ($use) => $use['fqdn'] . ' (' . __('domains.role.' . $use['role']) . ')')->implode(', ') }}
                </div>
                @endif
            </div>
            <div class="flex gap-2">
                <x-button.secondary class="h-fit !w-fit" wire:click="edit({{ $contact->id }})">{{ __('account.edit') }}</x-button.secondary>
                <x-button.danger class="h-fit !w-fit" wire:click="archive({{ $contact->id }})" wire:confirm="{{ __('account.archive_contact_confirm') }}">
                    {{ __('account.archive') }}
                </x-button.danger>
            </div>
        </div>
        @empty
        <p class="text-sm text-base/70">{{ __('account.no_contacts') }}</p>
        @endforelse
    </div>
</div>
