<?php

namespace App\Http\Controllers;

use App\Models\TicketMessageAttachment;
use MyCS\TicketMail\Attachments;

class TicketAttachmentController extends Controller
{
    public function download(TicketMessageAttachment $attachment)
    {
        // Always a download, never rendered: the type is not trusted to the browser (docs/tickets.md §3 ΣΤ).
        return response()->download($attachment->localPath, Attachments::safeName($attachment->filename), [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
