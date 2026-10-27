{{-- The service's related contact (docs/accounts.md 3b): information only, the invoice stays the account's. --}}
@if($canEditContact)
<div class="flex items-center text-base gap-2">
    <label for="contactId" class="mr-2">{{ __('services.related_contact') }}:</label>
    <select id="contactId" wire:model.live="contactId" class="rounded bg-background-secondary border border-neutral px-2 py-1 text-sm">
        <option value="">{{ __('services.related_contact_none') }}</option>
        @foreach($contactOptions as $id => $name)
        <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </select>
</div>
@elseif($canSeeContact && $service->contact)
<div class="flex items-center text-base">
    <span class="mr-2">{{ __('services.related_contact') }}:</span>
    <span class="text-base/50">{{ $service->contact->label() }}</span>
</div>
@endif
