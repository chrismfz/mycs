<x-modal :title="!$invoice->number && config('settings.invoice_proforma', false) && !config('settings.invoice_number_is_id', false) ? __('invoices.payment_for_proforma_invoice', ['id' => $invoice->id]) : __('invoices.payment_for_invoice', ['number' => $invoice->number ?: $invoice->id])" open>
    <x-slot name="closeTrigger">
        <div class="flex gap-4">
            {{ __('invoices.amount_due', ['amount' => $invoice->formattedRemaining]) }}
            <button wire:confirm="Are you sure?" wire:click="exitPay" @click="open = false" class="text-primary-100">
                <x-ri-close-fill class="size-6" />
            </button>
        </div>
    </x-slot>

    @if ($this->pay)
        <div class="mt-8">{{ $this->pay }}</div>
    @else
        @include('invoices.partials.payment-options')
    @endif
</x-modal>