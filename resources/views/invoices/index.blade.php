<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb />

    {{-- Filters (P4b), kept for the session --}}
    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('invoices.filter.label') }}">
        @foreach (['' => 'all', 'unpaid' => 'unpaid', 'paid' => 'paid', 'cancelled' => 'cancelled'] as $value => $name)
        <button type="button" wire:click="filter('{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}"
            class="flex items-center gap-2 px-3 py-1.5 rounded-lg border text-sm {{ $status === $value ? 'border-primary bg-primary/20 text-primary font-semibold' : 'border-neutral bg-background-secondary hover:bg-background-secondary/80' }}">
            {{ __('invoices.filter.' . $name) }}
            <span class="text-base/50">{{ $counts[$value] }}</span>
        </button>
        @endforeach
    </div>

    {{-- Mass Pay (docs/pay-flow-ux.md, PR 5): how it works, and every invoice on this page that may join at once. --}}
    @if ($massPay)
    <div class="bg-primary/10 border border-primary/30 px-4 py-3 rounded-lg flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm">{{ __('invoices.mass_pay_hint') }}</p>
        @if (collect($rowInfo)->pluck('refusal')->containsStrict(null))
        <button type="button" wire:click="selectAll" class="text-sm font-semibold text-primary underline">{{ __('invoices.mass_pay_bar.select_all') }}</button>
        @endif
    </div>
    @endif

    @forelse ($invoices as $invoice)
    @php
        $title = $invoice->displayTitle();
        $lines = \App\Livewire\Invoices\Index::lines($invoice);
        $more = count($lines) - \App\Livewire\Invoices\Index::LINES;
        $overdue = $invoice->status === 'unpaid' && $invoice->due_at?->lt(today());
    @endphp
    @php
        $ticked = in_array((string) $invoice->id, array_map(fn ($id) => is_scalar($id) ? (string) $id : '', $selected), true);
        $row = $rowInfo[$invoice->id] ?? null;
        $refusal = $massPay ? $row['refusal'] ?? null : null;
    @endphp
    <div wire:key="invoice-{{ $invoice->id }}"
        class="bg-background-secondary border p-4 rounded-lg flex items-start gap-3 {{ $ticked ? 'border-primary ring-1 ring-primary' : 'border-neutral' }}">
        @if ($massPay)
        <div class="pt-1 w-5 shrink-0">
            @if ($invoice->status === 'unpaid')
            {{-- One that cannot join (MassPay::refusal) says why, instead of failing after the click; one ticked before it
            could not stays enabled, so it can be unticked (review of #606). --}}
            <input type="checkbox" id="pay-together-{{ $invoice->id }}" value="{{ $invoice->id }}" wire:model.live="selected"
                aria-label="{{ __('invoices.mass_pay_select') }}: {{ $title }}" @if ($refusal) aria-describedby="pay-together-why-{{ $invoice->id }}" @if (!$ticked) disabled @endif @endif
                class="form-checkbox size-5 rounded border-neutral text-primary focus:ring-primary disabled:opacity-40" />
            @endif
        </div>
        @endif
        <div class="grow min-w-0 flex flex-wrap md:flex-nowrap justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="font-semibold text-primary hover:underline">{{ $title }}</a>
                    <span class="text-sm text-base/60">{{ $invoice->created_at->format('d/m/Y') }}</span>
                    <span class="px-2 py-0.5 rounded-md text-xs font-medium
                        @if ($invoice->status === 'paid') text-success bg-success/20
                        @elseif (in_array($invoice->status, ['cancelled', 'refunded'], true)) text-inactive bg-inactive/20
                        @else text-warning bg-warning/20 @endif">{{ __('invoices.statuses.' . $invoice->status) }}</span>
                </div>
                {{-- What it is for (owner 2026-10-19): its first lines, the rest counted --}}
                @foreach (array_slice($lines, 0, \App\Livewire\Invoices\Index::LINES) as $line)
                <p class="text-sm text-base/80 truncate" title="{{ $line }}">{{ $line }}</p>
                @endforeach
                @if ($more > 0)
                <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="text-sm text-base/60 hover:underline">{{ trans_choice('invoices.more_lines', $more, ['count' => $more]) }}</a>
                @endif
                {{-- What happens to it now (PaymentStatus, PR 2): a charge to come, a payment on its way, the bank asking. --}}
                @if ($row && $row['status'])
                <p class="text-sm font-medium {{ ['ok' => 'text-success', 'info' => 'text-primary', 'warn' => 'text-warning', 'error' => 'text-error', 'plain' => 'text-base/70'][$row['tone']] }}">{{ $row['status'] }}</p>
                @endif
                {{-- Its refusal is a sentence of its own; not said twice when the status line says it (on its way, ended). --}}
                @if ($refusal)
                <p id="pay-together-why-{{ $invoice->id }}" class="text-sm text-base/60 {{ $row['showRefusal'] ? '' : 'sr-only' }}">{{ $refusal }}</p>
                @endif
            </div>
            <div class="flex flex-wrap md:flex-col items-center md:items-end gap-x-4 gap-y-1 shrink-0 text-sm">
                <span class="text-base font-semibold">{{ $invoice->formattedTotal }}</span>
                @if ($invoice->status === 'unpaid' && $invoice->due_at)
                <span class="{{ $overdue ? 'text-error font-medium' : 'text-base/60' }}">
                    {{ __($overdue ? 'invoices.overdue_since' : 'invoices.due_on', ['date' => $invoice->due_at->format('d/m/Y')]) }}
                </span>
                @endif
                <div class="flex items-center gap-3">
                    <a href="{{ route('invoices.pdf', $invoice) }}" class="flex items-center gap-1 text-base/70 hover:text-primary" title="{{ __('invoices.download_pdf') }}">
                        <x-ri-file-pdf-2-line class="size-4" aria-hidden="true" /> PDF
                    </a>
                    @if ($invoice->status === 'unpaid')
                    <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="bg-primary text-white hover:bg-primary/90 rounded-lg px-3 py-1.5 font-semibold">{{ __('invoices.pay_now') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @empty
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg">
        <p class="text-base text-sm">{{ __('invoices.no_invoices') }}</p>
    </div>
    @endforelse

    {{-- The bar follows the ticks: how many, their total, what still stops them (PR 5). MassPay::open checks again. --}}
    {{-- Always there while Mass Pay applies, so a screen reader hears the first tick too (review of #606). --}}
    @if ($massPay && $selection)
    <div aria-live="polite">
    @if ($selection['count'] > 0)
    <div role="region" aria-label="{{ __('invoices.mass_pay_title') }}"
        class="sticky bottom-4 z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-lg border border-primary bg-background shadow-lg">
        <div>
            <p class="font-semibold">
                {{ trans_choice('invoices.mass_pay_bar.selected', $selection['count'], ['count' => $selection['count']]) }}
                @if ($selection['total'])
                · {{ $selection['total'] }}
                @endif
            </p>
            <p class="text-sm text-base/70">{{ $selection['problem'] ?? __('invoices.mass_pay_bar.ready') }}</p>
        </div>
        <div class="flex gap-2">
            <x-button.secondary class="!w-fit" wire:click="clearSelection">{{ __('invoices.mass_pay_bar.clear') }}</x-button.secondary>
            <x-button.primary class="!w-fit" wire:click="payTogether" wire:loading.attr="disabled" :disabled="$selection['problem'] !== null">
                <x-loading target="payTogether" />
                <span wire:loading.remove wire:target="payTogether">{{ $selection['total'] && !$selection['problem'] ? __('invoices.mass_pay_bar.pay', ['amount' => $selection['total']]) : __('invoices.mass_pay_title') }}</span>
            </x-button.primary>
        </div>
    </div>
    @endif
    </div>
    @endif

    {{ $invoices->links() }}
</div>
