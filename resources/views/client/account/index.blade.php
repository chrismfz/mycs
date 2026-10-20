<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2">

        <div class="grid md:grid-cols-2 gap-3">

            <x-form.input name="first_name" type="text" :label="__('general.input.first_name')"
                :placeholder="__('general.input.first_name_placeholder')" wire:model="first_name" required dirty />
            <x-form.input name="last_name" type="text" :label="__('general.input.last_name')"
                :placeholder="__('general.input.last_name_placeholder')" wire:model="last_name" required dirty />

            <x-form.input name="email" type="email" :label="__('general.input.email')"
                :placeholder="__('general.input.email_placeholder')" required wire:model="email" dirty />

        </div>

        @if($canEditDetails)
        <h3 class="text-lg font-semibold mt-8 mb-2">{{ __('account.account_details') }}</h3>
        <p class="text-sm text-base/70 mb-3">{{ __('account.account_details_description') }}</p>
        <x-contact-fields :locked="$identityLocked" />
        <div class="grid md:grid-cols-2 gap-3 mt-3">
            <x-form.properties :custom_properties="$custom_properties" :properties="$properties" dirty />
        </div>
        @endif


        <x-button.primary wire:click="submit" class="w-full mt-4">
            {{ __('general.update') }}
        </x-button.primary>
    </div>
</div>