<?php

namespace App\Livewire;

use App\Support\CurrentClient;
use App\Support\Portal\Attention;
use App\Support\Portal\Summary;
use App\Support\Portal\UpcomingRenewals;

class Dashboard extends Component
{
    public $activeComponent = 'services';

    public function render()
    {
        $summary = new Summary(CurrentClient::model());

        return view('dashboard', [
            'tiles' => $summary->tiles(),
            'services' => $summary->services(),
            'servicesCount' => $summary->servicesCount(),
            'attention' => (new Attention(CurrentClient::model()))->items(),
            'renewals' => (new UpcomingRenewals(CurrentClient::model()))->months(),
        ])->layoutData([
            'sidebar' => true,
        ]);
    }
}
