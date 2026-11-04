<?php

namespace App\Livewire\Client;

use App\Livewire\Component;
use App\Models\User;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Auth;

/**
 * The accounts a login can manage, and switching between them (docs/accounts.md, PR 2b; WHMCS «Switch Account»).
 * Staff in «Login as client» stay on the account they opened.
 */
class Accounts extends Component
{
    public function switch(int $clientId): void
    {
        if (Actor::impersonating() || !app(CurrentClient::class)->switchTo($clientId)) {
            $this->notify(__('This action is not allowed'), 'error');

            return;
        }
        $client = CurrentClient::model();
        $client?->grants()->where('user_id', Auth::id())->update(['last_login_at' => now()]);

        $this->notify(__('account.switched_account', ['name' => $client?->name]), 'success', true);
        $this->redirect(route('dashboard'), true);
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();

        return view('client.account.accounts', [
            // A closed one is not offered (review of #651), unless staff in «Login as client» look.
            'accounts' => $user->clients()->when(!Actor::impersonating(), fn ($open) => $open->whereNull('clients.closed_at'))
                ->with(['primaryContact', 'ownerGrant.user'])->orderByDesc('client_user.owner')->orderBy('clients.id')->get(),
            'current' => CurrentClient::id(),
            'impersonating' => Actor::impersonating(),
        ])->layoutData([
            'sidebar' => true,
            'title' => __('account.accounts'),
        ]);
    }
}
