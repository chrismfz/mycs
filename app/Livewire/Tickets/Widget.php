<?php

namespace App\Livewire\Tickets;

use App\Livewire\Component;
use App\Models\Ticket;
use App\Support\CurrentClient;
use Livewire\WithPagination;

class Widget extends Component
{
    use WithPagination;

    public function render()
    {
        return view('tickets.widget', [
            'tickets' => Ticket::where('client_id', CurrentClient::allows('tickets') ? CurrentClient::id() : 0)->where('status', '!=', 'closed')->latest()->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('navigation.tickets'),
        ]);
    }
}
