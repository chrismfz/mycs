<div class="container mt-14 space-y-4">
    {{-- Its own way back: the menu shows it only once there is a document (review of #482). --}}
    <x-navigation.breadcrumb :trail="[['name' => __('invoices.my_documents')]]" />

    <p class="text-sm text-base/70">{{ __('invoices.my_documents_note') }}</p>

    @forelse ($documents as $document)
    @php $cancelled = $document->state === \App\Models\EkdosiDocument::CANCELLED; @endphp
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg flex flex-wrap md:flex-nowrap justify-between gap-4" wire:key="document-{{ $document->id }}">
        <div class="min-w-0 space-y-1">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="font-semibold">{{ $document->code ?: __('invoices.fiscal_document') }}</span>
                <span class="text-sm text-base/60">{{ $document->created_at?->format('d/m/Y') }}</span>
                <span class="px-2 py-0.5 rounded-md text-xs font-medium {{ $cancelled ? 'text-inactive bg-inactive/20' : 'text-success bg-success/20' }}">
                    {{ $cancelled ? __('invoices.document_cancelled') : __('invoices.document_active') }}
                </span>
            </div>
            <p class="text-sm text-base/70">{{ __('invoices.mark') }}: <span class="font-mono">{{ $document->mark }}</span></p>
            <p class="text-sm text-base/70">
                {{ __('invoices.from_invoice') }}
                <a href="{{ route('invoices.show', $document->invoice) }}" wire:navigate class="text-primary hover:underline">{{ $document->invoice->displayTitle() }}</a>
                · {{ $document->invoice->formattedTotal }}
            </p>
        </div>
        <div class="flex items-center gap-3 shrink-0 text-sm">
            @if ($document->pdf_url && !$cancelled)
            <a href="{{ $document->pdf_url }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1 text-base/70 hover:text-primary">
                <x-ri-file-pdf-2-line class="size-4" aria-hidden="true" /> {{ __('invoices.open_pdf') }}
            </a>
            @endif
        </div>
    </div>
    @empty
    <div class="bg-background-secondary border border-neutral p-4 rounded-lg">
        <p class="text-base text-sm">{{ __('invoices.no_documents') }}</p>
    </div>
    @endforelse

    {{ $documents->links() }}
</div>
