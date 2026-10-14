<?php

namespace App\Livewire\Services;

use App\Support\CurrentClient;
use App\Livewire\Component;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Widget extends Component
{
    use WithPagination;

    public $status = null;

    public function render()
    {
        $query = CurrentClient::model()->services();

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
