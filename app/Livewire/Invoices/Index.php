<?php

namespace App\Livewire\Invoices;

use App\Support\CurrentClient;
use App\Livewire\Component;
use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('invoices.index', [
            'invoices' => CurrentClient::model()->invoices()->where('status', '!=', Invoice::STATUS_DRAFT)->with(['client', 'snapshot', 'items'])->orderBy('id', 'desc')->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('invoices.invoices'),
            'sidebar' => true,
        ]);
    }
}
