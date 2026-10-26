<?php

namespace App\Http\Controllers;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\UserResource;
use App\Models\Client;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\CurrentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * «Leave» of «Login as client» (AUTH-19): ends it, and this tab goes back to the admin, at the client (or the user) it
 * was. Since it opens in a new tab (owner 2026-10-26) the admin's own pages no longer end it: only this, the client
 * area's «Log out», or the staff member's session ending.
 */
class LeaveImpersonationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $session = $request->session();
        if (!$session->has('impersonating')) {
            return redirect()->to('/dashboard');
        }
        $user = User::query()->find($session->get('impersonating'));
        $client = Client::query()->find($session->get(CurrentClient::IMPERSONATING_KEY));
        $session->forget(['impersonating', CurrentClient::IMPERSONATING_KEY]);
        Auth::guard('web')->forgetUser();

        return redirect()->to(match (true) {
            $client !== null => ClientResource::getUrl('view', ['record' => $client], panel: 'admin'),
            $user !== null => UserResource::getUrl('edit', ['record' => $user], panel: 'admin'),
            default => '/' . AdminPanelProvider::PATH,
        });
    }
}
