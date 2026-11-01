@if($service->plan->type == 'recurring' && in_array($service->status, ['active', 'suspended'], true) && (string) $service->subscription_id === '')
{{-- Its own payment method (owner 2026-10-29, as WHMCS's): none, the account's; another gateway's is charged only by a saved method of that gateway (#568). --}}
@if($canSetPaymentMethod)
<div class="flex items-center text-base gap-2">
    <label for="paymentGatewayId" class="mr-2">{{ __('services.payment_method') }}:</label>
    <select id="paymentGatewayId" wire:model.live="paymentGatewayId" class="rounded bg-background-secondary border border-neutral px-2 py-1 text-sm">
        {{-- Each way says whether a saved method charges it or it is paid from the invoice (owner 2026-11-01). --}}
        <option value="">{{ \App\Services\Payment\ItemPaymentMethod::optionLabel($service, null) }}</option>
        @foreach($paymentMethodOptions as $gateway)
        <option value="{{ $gateway->id }}">{{ \App\Services\Payment\ItemPaymentMethod::optionLabel($service, $gateway) }}</option>
        @endforeach
        @if($service->paymentGateway && !$paymentMethodOptions->contains('id', $service->payment_gateway_id))
        <option value="{{ $service->payment_gateway_id }}">{{ \App\Services\Payment\ItemPaymentMethod::optionLabel($service, $service->paymentGateway) }}</option>
        @endif
    </select>
</div>
@endif
{{-- Charged automatically or not, and why (owner 2026-11-01: no misunderstanding); its own switch (#568). Who pays the
account's invoices, as on the domain's page. --}}
@if(\App\Support\CurrentClient::allows('invoices'))
<div class="flex items-center text-base gap-2">
    <span class="mr-2">{{ __('services.auto_pay') }}:</span>
    <span class="text-base/50">{{ \App\Services\Payment\ItemPaymentMethod::autoChargeStatus($service) }}</span>
    @if($canSetPaymentMethod && ($service->no_auto_charge || $service->client->billingAgreements()->exists()))
    <button type="button" class="text-sm underline" wire:click="toggleAutoCharge" wire:loading.attr="disabled"
        wire:confirm="{{ $service->no_auto_charge ? __('services.auto_charge_on_confirm') : __('services.auto_charge_off_confirm') }}">
        {{ $service->no_auto_charge ? __('services.auto_charge_turn_on') : __('services.auto_charge_turn_off') }}
    </button>
    @endif
    @if((int) $service->client_id === (int) \App\Support\CurrentClient::id() && \App\Support\CurrentClient::allows('invoices'))
    <a href="{{ route('account.payment-methods') }}" wire:navigate class="cursor-pointer" aria-label="{{ __('account.payment_methods') }}">
        <x-ri-edit-line class="inline size-4 ml-1 text-base/50" />
    </a>
    @endif
</div>
@endif
@endif
