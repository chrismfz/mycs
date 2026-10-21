{{-- Bank transfer: the accounts, the amount and the reference to write; staff record the payment when it arrives. --}}
<div class="mt-2 space-y-4">
    <p class="text-sm text-base/70">{{ __('invoices.bank_transfer_intro', ['total' => $total]) }}</p>
    <div class="bg-background border border-neutral rounded-md p-4 font-mono text-sm whitespace-pre-line">{{ trim($accounts) }}</div>
    <p class="font-semibold">{{ __('invoices.bank_transfer_reference', ['reference' => $reference]) }}</p>
    <p class="text-sm text-base/70">{{ __('invoices.bank_transfer_after') }}</p>
</div>
