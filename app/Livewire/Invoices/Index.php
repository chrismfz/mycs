<?php

namespace App\Livewire\Invoices;

use App\Livewire\Component;
use App\Models\Invoice;
use App\Services\Invoice\MassPay;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    /** @var list<int|string> unpaid invoices chosen to pay at once (Mass Pay, PR F2) */
    public array $selected = [];

    /** Mass Pay (PR F2): the chosen invoices become the account's payment group, paid on its page. */
    public function payTogether()
    {
        $group = (new MassPay)->open(CurrentClient::model(), $this->selected, Auth::user());

        return $this->redirect(route('invoices.pay-together', $group), true);
    }

    public function render()
    {
        $client = CurrentClient::model();

        return view('invoices.index', [
            'invoices' => $client->invoices()->where('status', '!=', Invoice::STATUS_DRAFT)->with(['client.primaryContact', 'client.ownerGrant.user', 'snapshot', 'items'])->orderBy('id', 'desc')->paginate(config('settings.pagination')),
            'massPay' => MassPay::enabled() && $client->invoices()->where('status', Invoice::STATUS_UNPAID)->count() >= 2,
        ])->layoutData([
            'title' => __('invoices.invoices'),
            'sidebar' => true,
        ]);
    }
}
