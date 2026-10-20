<?php

namespace App\Livewire;

use App\Support\CurrentClient;
use App\Support\Portal\Attention;
use App\Support\Portal\UpcomingRenewals;

class Dashboard extends Component
{
    public $activeComponent = 'services';

    public function render()
    {
        return view('dashboard', [
            'attention' => (new Attention(CurrentClient::model()))->items(),
            'renewals' => (new UpcomingRenewals(CurrentClient::model()))->months(),
        ])->layoutData([
            'sidebar' => true,
        ]);
    }
}
