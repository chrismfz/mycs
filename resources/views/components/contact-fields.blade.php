@props(['prefix' => 'contact', 'emails' => true, 'identity' => true, 'locked' => false])
{{-- A contact's fields (docs/accounts.md, PR 3a): the account's details or another contact of it. --}}
<div class="grid md:grid-cols-2 gap-3">
    @if ($locked)
    {{-- A domain uses it (P1b): who it is stays as its registrars hold it --}}
    <p class="md:col-span-2 text-sm text-base/70">{{ __('domains.contact_identity_locked') }} {{ __('domains.contact_details_sent') }}</p>
    @endif
    <x-form.select name="{{ $prefix }}.type" :label="__('account.contact_type.label')" wire:model.live="{{ $prefix }}.type" :disabled="$locked">
        <option value="individual">{{ __('account.contact_type.individual') }}</option>
        <option value="business">{{ __('account.contact_type.business') }}</option>
    </x-form.select>
    <x-form.input name="{{ $prefix }}.company_name" type="text" :label="__('account.contact.company_name')" wire:model="{{ $prefix }}.company_name" :disabled="$locked" />
    @if ($identity)
    <x-form.input name="{{ $prefix }}.first_name" type="text" :label="__('general.input.first_name')" wire:model="{{ $prefix }}.first_name" :disabled="$locked" />
    <x-form.input name="{{ $prefix }}.last_name" type="text" :label="__('general.input.last_name')" wire:model="{{ $prefix }}.last_name" :disabled="$locked" />
    <x-form.input name="{{ $prefix }}.email" type="email" :label="__('general.input.email')" wire:model="{{ $prefix }}.email" />
    @endif
    <x-form.input name="{{ $prefix }}.phone" type="text" :label="__('account.contact.phone')" wire:model="{{ $prefix }}.phone" />
    <x-form.input name="{{ $prefix }}.tax_id" type="text" :label="__('account.contact.tax_id')" wire:model="{{ $prefix }}.tax_id" />
    <x-form.input name="{{ $prefix }}.vat_number" type="text" :label="__('account.contact.vat_number')" wire:model="{{ $prefix }}.vat_number" />
    <x-form.input name="{{ $prefix }}.tax_office" type="text" :label="__('account.contact.tax_office')" wire:model="{{ $prefix }}.tax_office" />
    <x-form.input name="{{ $prefix }}.activity" type="text" :label="__('account.contact.activity')" wire:model="{{ $prefix }}.activity" />
    <x-form.input name="{{ $prefix }}.address1" type="text" :label="__('account.contact.address1')" wire:model="{{ $prefix }}.address1" />
    <x-form.input name="{{ $prefix }}.address2" type="text" :label="__('account.contact.address2')" wire:model="{{ $prefix }}.address2" />
    <x-form.input name="{{ $prefix }}.city" type="text" :label="__('account.contact.city')" wire:model="{{ $prefix }}.city" />
    <x-form.input name="{{ $prefix }}.state" type="text" :label="__('account.contact.state')" wire:model="{{ $prefix }}.state" />
    <x-form.input name="{{ $prefix }}.postcode" type="text" :label="__('account.contact.postcode')" wire:model="{{ $prefix }}.postcode" />
    <x-form.select name="{{ $prefix }}.country" :label="__('account.contact.country')" wire:model="{{ $prefix }}.country">
        @foreach ((array) config('app.countries') as $code => $country)
        <option value="{{ $code }}">{{ $country }}</option>
        @endforeach
    </x-form.select>
    <x-form.select name="{{ $prefix }}.document_type" :label="__('account.document_type.label')" wire:model="{{ $prefix }}.document_type">
        <option value="">—</option>
        @foreach (\App\Models\Contact::DOCUMENT_TYPES as $type)
        <option value="{{ $type }}">{{ __('account.document_type.' . $type) }}</option>
        @endforeach
    </x-form.select>
    @if ($emails)
    <div class="md:col-span-2">
        <p class="mb-1 text-sm text-primary-100">{{ __('account.contact.receives_emails') }}</p>
        <div class="grid md:grid-cols-3 gap-2">
            @foreach (\App\Models\Contact::EMAIL_PREFERENCES as $kind)
            <x-form.checkbox name="{{ $prefix }}.email_preferences" id="{{ $prefix }}-email-{{ $kind }}" value="{{ $kind }}"
                wire:model="{{ $prefix }}.email_preferences" :label="__('account.email_preference.' . $kind)" />
            @endforeach
        </div>
    </div>
    @endif
</div>
