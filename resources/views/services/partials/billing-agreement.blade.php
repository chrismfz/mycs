@if($service->plan->type == 'recurring' && in_array($service->status, ['active', 'suspended'], true) && (string) $service->subscription_id === '')
{{-- Its own payment method (owner 2026-10-29, as WHMCS's): none, the account's; another gateway's is never charged by the saved method. --}}
@if($canSetPaymentMethod)
<div class="flex items-center text-base gap-2">
    <label for="paymentGatewayId" class="mr-2">{{ __('services.payment_method') }}:</label>
    <select id="paymentGatewayId" wire:model.live="paymentGatewayId" class="rounded bg-background-secondary border border-neutral px-2 py-1 text-sm">
        <option value="">{{ __('services.payment_method_account') }}</option>
        @foreach($paymentMethodOptions as $gateway)
        <option value="{{ $gateway->id }}">{{ $gateway->name }}</option>
        @endforeach
        @if($service->paymentGateway && !$paymentMethodOptions->contains('id', $service->payment_gateway_id))
        <option value="{{ $service->payment_gateway_id }}">{{ $service->paymentGateway->name }}</option>
        @endif
    </select>
</div>
@endif
@if($service->client->billingAgreements->count() > 0)
@php $agreement = \App\Services\Payment\ItemPaymentMethod::chargedBy($service); @endphp
<div class="flex items-center text-base">
    <!-- Auto paying using: the account's method (decision 6), unless its own payment method is another gateway's -->
    <span class="mr-2">{{ __(key: 'services.auto_pay') }}:</span>
    <span class="text-base/50">{{ $agreement ? $agreement->name : ($service->paymentGateway ? __('services.auto_pay_paid_with', ['method' => $service->paymentGateway->name]) : __('services.auto_pay_not_configured')) }}
        @if((int) $service->client_id === (int) \App\Support\CurrentClient::id() && \App\Support\CurrentClient::allows('invoices'))
        <a href="{{ route('account.payment-methods') }}" wire:navigate class="cursor-pointer">
            <x-ri-edit-line class="inline size-4 ml-1 text-base/50" />
        </a>
        @endif
    </span>
</div>
@endif
@endif
