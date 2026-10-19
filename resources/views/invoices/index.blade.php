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

    @if ($massPay)
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg flex flex-wrap items-center justify-between gap-4">
        <p class="text-base text-sm">{{ __('invoices.mass_pay_hint') }}</p>
        <x-button.primary class="!w-fit" wire:click="payTogether" wire:loading.attr="disabled" :disabled="count($selected) < 2">
            <x-loading target="payTogether" />
            <span wire:loading.remove wire:target="payTogether">{{ __('invoices.mass_pay_button', ['count' => count($selected)]) }}</span>
        </x-button.primary>
    </div>
    @endif

    @forelse ($invoices as $invoice)
    @php
        $title = !$invoice->number && config('settings.invoice_proforma', false) ? __('invoices.proforma_invoice', ['id' => $invoice->id]) : __('invoices.invoice', ['id' => $invoice->number]);
        $lines = \App\Livewire\Invoices\Index::lines($invoice);
        $more = count($lines) - \App\Livewire\Invoices\Index::LINES;
        $overdue = $invoice->status === 'unpaid' && $invoice->due_at?->lt(today());
    @endphp
    <div class="flex items-start gap-3" wire:key="invoice-{{ $invoice->id }}">
        @if ($massPay)
        <div class="pt-5 w-5 shrink-0">
            @if ($invoice->status === 'unpaid')
            <input type="checkbox" value="{{ $invoice->id }}" wire:model.live="selected" aria-label="{{ __('invoices.mass_pay_select') }}"
                class="size-4 rounded border-neutral text-primary focus:ring-primary" />
            @endif
        </div>
        @endif
        <div class="grow min-w-0 bg-background-secondary border border-neutral p-4 rounded-lg flex flex-wrap md:flex-nowrap justify-between gap-4">
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

    {{ $invoices->links() }}
</div>
