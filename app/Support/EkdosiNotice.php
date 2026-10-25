<?php

namespace App\Support;

use App\Models\EkdosiDocument;
use App\Models\Invoice;

/**
 * What staff are told before they cancel or refund an invoice Ekdosi issued a document for (docs/ekdosi-bridge.md, E2):
 * the document stands until Ekdosi cancels it with a credit note — nothing here reaches it.
 */
final class EkdosiNotice
{
    public static function forInvoice(?Invoice $invoice): ?string
    {
        if ($invoice === null) {
            return null;
        }
        $codes = $invoice->ekdosiDocuments()->where('state', EkdosiDocument::ACTIVE)->orderBy('id')
            ->get(['code', 'mark'])->map(fn (EkdosiDocument $document) => $document->code ?: $document->mark);
        if ($codes->isEmpty()) {
            return null;
        }

        return __('It has a fiscal document from Ekdosi (:documents): cancelling or refunding here does not cancel it — issue a credit note in Ekdosi.',
            ['documents' => $codes->implode(', ')]);
    }
}
