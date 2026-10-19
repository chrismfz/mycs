<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Support\CurrentClient;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public $status = null;

    public function render()
    {
        $query = CurrentClient::model()->services()->orderBy('created_at', 'desc');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return view('services.index', [
            'services' => $query->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('navigation.services'),
            'sidebar' => true,
        ]);
    }
}
