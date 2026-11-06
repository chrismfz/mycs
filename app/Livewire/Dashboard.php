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
        // The unpaid invoices, read once for the counts and «Needs your attention» (review of #616).
        $unpaid = CurrentClient::allows('invoices') ? Attention::unpaidOf(CurrentClient::model()) : null;
        $summary = new Summary(CurrentClient::model(), $unpaid);

        return view('dashboard', [
            'tiles' => $summary->tiles(),
            'services' => $summary->services(),
            'servicesCount' => $summary->servicesCount(),
            'attention' => (new Attention(CurrentClient::model(), $unpaid))->items(),
            'renewals' => (new UpcomingRenewals(CurrentClient::model()))->months(),
        ])->layoutData([
            'sidebar' => true,
        ]);
    }
}
