<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Support\Tickets\TicketPresence;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A ticket's page closed or left (docs/tickets.md §6ν): its beacon removes its row now, not when it expires. Only the
 * member's own page: another's id removes nothing.
 */
class TicketPresenceController extends Controller
{
    public function leave(Request $request): Response
    {
        $page = $request->input('page');
        $staff = $request->user('staff');
        if (is_string($page) && strlen($page) <= 64 && $staff instanceof Staff) {
            TicketPresence::leave($staff, $page);
        }

        return response()->noContent();
    }
}
