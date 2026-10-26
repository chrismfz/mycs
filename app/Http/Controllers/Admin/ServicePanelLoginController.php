<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Http\Controllers\Controller;
use App\Livewire\Services\Show;
use App\Models\Service;
use App\Support\StaffLink;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Events\AuditCustom;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * «Log in to panel» (#400) in a new tab (owner 2026-10-26): the service page's signed link (StaffLink) opens here, which
 * asks the server's module for its one-time address (getLoginUrl, any module that has it), audits who did it, and sends
 * the tab there. The address is never logged nor kept: it signs in whoever has it.
 */
class ServicePanelLoginController extends Controller
{
    public function __invoke(Request $request, Service $service): RedirectResponse
    {
        $back = ServiceResource::getUrl('edit', ['record' => $service]);
        $refusal = StaffLink::refusal($request);
        abort_if($refusal === StaffLink::FORGED, 403);
        if ($refusal === StaffLink::EXPIRED) {
            Notification::make()->warning()->title(__('This link has expired: try again.'))->send();

            return redirect()->to($back);
        }
        abort_unless(EditService::panelLoginOffered($service), 403);
        try {
            $url = ExtensionHelper::callService($service, 'getLoginUrl', [null]);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('Could not sign in to its panel'))->body(mb_substr($e->getMessage(), 0, 200))->send();

            return redirect()->to($back);
        }
        if ($url instanceof SymfonyRedirect) {
            $url = $url->getTargetUrl();
        }
        // Only an http(s) address of a host, never `javascript:` or one with credentials in it (Show::panelUrl).
        $url = is_string($url) ? Show::panelUrl($url) : null;
        if ($url === null) {
            Notification::make()->danger()->title(__('Could not sign in to its panel'))->send();

            return redirect()->to($back);
        }
        $service->auditEvent = 'panel_login';
        $service->isCustomEvent = true;
        $service->auditCustomOld = [];
        $service->auditCustomNew = ['staff_id' => Auth::guard('staff')->id(), 'server_id' => $service->provisioningServer()?->id, 'username' => $service->username];
        event(new AuditCustom($service));

        return self::away($url);
    }

    /**
     * To the panel's one-time address: kept by no cache, and the admin page it came from not sent to the panel as the
     * referrer.
     */
    public static function away(string $url): RedirectResponse
    {
        return redirect()->away($url)->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }
}
