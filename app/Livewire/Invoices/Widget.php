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
            // Nothing without `invoices` (docs/accounts.md, PR 2b): the widget is called on its own too.
            'invoices' => (CurrentClient::allows('invoices') ? CurrentClient::model()->invoices() : Invoice::query()->whereRaw('1 = 0'))->orderBy('id', 'desc')->where('status', '=', Invoice::STATUS_UNPAID)->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('invoices.invoices'),
        ]);
    }
}
