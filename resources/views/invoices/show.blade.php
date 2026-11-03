@php
    // A proforma has no number yet (nixpal): its id, as its heading says (code review 1 of #226).
    $invoiceTitle = $invoice->displayTitle();
@endphp
<div class="container mt-14">
    <x-navigation.breadcrumb :trail="[['name' => __('navigation.invoices'), 'url' => route('invoices')], ['name' => $invoiceTitle]]" />
    {{-- Back from a gateway: every 5 seconds; a charge held or a payment processed (it may take days): every 30. --}}
    <div @if ($checkPayment) wire:poll.5s="checkPaymentStatus" @elseif ($paymentStatus->waiting()) wire:poll.30s="checkPaymentStatus" @endif>
        {{-- The window holds only a gateway's own form now (Stripe's, say): the ways to pay are on the page (PR 3). --}}
        @if ($this->pay)
        @include('invoices.partials.payment-modal')
        @endif

        <div class="flex justify-end">
            <div class="max-w-[200px] w-full text-right">
                <span class="cursor-pointer text-base underline" wire:click="downloadPDF">
                    <span wire:loading wire:target="downloadPDF">
                        <x-ri-loader-5-fill class="size-6 animate-spin" />
                    </span>
                    <span wire:loading.remove wire:target="downloadPDF">
                        {{ __('invoices.download_pdf') }}
                    </span>
                </span>
            </div>
        </div>

        {{-- What happens now, before any click (docs/pay-flow-ux.md, PR 2): App\Services\Invoice\PaymentStatus. --}}
        @php
            $tone = [
                'ok' => ['box' => 'border-success bg-success/10', 'text' => 'text-success'],
                'info' => ['box' => 'border-primary bg-primary/10', 'text' => 'text-primary'],
                'warn' => ['box' => 'border-warning bg-warning/10', 'text' => 'text-warning'],
                'error' => ['box' => 'border-error bg-error/10', 'text' => 'text-error'],
                'plain' => ['box' => 'border-neutral bg-background-secondary', 'text' => 'text-base'],
            ][$paymentStatus->tone()];
            $statusBody = $paymentStatus->body();
        @endphp
        {{-- An email's or the cart's ?pay=1 lands here: what happens now, then the ways to pay; clear of the fixed bar. --}}
        <div id="status" class="mt-2 border rounded-lg p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 scroll-mt-20 {{ $tone['box'] }}"
            @if ($showPayModal && $paymentStatus->payable()) x-init="$el.scrollIntoView({ block: 'start' })" @endif>
            <div role="status" class="flex gap-3 items-start">
                @if ($paymentStatus->waiting())
                <x-ri-loader-5-fill aria-hidden="true" class="size-6 shrink-0 animate-spin {{ $tone['text'] }}" />
                @endif
                <div class="space-y-1">
                    <p class="text-lg font-semibold {{ $tone['text'] }}">{{ $paymentStatus->title() }}</p>
                    @if ($statusBody !== '')
                    <p class="text-sm text-base">{{ $statusBody }}</p>
                    @endif
                    {{-- Charged on its own: the customer may keep it from that and pay another way, a transfer say (Show::keepFromAutoCharge). --}}
                    @if ($paymentStatus->charged())
                    <button type="button" wire:click="keepFromAutoCharge" wire:confirm="{{ __('invoices.keep_from_charge.confirm') }}"
                        wire:loading.attr="disabled" wire:target="keepFromAutoCharge" class="text-sm text-primary underline text-left">
                        {{ __('invoices.keep_from_charge.button') }}
                    </button>
                    @endif
                    {{-- A ticket of the invoice's own account, where tickets are on (review of #593). --}}
                    @if ($paymentStatus->offersContact() && !config('settings.tickets_disabled') && (int) \App\Support\CurrentClient::id() === (int) $invoice->client_id && \App\Support\CurrentClient::allows('tickets'))
                    <a href="{{ route('tickets.create') }}" wire:navigate class="text-sm text-primary underline">{{ __('invoices.payment_status.contact') }}</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- The ways to pay beside the invoice (under the status on a phone), while it may be paid; an email's ?pay=1 brings
        the customer to them (docs/pay-flow-ux.md, PR 3). --}}
        {{-- Two columns only where the invoice keeps room beside the sidebar (xl); the panel follows it in the reading order
        of a phone, before it on the page (review of #597). --}}
        <div class="mt-2 grid gap-4 items-start {{ $paymentStatus->payable() ? 'xl:grid-cols-[minmax(0,1fr)_22rem]' : '' }}">
        @if ($paymentStatus->payable())
        <section id="pay" aria-labelledby="pay-title" class="xl:order-last min-w-0">
            @include('invoices.partials.pay-panel', [
                'payAmount' => $invoice->formattedRemaining,
                'payGroups' => $this->payGroups,
                'payLabel' => $this->payLabel,
                'credit' => $this->usableCredit,
                'automaticMethodId' => $this->automaticMethodId,
                'renewalsChoice' => $this->renewalsChoice,
                'payNote' => null,
                'showManage' => $this->savedPaymentMethods->isNotEmpty() || count($this->paymentMethods) > 0,
            ])
        </section>
        @endif
        <div class="bg-background-secondary border border-neutral p-6 sm:p-10 rounded-lg min-w-0">
            <h1 class="text-2xl font-bold sm:text-3xl">
                {{ $invoiceTitle }}
            </h1>
            <div class="sm:flex justify-between pr-4 pt-4">
                <div class="mt-4 sm:mt-0">
                    <p class="uppercase font-bold">{{ __('invoices.issued_to') }}</p>
                    <p>{{ $invoice->user_name }}</p>
                    @foreach($invoice->user_properties as $property)
                    <p>{{ $property }}</p>
                    @endforeach
                </div>
                <div class="mt-4 sm:mt-0 text-right">
                    <p class="uppercase font-bold">{{ __('invoices.bill_to') }}</p>
                    <p>{!! nl2br(e($invoice->bill_to)) !!}</p>
                </div>
            </div>
            <div class="sm:flex justify-between pr-4 pt-4 mt-6">
                <div class="">
                    <p class="text-base">{{ !$invoice->number && config('settings.invoice_proforma', false) ?
                    __('invoices.proforma_invoice_date') : __('invoices.invoice_date') }}: {{
                    $invoice->created_at->format('d M Y') }}</p>
                    @if($invoice->due_at)
                    <p class="text-base">{{ __('invoices.due_date') }}: {{ $invoice->due_at->format('d M Y') }}</p>
                    @endif
                    @if($invoice->number)
                    <p class="text-base">{{ __('invoices.invoice_no')}}: {{ $invoice->number }}</p>
                    @endif
                </div>
            </div>

            <div class="mt-12 border-b border-neutral overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-background border border-neutral rounded-lg">
                        <tr>
                            <th scope="col"
                                class="p-4 text-xs font-semibold tracking-wider text-left uppercase rounded-l-lg">
                                {{ __('invoices.item') }}
                            </th>
                            <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">
                                {{ __('invoices.price') }}
                            </th>
                            <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">
                                {{ __('invoices.quantity') }}
                            </th>
                            <th scope="col"
                                class="p-4 text-xs font-semibold tracking-wider text-left uppercase rounded-r-lg">
                                {{ __('invoices.total') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $item)
                        <tr>
                            <td class="p-4 font-normal whitespace-nowrap">
                                {{-- A line of a service deleted since keeps its text, without a link (DATA-2). --}}
                                @if(in_array($item->reference_type, ['App\Models\Service', 'App\Models\ServiceUpgrade']) && $item->reference)
                                <a href="{{ route('services.show', $item->reference_type == 'App\Models\Service' ? $item->reference_id : $item->reference->service_id) }}"
                                    class="hover:underline underline-offset-2">{{ $item->description }}
                                </a>
                                @else
                                {{ $item->description }}
                                @endif

                                {{-- A service's line: what tells it apart (ServiceIdentifier, owner 2026-11-03), unless its text says it. --}}
                                @php
                                    $identifier = $identifiers[$item->id] ?? '';
                                @endphp
                                @if($identifier !== '')
                                    <br><small class="text-base/60 whitespace-normal break-words">{{ $identifier }}</small>
                                @elseif($item->reference_type && $item->reference && $item->reference->label)
                                    <br><small style="color: #666; font-size: 0.9em;">{{ $item->reference->label }}</small>
                                @endif
                            </td>
                            <td class="p-4 font-normal whitespace-nowrap text-base">{{ $item->formattedPrice }}
                            </td>
                            <td class="p-4 font-normal whitespace-nowrap">{{ $item->quantity }}</td>
                            <td class="p-4 whitespace-nowrap font-semibold">{{ $item->formattedTotal }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{-- PR G: why lines carry no VAT (reverse charge, outside the EU). --}}
            @foreach ($invoice->taxNotes() as $taxNote)
            <p class="mt-6 text-sm text-gray-600 dark:text-base">{{ $taxNote }}</p>
            @endforeach
            <div class="space-y-3 sm:text-right sm:ml-auto sm:w-72 mt-10">
                {{-- Δ1: the tax of the lines, by rate. --}}
                @if ($invoice->taxTotal != 0)
                <div class="flex justify-between">
                    <div class="text-sm font-medium text-gray-500 uppercase dark:text-base">{{ __('invoices.subtotal') }}
                    </div>
                    <div class="text-base font-medium text-gray-900 dark:text-white">
                        {{ $invoice->formattedTotal->format($invoice->subtotal) }}
                    </div>
                </div>
                @foreach ($invoice->taxLines() as $taxLine)
                <div class="flex justify-between">
                    <div class="text-sm font-medium text-gray-500 uppercase dark:text-base">
                        {{ __('invoices.tax_line', ['name' => $invoice->taxName(), 'rate' => rtrim(rtrim($taxLine['rate'], '0'), '.')]) }}
                    </div>
                    <div class="text-base font-medium text-gray-900 dark:text-white">
                        {{ $invoice->formattedTotal->format($taxLine['amount']) }}
                    </div>
                </div>
                @endforeach
                @endif
                @php
                    $adjustmentsTotal = $invoice->adjustmentNotes->filter(fn ($note) => $note->status === \App\Enums\AdjustmentNoteStatus::Active)->sum('amount');
                    $adjustmentsPrice = new App\Classes\Price(['price' => $adjustmentsTotal, 'currency' => $invoice->currency]);
                @endphp
                @if($adjustmentsTotal != 0)
                <div class="flex justify-between">
                    <div class="text-sm font-medium text-gray-500 uppercase dark:text-base">{{ __('invoices.ledger_adjustments') }}
                    </div>
                    <div class="text-base font-medium text-gray-900 dark:text-white">
                        {{ $adjustmentsPrice->format($adjustmentsTotal) }}
                    </div>
                </div>
                @endif
                {{-- As the PDF and the email (Invoice::statement): the account credit used, then the total after it. --}}
                @php
                    $statement = $invoice->statement();
                @endphp
                @if ($statement['credit'] != 0)
                <div class="flex justify-between">
                    <div class="text-sm font-medium text-gray-500 uppercase dark:text-base">{{ __('invoices.credit_applied') }}</div>
                    <div class="text-base font-medium text-gray-900 dark:text-white">{{ $invoice->formattedTotal->format($statement['credit']) }}</div>
                </div>
                @endif
                <div class="flex justify-between">
                    <div class="text-base font-semibold text-gray-900 uppercase dark:text-white">{{ __('invoices.total') }}</div>
                    <div class="text-base font-bold text-gray-900 dark:text-white">
                        {{ $invoice->formattedTotal->format($statement['total']) }}
                    </div>
                </div>
            </div>

            {{-- The fiscal documents Ekdosi issued from this invoice (docs/ekdosi-bridge.md, E2): the legal document is that one. --}}
            @if ($invoice->ekdosiDocuments->isNotEmpty())
            <div class="mt-12">
                <h2 class="text-2xl font-bold">{{ __('invoices.fiscal_documents') }}</h2>
                @if ($invoice->ekdosiDocuments->contains('state', \App\Models\EkdosiDocument::ACTIVE))
                <p class="mt-1 text-sm text-base/70">{{ __('invoices.fiscal_documents_note') }}</p>
                @endif
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-background border border-neutral rounded-lg">
                            <tr>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase rounded-l-lg">{{ __('invoices.fiscal_document') }}</th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">{{ __('invoices.mark') }}</th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">{{ __('invoices.date') }}</th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">{{ __('invoices.status') }}</th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase rounded-r-lg"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->ekdosiDocuments->sortBy('id') as $document)
                            <tr>
                                <td class="p-4 font-normal whitespace-nowrap">{{ $document->code ?: '—' }}</td>
                                <td class="p-4 font-mono text-sm whitespace-nowrap">{{ $document->mark }}</td>
                                <td class="p-4 font-normal whitespace-nowrap">{{ $document->created_at?->format('d M Y') }}</td>
                                <td class="p-4 font-normal whitespace-nowrap">
                                    {{ $document->state === \App\Models\EkdosiDocument::CANCELLED ? __('invoices.document_cancelled') : __('invoices.document_active') }}
                                </td>
                                <td class="p-4 font-normal whitespace-nowrap">
                                    @if ($document->pdf_url && $document->state !== \App\Models\EkdosiDocument::CANCELLED)
                                    <a href="{{ $document->pdf_url }}" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">{{ __('invoices.open_pdf') }}</a>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            @if ($invoice->transactions->isNotEmpty())
            @php
                $hasRefunds = $invoice->transactions->contains(fn ($transaction) => $transaction->refunded_amount > 0);
            @endphp
            <div class="mt-12">
                <h2 class="text-2xl font-bold">{{ __('invoices.transactions') }}</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-background border border-neutral rounded-lg">
                            <tr>
                                <th scope="col"
                                    class="p-4 text-xs font-semibold tracking-wider text-left uppercase rounded-l-lg">
                                    {{ __('invoices.date') }}
                                </th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">
                                    {{ __('invoices.transaction_id') }}
                                </th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">
                                    {{ __('invoices.gateway') }}
                                </th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">
                                    {{ __('invoices.amount') }}
                                </th>
                                @if($hasRefunds)
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">
                                    {{ __('invoices.refunded_amount') }}
                                </th>
                                @endif
                                <th scope="col"
                                    class="p-4 text-xs font-semibold tracking-wider text-left uppercase rounded-r-lg">
                                    {{ __('invoices.status') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->transactions->sortByDesc('created_at') as $transaction)
                            <tr>
                                <td class="p-4 font-normal whitespace-nowrap">
                                    {{ $transaction->created_at->format('d M Y H:i') }}
                                </td>
                                <td class="p-4 font-normal whitespace-nowrap">{{ $transaction->transaction_id }}
                                </td>
                                <td class="p-4 font-normal whitespace-nowrap">
                                    @if($transaction->is_credit_transaction)
                                    {{ __('invoices.paid_with_credits') }}
                                    @else
                                    {{ $transaction->gateway?->name }}
                                    @endif
                                </td>
                                <td class="p-4 font-normal whitespace-nowrap">{{ $transaction->formattedAmount }}
                                </td>
                                @if($hasRefunds)
                                <td class="p-4 font-normal whitespace-nowrap">
                                    @if($transaction->refunded_amount > 0)
                                    {{ $transaction->formattedRefundedAmount }}
                                    @else
                                    -
                                    @endif
                                </td>
                                @endif
                                <td class="p-4 font-normal whitespace-nowrap">
                                    @if($transaction->status == \App\Enums\InvoiceTransactionStatus::Succeeded)
                                    <span class="text-green-600 font-semibold">{{
                                    __('invoices.transaction_statuses.succeeded') }}</span>
                                    @elseif($transaction->status == \App\Enums\InvoiceTransactionStatus::Processing)
                                    <span class="text-yellow-600 font-semibold flex items-center">
                                        {{ __('invoices.transaction_statuses.processing') }}
                                        <x-ri-loader-5-fill aria-hidden="true"
                                            class="size-6 me-2 fill-yellow-600 animate-spin" />
                                    </span>
                                    @elseif($transaction->status == \App\Enums\InvoiceTransactionStatus::Failed)
                                    <span class="text-red-600 font-semibold">{{ __('invoices.transaction_statuses.failed')
                                    }}</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            @php
                $clientAdjustmentNotes = $invoice->adjustmentNotes;
            @endphp
            @if (config('settings.notes_client_visible', false) && $clientAdjustmentNotes->isNotEmpty())
            <div class="mt-12">
                <h2 class="text-2xl font-bold">{{ __('invoices.ledger_adjustments') }}</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-background border border-neutral rounded-lg">
                            <tr>
                                <th scope="col"
                                    class="p-4 text-xs font-semibold tracking-wider text-left uppercase rounded-l-lg">
                                    {{ __('invoices.date') }}
                                </th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">
                                    {{ __('invoices.type') }}
                                </th>
                                <th scope="col" class="p-4 text-xs font-semibold tracking-wider text-left uppercase">
                                    {{ __('invoices.description') }}
                                </th>
                                <th scope="col"
                                    class="p-4 text-xs font-semibold tracking-wider text-left uppercase rounded-r-lg">
                                    {{ __('invoices.amount') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($clientAdjustmentNotes as $note)
                            <tr>
                                <td class="p-4 font-normal whitespace-nowrap">
                                    {{ $note->created_at->format('d M Y H:i') }}
                                </td>
                                <td class="p-4 font-normal whitespace-nowrap">
                                    @if($note->type === \App\Enums\AdjustmentNoteType::Credit->value)
                                    <span class="text-green-600 font-semibold">{{ __('invoices.credit_note') }}</span>
                                    @elseif($note->type === \App\Enums\AdjustmentNoteType::Debit->value)
                                    <span class="text-red-600 font-semibold">{{ __('invoices.debit_note') }}</span>
                                    @endif
                                </td>
                                <td class="p-4 font-normal whitespace-nowrap">
                                    {{ $note->description }}
                                </td>
                                <td class="p-4 font-normal whitespace-nowrap">
                                    <span class="{{ $note->type === \App\Enums\AdjustmentNoteType::Credit->value ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $note->formattedAmount }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

        </div>

        </div>
    </div>
</div>
