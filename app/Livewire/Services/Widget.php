<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use App\Support\CurrentClient;
use Livewire\WithPagination;

class Widget extends Component
{
    use WithPagination;

    public $status = null;

    public function render()
    {
        // Nothing without `products` (docs/accounts.md, PR 2b): the widget is called on its own too.
        $query = CurrentClient::allows('products') ? CurrentClient::model()->services() : Service::query()->whereRaw('1 = 0');

        if ($this->status) {
            $query->where('status', $this->status);
        } else {
            $query->whereNotIn('status', Service::ENDED_STATUSES);
        }

        return view('services.widget', [
            'services' => $query->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => 'Services',
        ]);
    }
}
