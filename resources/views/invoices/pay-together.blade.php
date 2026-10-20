<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb :trail="[['name' => __('navigation.invoices'), 'url' => route('invoices')], ['name' => __('invoices.mass_pay_title')]]" />

    @if ($pay)
    <x-modal :title="__('invoices.mass_pay_title')" open>
        <x-slot name="closeTrigger">
            <div class="flex gap-4">
                {{ __('invoices.amount_due', ['amount' => number_format((float) $paymentGroup->total, 2) . ' ' . $paymentGroup->currency_code]) }}
                <button wire:confirm="Are you sure?" wire:click="exitPay" @click="open = false" class="text-primary-100">
                    <x-ri-close-fill class="size-6" />
                </button>
            </div>
        </x-slot>
        <div class="mt-8">{{ $pay }}</div>
    </x-modal>
    @endif

    <div class="bg-background-secondary border border-neutral p-6 rounded-lg">
        <h1 class="text-2xl font-bold">{{ __('invoices.mass_pay_title') }}</h1>
        <p class="text-base text-sm mt-2">{{ __('invoices.mass_pay_explain') }}</p>

        <div class="mt-6 divide-y divide-neutral">
            @foreach ($paymentGroup->invoices as $invoice)
            <div class="flex justify-between py-2">
                <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="underline">
                    {{ !$invoice->number && config('settings.invoice_proforma', false) ? __('invoices.proforma_invoice', ['id' => $invoice->id]) : __('invoices.invoice', ['id' => $invoice->number]) }}
                    @if ($invoice->due_at)
                    <span class="text-base/50 text-sm">({{ __('invoices.due_date') }}: {{ $invoice->due_at->format('d M Y') }})</span>
                    @endif
                </a>
                <span>{{ number_format((float) $invoice->pivot->amount, 2) }} {{ $paymentGroup->currency_code }}</span>
            </div>
            @endforeach
            <div class="flex justify-between py-2 font-semibold">
                <span>{{ __('invoices.total') }}</span>
                <span>{{ number_format((float) $paymentGroup->total, 2) }} {{ $paymentGroup->currency_code }}</span>
            </div>
        </div>
    </div>

    @if ($this->staleness)
    <div class="bg-background-secondary border border-neutral p-6 rounded-lg">
        <p class="text-warning">{{ $this->staleness }}</p>
        <a href="{{ route('invoices') }}" wire:navigate class="underline">{{ __('invoices.invoices') }}</a>
    </div>
    @else
    <div class="bg-background-secondary border border-neutral p-6 rounded-lg space-y-3">
        @foreach ($this->savedPaymentMethods as $method)
        <div wire:click="$set('selectedMethod', '{{ $method->ulid }}')" wire:loading.class="opacity-50 pointer-events-none" wire:target="selectedMethod,processPayment"
            class="flex items-center justify-between p-4 border rounded-lg cursor-pointer {{ $selectedMethod === $method->ulid ? 'border-primary ring-2 ring-primary' : 'border-neutral' }}">
            <div>
                <p class="font-semibold">{{ $method->name }}</p>
                <p class="text-sm text-base/50">{{ __('account.saved_payment_methods') }}</p>
            </div>
            @if ($selectedMethod === $method->ulid)
            <x-ri-check-line class="size-5 text-primary" />
            @endif
        </div>
        @endforeach

        @foreach ($this->gateways as $method)
        <div wire:click="$set('selectedMethod', 'gateway-{{ $method->id }}')" wire:loading.class="opacity-50 pointer-events-none" wire:target="selectedMethod,processPayment"
            class="flex items-center justify-between p-4 border rounded-lg cursor-pointer {{ $selectedMethod === 'gateway-' . $method->id ? 'border-primary ring-2 ring-primary' : 'border-neutral' }}">
            <div class="flex items-center gap-4">
                @if ($method->meta?->icon)
                <img src="{{ $method->meta->icon }}" alt="{{ $method->name }} Icon" class="h-9 w-9 object-contain" />
                @else
                <x-ri-secure-payment-line class="size-5 text-primary" />
                @endif
                <div>
                    <p class="font-medium">{{ $method->name }}</p>
                    <p class="text-sm text-base/50">{{ __('invoices.one_time_payment') }}</p>
                </div>
            </div>
            @if ($selectedMethod === 'gateway-' . $method->id)
            <x-ri-check-line class="size-5 text-primary" />
            @endif
        </div>
        @endforeach

        <x-button.primary class="w-full mt-4" wire:click="processPayment" wire:loading.attr="disabled" :disabled="!$selectedMethod">
            <x-loading target="processPayment" />
            <span wire:loading.remove wire:target="processPayment">
                {{ __('invoices.pay_now') }} ({{ number_format((float) $paymentGroup->total, 2) }} {{ $paymentGroup->currency_code }})
            </span>
        </x-button.primary>
    </div>
    @endif
</div>
