<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\UserResource;
use App\Admin\Resources\UserResource\Pages\EditUser;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Staff;
use App\Models\User;
use App\Support\StaffLink;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * «Login as client» (AUTH-19) in a new tab (owner 2026-10-26): the client's or the user's page has a signed link
 * (StaffLink) to here, which starts it and sends the tab to the client area. The admin tab stays as it was: staff stay
 * logged in there (guard `staff`), and the client area sees the customer (ImpersonateMiddleware) until «Leave».
 */
class LoginAsClientController extends Controller
{
    /** As the account's owner, on that account (WHMCS logs in as the owner). */
    public function client(Request $request, Client $client): RedirectResponse
    {
        if (($expired = $this->refused($request, ClientResource::getUrl('view', ['record' => $client]))) !== null) {
            return $expired;
        }
        // Who may see the button: the permission, and edit rights on the client (the Summary's, review F2).
        abort_unless(ClientResource::canEdit($client), 403);
        $owner = $client->owner();
        abort_if($owner === null, 404);
        EditUser::impersonate($owner, $client);

        return redirect()->to('/dashboard');
    }

    /** As this user, on their default account. */
    public function user(Request $request, User $user): RedirectResponse
    {
        if (($expired = $this->refused($request, UserResource::getUrl('edit', ['record' => $user]))) !== null) {
            return $expired;
        }
        abort_unless(UserResource::canEdit($user), 403);
        EditUser::impersonate($user);

        return redirect()->to('/dashboard');
    }

    /** A link not theirs is a 403; one older than an hour goes back to the page it came from, to try again. */
    private function refused(Request $request, string $back): ?RedirectResponse
    {
        $refusal = StaffLink::refusal($request);
        abort_if($refusal === StaffLink::FORGED, 403);
        $staff = Auth::guard('staff')->user();
        abort_unless($staff instanceof Staff && $staff->hasPermission('admin.users.impersonate'), 403);
        if ($refusal === StaffLink::EXPIRED) {
            Notification::make()->warning()->title(__('This link has expired: try again.'))->send();

            return redirect()->to($back);
        }

        return null;
    }
}
