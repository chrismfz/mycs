{{-- Below the lines (owner 2026-10-24): the invoice's own sums as saved, the same the PDF and the client area print. --}}
@php
    /** @var \App\Models\Invoice $record */
    $money = fn ($amount) => (string) new \App\Classes\Price(['price' => $amount, 'currency' => $record->currency], tax: 0);
    $statement = $record->statement();
@endphp
<div class="flex justify-end">
    <dl class="grid w-full max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm tabular-nums">
        <dt class="text-gray-500 dark:text-gray-400">{{ __('Subtotal') }}</dt>
        <dd class="text-end">{{ $money($record->subtotal) }}</dd>
        @foreach ($record->taxLines() as $tax)
            <dt class="text-gray-500 dark:text-gray-400">{{ $record->taxName() }} {{ rtrim(rtrim($tax['rate'], '0'), '.') }}%</dt>
            <dd class="text-end">{{ $money($tax['amount']) }}</dd>
        @endforeach
        @if ($statement['adjustments'] != 0)
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Adjustment notes') }}</dt>
            <dd class="text-end">{{ $money($statement['adjustments']) }}</dd>
        @endif
        @if ($statement['credit'] != 0)
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Credit applied') }}</dt>
            <dd class="text-end">−{{ $money($statement['credit']) }}</dd>
        @endif
        <dt class="border-t border-gray-200 pt-1 font-semibold dark:border-white/10">{{ __('Total due') }}</dt>
        <dd class="border-t border-gray-200 pt-1 text-end font-semibold dark:border-white/10">{{ $money($statement['total']) }}</dd>
    </dl>
</div>
<p class="mt-2 text-end text-xs text-gray-500 dark:text-gray-400">{{ __('Prices before VAT; the totals are the saved ones, worked out again on Save.') }}</p>
