<?php

namespace App\Livewire;

use App\Support\CurrentClient;
use App\Support\Portal\Attention;

class Dashboard extends Component
{
    public $activeComponent = 'services';

    public function render()
    {
        return view('dashboard', [
            'attention' => (new Attention(CurrentClient::model()))->items(),
        ])->layoutData([
            'sidebar' => true,
        ]);
    }
}
