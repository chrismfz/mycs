{{-- The validation method (and approver) for an SSL order or reissue, once its CSR is checked. --}}
<x-form.select wire:model.live="sslMethod" name="sslMethod" :label="__('services.ssl.method')" required>
    <option value="dns">{{ __('services.ssl.methods.dns') }}</option>
    <option value="https">{{ __('services.ssl.methods.https') }}</option>
    <option value="email">{{ __('services.ssl.methods.email') }}</option>
</x-form.select>
@if($sslMethod === 'email')
<x-form.select wire:model="sslApprover" name="sslApprover" :label="__('services.ssl.approver')" required>
    <option value="">—</option>
    @foreach($sslApprovers as $approver)
    <option value="{{ $approver }}">{{ $approver }}</option>
    @endforeach
</x-form.select>
@endif
