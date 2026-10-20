<?php

namespace App\Http\Controllers;

use App\Models\TicketMessageAttachment;
use App\Support\Tickets\GuestLinks;
use MyCS\TicketMail\Attachments;

/** A file of a guest's ticket by its link (docs/tickets.md §3 Δ): only of a message they may see, always downloaded. */
class GuestTicketAttachmentController extends Controller
{
    public function download(string $token, string $attachment)
    {
        $ticket = GuestLinks::find($token)?->ticket;
        abort_if($ticket === null, 404);
        $file = TicketMessageAttachment::query()->where('uuid', $attachment)
            ->whereIn('ticket_message_id', $ticket->publicMessages()->select('id'))->first();
        abort_if($file === null || !is_file($file->localPath), 404);

        return response()->download($file->localPath, Attachments::safeName($file->filename), [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
