{{-- WHMCS's «Order Items» (owner 2026-10-19): each service and domain of the order, read only. --}}
@php($record = $getRecord())
@php($rows = \App\Admin\Resources\OrderResource\OrderSummary::items($record, \App\Admin\Resources\OrderResource\OrderSummary::invoice($record)))
<div class="overflow-x-auto">
    <table class="w-full text-sm text-start divide-y divide-gray-200 dark:divide-white/10">
        <thead>
            <tr class="text-start">
                <th class="px-3 py-2 text-start font-semibold">Item</th>
                <th class="px-3 py-2 text-start font-semibold">Description</th>
                <th class="px-3 py-2 text-start font-semibold">Billing Cycle</th>
                <th class="px-3 py-2 text-end font-semibold">Amount (net)</th>
                <th class="px-3 py-2 text-start font-semibold">Status</th>
                <th class="px-3 py-2 text-start font-semibold">Payment Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-3 py-2">
                        @if ($row['url'])
                            <a href="{{ $row['url'] }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['item'] }}</a>
                        @else
                            {{ $row['item'] }}
                        @endif
                    </td>
                    <td class="px-3 py-2 whitespace-pre-line">{{ $row['description'] }}</td>
                    <td class="px-3 py-2">{{ $row['cycle'] }}</td>
                    <td class="px-3 py-2 text-end">{{ $row['amount'] }}</td>
                    <td class="px-3 py-2">{{ $row['status'] }}</td>
                    <td class="px-3 py-2 @if ($row['payment'] === 'Paid') text-success-600 dark:text-success-400 @elseif ($row['payment'] === 'Unpaid') text-danger-600 dark:text-danger-400 @endif">{{ $row['payment'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-2 text-gray-500">—</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
