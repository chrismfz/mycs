<?php

namespace App\Livewire\Invoices;

use App\Livewire\Component;
use App\Models\Invoice;
use App\Support\CurrentClient;
use Livewire\WithPagination;

class Widget extends Component
{
    use WithPagination;

    public function render()
    {
        return view('invoices.widget', [
            'invoices' => CurrentClient::model()->invoices()->orderBy('id', 'desc')->where('status', '=', Invoice::STATUS_UNPAID)->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('invoices.invoices'),
        ]);
    }
}
