<?php

namespace App\Livewire\Invoices;

use App\Livewire\Component;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;

/**
 * «Τα παραστατικά μου» (E2b, docs/ekdosi-bridge.md), as the WHMCS plugin's page: every fiscal document Ekdosi issued
 * from the account's invoices, newest first, each with its MARK, its PDF and the invoice it came from. The invoice's
 * own page lists them too (E2).
 */
class Documents extends Component
{
    use WithPagination;

    /** The account's documents: of its issued invoices (a draft has none). */
    public static function of(int $clientId): Builder
    {
        return EkdosiDocument::query()->whereHas('invoice', fn ($q) => $q->where('client_id', $clientId)
            ->where('status', '!=', Invoice::STATUS_DRAFT));
    }

    /** In the menu: with the bridge on and a document to show. */
    public static function shown(): bool
    {
        $client = CurrentClient::model();

        return (bool) config('settings.ekdosi_enabled') && $client !== null && self::of($client->id)->exists();
    }

    public function render()
    {
        return view('invoices.documents', [
            'documents' => self::of(CurrentClient::model()->id)->with(['invoice.currency', 'invoice.items', 'invoice.adjustmentNotes'])
                ->orderByDesc('id')->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('invoices.my_documents'),
            'sidebar' => true,
        ]);
    }
}
