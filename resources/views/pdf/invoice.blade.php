@php
    // As WHMCS's (owner 2026-10-20): the company's logo and details, a status stamp, the number and its dates, who it is
    // invoiced to, the lines, the totals with the credit applied, the transactions and the balance. In one language:
    // the customer's (App\Classes\PDF). Everything from Settings and the invoice's snapshot, nothing per company.
    $statement = $invoice->statement();
    $money = fn ($amount) => (new \App\Classes\Price(['price' => $amount, 'currency' => $invoice->currency], tax: 0))->format($amount);
    $withQuantity = $invoice->items->contains(fn ($item) => (float) $item->quantity != 1.0);
    $stamp = match ($invoice->status) {
        'paid' => '#5cb85c',
        'unpaid' => '#d9534f',
        default => '#999999',
    };
    // Capitals without accents, as Greek writes them («ΑΠΛΗΡΩΤΟ», review F6 of #264).
    $stampText = strtr(mb_strtoupper(\App\Models\Invoice::statusLabel($invoice->status)), ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'ΐ' => 'Ϊ', 'ΰ' => 'Ϋ']);
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->displayTitle() }}</title>
    <style>
        @page { margin: 40px 45px; }
        /* DejaVu Sans first: dompdf's own font, with Greek. */
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; font-size: 11px; color: #222; }
        table { width: 100%; border-collapse: collapse; }
        /* In its own line above the company's details, never over them (owner 2026-10-20). */
        .stamp-line { text-align: right; margin-bottom: 14px; }
        .stamp { display: inline-block; padding: 4px 12px; color: #fff; font-size: 12px; font-weight: bold;
            letter-spacing: 1px; }
        .header td { vertical-align: top; }
        .company { text-align: right; font-size: 11px; line-height: 1.45; }
        .title { background: #efefef; padding: 8px 10px; margin: 25px 0; }
        .title h1 { font-size: 18px; margin: 0 0 4px 0; }
        .title p { margin: 2px 0; }
        .to { margin-bottom: 25px; line-height: 1.45; }
        /* Fixed columns: a long word (a URL, a domain) wraps, never pushes the amounts off the page (review F1 of #264). */
        .grid { table-layout: fixed; }
        .grid td { word-wrap: break-word; }
        .grid th { background: #efefef; border: 1px solid #ccc; padding: 5px; font-weight: bold; text-align: center; }
        .grid td { border: 1px solid #ccc; padding: 5px; vertical-align: top; }
        .grid .num { text-align: right; white-space: nowrap; width: 110px; }
        .grid .sum td { background: #efefef; font-weight: bold; text-align: right; }
        .grid .empty { text-align: center; }
        .section { font-size: 14px; font-weight: bold; margin: 25px 0 8px 0; }
        .muted { color: #666; font-size: 10px; }
        .footer { text-align: center; margin-top: 30px; font-size: 10px; }
    </style>
</head>

<body>
    <div class="stamp-line"><span class="stamp" style="background: {{ $stamp }};">{{ $stampText }}</span></div>

    <table class="header">
        <tr>
            <td style="width: 55%;">
                @if (config('settings.logo'))
                    <img style="max-height: 60px; max-width: 260px;" src="{{ public_path('storage/' . config('settings.logo')) }}" alt="{{ config('app.name') }}">
                @endif
            </td>
            <td class="company">{!! nl2br(e($invoice->bill_to)) !!}</td>
        </tr>
    </table>

    <div class="title">
        <h1>{{ $invoice->displayTitle() }}</h1>
        <p>{{ $invoice->isProforma() ? __('invoices.proforma_invoice_date') : __('invoices.invoice_date') }}: {{ $invoice->created_at->translatedFormat('l, j F Y') }}</p>
        @if ($invoice->due_at)
            <p>{{ __('invoices.due_date') }}: {{ $invoice->due_at->translatedFormat('l, j F Y') }}</p>
        @endif
    </div>

    <div class="to">
        <strong>{{ __('invoices.invoiced_to') }}</strong><br>
        {{ $invoice->user_name }}<br>
        @foreach ($invoice->user_properties as $property)
            {!! nl2br(e($property)) !!}<br>
        @endforeach
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('invoices.description') }}</th>
                @if ($withQuantity)
                    <th class="num">{{ __('invoices.quantity') }}</th>
                    <th class="num">{{ __('invoices.unit_price') }}</th>
                @endif
                <th class="num">{{ __('invoices.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>
                        {!! nl2br(e($item->description)) !!}
                        @if ($item->reference_type && $item->reference && $item->reference->label)
                            <br><span class="muted">{{ $item->reference->label }}</span>
                        @endif
                    </td>
                    @if ($withQuantity)
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">{{ $item->formattedPrice }}</td>
                    @endif
                    <td class="num">{{ $item->formattedTotal }}</td>
                </tr>
            @endforeach
            @php $span = $withQuantity ? 3 : 1; @endphp
            <tr class="sum"><td colspan="{{ $span }}">{{ __('invoices.subtotal') }}</td><td class="num">{{ $money($invoice->subtotal) }}</td></tr>
            @foreach ($invoice->taxLines() as $taxLine)
                <tr class="sum">
                    <td colspan="{{ $span }}">{{ __('invoices.tax_line', ['name' => $invoice->taxName(), 'rate' => rtrim(rtrim($taxLine['rate'], '0'), '.')]) }}</td>
                    <td class="num">{{ $money($taxLine['amount']) }}</td>
                </tr>
            @endforeach
            @if ($statement['adjustments'] != 0)
                <tr class="sum"><td colspan="{{ $span }}">{{ __('invoices.ledger_adjustments') }}</td><td class="num">{{ $money($statement['adjustments']) }}</td></tr>
            @endif
            <tr class="sum"><td colspan="{{ $span }}">{{ __('invoices.credit_applied') }}</td><td class="num">{{ $money($statement['credit']) }}</td></tr>
            <tr class="sum"><td colspan="{{ $span }}">{{ __('invoices.total') }}</td><td class="num">{{ $money($statement['total']) }}</td></tr>
        </tbody>
    </table>

    {{-- PR G: why lines carry no VAT (reverse charge, outside the EU). --}}
    @foreach ($invoice->taxNotes() as $taxNote)
        <p style="margin-top: 12px;">{{ $taxNote }}</p>
    @endforeach

    @if (config('settings.notes_client_visible', false) && $invoice->adjustmentNotes->isNotEmpty())
        <div class="section">{{ __('invoices.ledger_adjustments') }}</div>
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('invoices.date') }}</th>
                    <th>{{ __('invoices.type') }}</th>
                    <th>{{ __('invoices.status') }}</th>
                    <th>{{ __('invoices.description') }}</th>
                    <th class="num">{{ __('invoices.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->adjustmentNotes as $note)
                    <tr>
                        <td>{{ $note->created_at->format('d/m/Y') }}</td>
                        <td>{{ $note->type === \App\Enums\AdjustmentNoteType::Credit->value ? __('invoices.credit_note') : __('invoices.debit_note') }}</td>
                        @php $noteStatus = $note->status instanceof \App\Enums\AdjustmentNoteStatus ? $note->status->value : (string) $note->status; @endphp
                        <td style="{{ $noteStatus === 'voided' ? 'color: #d9534f; text-decoration: line-through;' : '' }}">{{ __('invoices.note_statuses.' . $noteStatus) }}</td>
                        <td>{{ $note->description }}</td>
                        <td class="num">{{ $note->formattedAmount }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section">{{ __('invoices.transactions') }}</div>
    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('invoices.transaction_date') }}</th>
                <th>{{ __('invoices.gateway') }}</th>
                <th>{{ __('invoices.transaction_id') }}</th>
                <th class="num">{{ __('invoices.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($statement['payments'] as $transaction)
                <tr>
                    <td style="text-align: center;">{{ $transaction->created_at->translatedFormat('d/m/Y') }}</td>
                    <td style="text-align: center;">{{ $transaction->gateway?->name ?? '' }}</td>
                    <td style="text-align: center;">{{ $transaction->transaction_id }}</td>
                    <td class="num">
                        {{ $transaction->formattedAmount }}
                        @if ($transaction->refunded_amount > 0)
                            <br><span class="muted">{{ __('invoices.refunded_amount') }}: {{ $transaction->formattedRefundedAmount }}</span>
                        @endif
                        {{-- What of it went to the account's credit (an overpayment, a refund to credit): the rows add up. --}}
                        @if ($transaction->credited_amount > 0)
                            <br><span class="muted">{{ __('invoices.moved_to_credit') }}: {{ $money($transaction->credited_amount) }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                @if ($statement['processing'] === [])
                    <tr><td colspan="4" class="empty">{{ __('invoices.no_transactions') }}</td></tr>
                @endif
            @endforelse
            {{-- On its way (a SEPA debit, a transfer to confirm): listed, never in the balance (review P2 of #264). --}}
            @foreach ($statement['processing'] as $transaction)
                <tr>
                    <td style="text-align: center;">{{ $transaction->created_at->translatedFormat('d/m/Y') }}</td>
                    <td style="text-align: center;">{{ $transaction->gateway?->name ?? '' }}</td>
                    <td style="text-align: center;">{{ $transaction->transaction_id }}</td>
                    <td class="num">{{ $transaction->formattedAmount }}<br><span class="muted">{{ __('invoices.transaction_processing') }}</span></td>
                </tr>
            @endforeach
            <tr class="sum"><td colspan="3">{{ __('invoices.balance') }}</td><td class="num">{{ $money($statement['balance']) }}</td></tr>
        </tbody>
    </table>

    <div class="footer">{{ __('invoices.generated_on', ['date' => now()->translatedFormat('l, j F Y')]) }}</div>
</body>

</html>
