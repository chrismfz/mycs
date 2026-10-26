<?php

namespace App\Http\Controllers;

use App\Helpers\ExtensionHelper;
use App\Http\Controllers\Admin\ServicePanelLoginController as AdminPanelLogin;
use App\Livewire\Services\Show;
use App\Models\Service;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use OwenIt\Auditing\Events\AuditCustom;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * The client area's sign-ins into a service's panel in a new tab (owner 2026-10-26): the service page posts a form
 * (CSRF, `target="_blank"`) here. Any server module's button marked `sso` (getActions) is one, and its shortcuts
 * (`type: shortcuts`) through getLoginUrl; only one the module offers for this service now, for a login with
 * `productsso` on its account (the route's `can:view` is that it is this account's). The address is never logged.
 */
class ServicePanelLoginController extends Controller
{
    public function __invoke(Request $request, Service $service): RedirectResponse
    {
        $back = fn (string $message) => tap(redirect()->route('services.show', $service), fn () => Session::put('notification', ['message' => $message, 'type' => 'error']));
        // Active now, not only when the page opened (ADV-2 of #318).
        if ($service->fresh()?->status !== Service::STATUS_ACTIVE) {
            return $back(__('This action is not allowed'));
        }
        if (!Show::loginMay($service, 'productsso')) {
            return $back(__('You do not have access to this area of the account.'));
        }
        try {
            // Strings only: `function[]=` is a refusal, not a 500 (ADV-3 of #465).
            $function = $request->input('function');
            $app = $request->input('app');
            $call = is_string($function) && ($app === null || is_string($app)) ? $this->offered($service, $function, $app === '' ? null : $app) : null;
        } catch (Throwable $e) {
            // A module whose actions come from its server's API, down for the moment: not «not allowed» (review of #465).
            report($e);

            return $back(__('services.cpanel.failed'));
        }
        if ($call === null) {
            return $back(__('This action is not allowed'));
        }
        try {
            $url = ExtensionHelper::callService($service, $call[0], $call[1]);
        } catch (Throwable $e) {
            report($e);

            return $back(__('services.cpanel.failed'));
        }
        if ($url instanceof SymfonyRedirect) {
            $url = $url->getTargetUrl();
        }
        // Only a web page (a server that answers `javascript:` would run it on this site): Show::panelUrl.
        if (!is_string($url) || ($url = Show::panelUrl($url)) === null) {
            return $back(__('services.cpanel.failed'));
        }
        // Staff in «Login as client» signing in: who, in the service's audit, as the admin's «Log in to panel».
        if (Actor::impersonating()) {
            $service->auditEvent = 'panel_login';
            $service->isCustomEvent = true;
            $service->auditCustomOld = [];
            $service->auditCustomNew = ['staff_id' => Auth::guard('staff')->id(), 'server_id' => $service->provisioningServer()?->id, 'username' => $service->username, 'function' => $call[0]];
            event(new AuditCustom($service));
        }

        return AdminPanelLogin::away($url);
    }

    /**
     * The module's call for this button, if it offers it for this service: an `sso` button's function, or a shortcut's
     * app through getLoginUrl. Never a function or an app it does not list.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function offered(Service $service, string $function, ?string $app): ?array
    {
        foreach ((array) ExtensionHelper::getActions($service) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = $action['type'] ?? null;
            if ($app === null && $type === 'button' && !empty($action['sso']) && is_string($action['function'] ?? null) && $action['function'] === $function) {
                return [$function, []];
            }
            if ($app !== null && $type === 'shortcuts' && $function === 'getLoginUrl'
                && in_array($app, array_column(array_filter((array) ($action['items'] ?? []), 'is_array'), 'app'), true)) {
                return ['getLoginUrl', [$app]];
            }
        }

        return null;
    }
}
