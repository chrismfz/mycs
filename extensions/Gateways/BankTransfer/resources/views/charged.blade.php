{{-- An invoice a saved method pays on its own: no transfer offered, or it would be paid twice. --}}
<div class="mt-2 space-y-4">
    <p>{{ __('invoices.bank_transfer_charged', ['date' => $due]) }}</p>
</div>
