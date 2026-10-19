<?php

namespace App\Http\Controllers;

use App\Classes\PDF;
use App\Models\Invoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** An invoice's PDF from its row in the list (P4b), as from its page (Invoices\Show::downloadPDF). */
class InvoicePdfController extends Controller
{
    public function __invoke(Invoice $invoice): StreamedResponse
    {
        return response()->streamDownload(function () use ($invoice) {
            echo PDF::generateInvoice($invoice)->output(); // the PDF only: stream() is a whole response, its headers written into the file
        }, $invoice->pdfFileName(), ['Content-Type' => 'application/pdf']);
    }
}
