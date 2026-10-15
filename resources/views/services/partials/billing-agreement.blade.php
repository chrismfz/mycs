@if($service->plan->type == 'recurring' && $service->status == 'active' && (string) $service->subscription_id === '' && $service->client->billingAgreements->count() > 0)
@php $agreement = $service->client->autoChargeAgreement(); @endphp
<div class="flex items-center text-base">
    <!-- Auto paying using: the account's method (decision 6) -->
    <span class="mr-2">{{ __(key: 'services.auto_pay') }}:</span>
    <span class="text-base/50">{{ $agreement ? $agreement->name : __('services.auto_pay_not_configured') }}
        @if(\App\Support\CurrentClient::allows('invoices'))
        <a href="{{ route('account.payment-methods') }}" wire:navigate class="cursor-pointer">
            <x-ri-edit-line class="inline size-4 ml-1 text-base/50" />
        </a>
        @endif
    </span>
</div>
@endif
