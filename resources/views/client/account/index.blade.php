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

            {{-- Asked only for a new email (its owner's password): a password reset goes to that address. --}}
            <div x-data="{ was: @js(auth()->user()->email) }" x-show="($wire.email || '').toLowerCase().trim() !== was.toLowerCase()" x-cloak>
                <x-form.input name="email_password" type="password" :label="__('account.input.password_to_change_email')"
                    wire:model="email_password" autocomplete="current-password" />
            </div>

        </div>

        @if($canEditDetails)
        <h3 class="text-lg font-semibold mt-8 mb-2">{{ __('account.account_details') }}</h3>
        <p class="text-sm text-base/70 mb-3">{{ __('account.account_details_description') }}</p>
        <x-contact-fields :locked="$identityLocked" />
        {{-- The account's mails and PDFs (docs/accounts.md). --}}
        <div class="grid md:grid-cols-2 gap-3 mt-3">
            <x-form.select name="language" :label="__('account.input.language')" wire:model="language">
                <option value="">{{ __('account.input.language_default', ['language' => \App\Support\Locales::options()[\App\Support\Locales::main()] ?? \App\Support\Locales::main()]) }}</option>
                @foreach (\App\Support\Locales::options() as $code => $name)
                <option value="{{ $code }}">{{ $name }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div class="grid md:grid-cols-2 gap-3 mt-3">
            <x-form.properties :custom_properties="$custom_properties" :properties="$properties" dirty />
        </div>
        @endif


        <x-button.primary wire:click="submit" class="w-full mt-4">
            {{ __('general.update') }}
        </x-button.primary>
    </div>
</div>