{{-- Beside its details (owner 2026-10-24): what it comes to, what was paid and what is left, as its statement. --}}
@php
    /** @var \App\Models\Invoice $record */
    $money = fn ($amount) => (string) new \App\Classes\Price(['price' => $amount, 'currency' => $record->currency], tax: 0);
    $statement = $record->statement();
    // Its payments as the statement lists them, each net of refunds and of what became credit again.
    $paid = collect($statement['payments'])->sum(fn ($payment) => (int) round((float) $payment->amount * 100) - (int) round((float) $payment->refunded_amount * 100) - (int) round((float) $payment->credited_amount * 100)) / 100;
@endphp
<dl class="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1 rounded-lg bg-gray-50 p-3 text-sm tabular-nums ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
    <dt class="text-gray-500 dark:text-gray-400">{{ __('Total') }}</dt>
    <dd class="text-end">{{ $money($record->total) }}</dd>
    <dt class="text-gray-500 dark:text-gray-400">{{ __('Credit applied') }}</dt>
    <dd class="text-end">{{ $money($statement['credit']) }}</dd>
    <dt class="text-gray-500 dark:text-gray-400">{{ __('Paid') }}</dt>
    <dd class="text-end">{{ $money($paid) }}</dd>
    <dt class="border-t border-gray-200 pt-1 font-semibold dark:border-white/10">{{ __('Balance due') }}</dt>
    <dd @class(['border-t border-gray-200 pt-1 text-end font-semibold dark:border-white/10', 'text-danger-600 dark:text-danger-400' => $statement['balance'] > 0])>{{ $money($statement['balance']) }}</dd>
</dl>
