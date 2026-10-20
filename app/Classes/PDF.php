<?php

namespace App\Classes;

use App\Classes\Pdf\ContentPdfWrapper;
use App\Classes\Pdf\FilePdfWrapper;
use App\Events\Invoice\GeneratePdf;
use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf as DomPDF;
use Illuminate\Support\Facades\App;

class PDF
{
    /**
     * In the customer's language wherever it is made (portal, admin, mail; owner 2026-10-20): the same invoice reads the
     * same for everyone. Until a customer has a language of their own, the company's.
     */
    public static function generateInvoice(Invoice $invoice)
    {
        $previous = App::getLocale();
        App::setLocale(NotificationHelper::localeFor($invoice->client));
        try {
            return self::render($invoice);
        } finally {
            App::setLocale($previous);
        }
    }

    private static function render(Invoice $invoice)
    {
        // Dispatch event to see if any extension wants to handle PDF generation
        $event = new GeneratePdf($invoice);
        event($event);

        // If an extension provided a PDF in any format, handle it
        if ($event->hasPdf()) {
            return self::processPdfFromEvent($event);
        }

        // Fall back to default PDF generation
        return self::dompdf()->loadView('pdf.invoice', ['invoice' => $invoice]);
    }

    private static function processPdfFromEvent(GeneratePdf $event)
    {
        // If it's already a DomPDF instance, return it
        if ($event->pdf) {
            return $event->pdf;
        }

        // If it's a file path, create a wrapper
        if ($event->pdfPath) {
            return new FilePdfWrapper($event->pdfPath, $event->fileName);
        }

        // If it's content (base64, binary, etc.), create a wrapper
        if ($event->pdfContent) {
            return new ContentPdfWrapper($event->pdfContent, $event->fileName);
        }

        // This shouldn't happen, but fallback just in case
        return self::dompdf()->loadView('pdf.invoice', ['invoice' => $event->invoice]);
    }

    /** Only the letters used of DejaVu Sans (its Greek): a whole font in every PDF is ~900 KB. */
    private static function dompdf()
    {
        return DomPDF::setOption('isFontSubsettingEnabled', true);
    }
}
