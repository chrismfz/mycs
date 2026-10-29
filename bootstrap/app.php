<?php

use App\Http\Middleware\Api\AdminApi;
use App\Http\Middleware\CheckoutParameterMiddleware;
use App\Http\Middleware\EnsureClientAccount;
use App\Http\Middleware\EnsureClientPermission;
use App\Http\Middleware\EnsureUserHasPermissions;
use App\Http\Middleware\ImpersonateMiddleware;
use App\Http\Middleware\LockSession;
use App\Http\Middleware\ProxyMiddleware;
use App\Http\Middleware\ResolveUserSession;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\UseWebGuard;
use App\Models\DebugLog;
use App\Support\LogText;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        then: function () {
            Route::group([], __DIR__ . '/../routes/zlic.php');
            Route::group([], __DIR__ . '/../routes/health.php');
            Route::group([], __DIR__ . '/../routes/legacy.php');
        },
        // channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(ProxyMiddleware::class);
        // First of all: the answers of the other global middleware (the maintenance page, a too large post) carry them too.
        $middleware->prepend(SecurityHeaders::class);
        // zlic.php is a machine contract (CLAUDE.md, review ADV-L4/L7): answered during a deploy too (its own
        // «Remote Check Failed» is what clients fall back on), and its input as sent, never trimmed or nulled. So is the
        // Cardlink vPOS return: its digest signs the values as posted, an empty or padded one included (PR H2a, CR-1).
        // That return is also the only word of a card payment the bank has taken: a 503 during a deploy lost it (AUD8-1;
        // whether the vPOS sends it again is not known). Answered during a deploy too; Stripe, PayPal and Mollie send
        // their webhooks again, so those wait.
        $middleware->preventRequestsDuringMaintenance(except: ['zlic.php', 'extensions/cardlink/return']);
        $middleware->trimStrings(except: [fn ($request) => $request->is('zlic.php', 'extensions/cardlink/return')]);
        $middleware->convertEmptyStringsToNull(except: [fn ($request) => $request->is('zlic.php', 'extensions/cardlink/return')]);
        $middleware->alias([
            'has' => EnsureUserHasPermissions::class,
            'api.admin' => AdminApi::class,
            'checkout' => CheckoutParameterMiddleware::class,
            'client.can' => EnsureClientPermission::class,
        ]);
        $middleware->web([
            UseWebGuard::class,
            ResolveUserSession::class,
            LockSession::class,
            ImpersonateMiddleware::class,
            EnsureClientAccount::class,
            SetLocale::class,
        ]);
        // Before `auth`, which the priority sort otherwise moves ahead of the web group's own middleware: the client
        // area's guard, the customer's session, then «Login as client» (docs/accounts.md, PR 1). In this order.
        foreach ([UseWebGuard::class, ResolveUserSession::class, ImpersonateMiddleware::class] as $beforeAuth) {
            $middleware->prependToPriorityList(AuthenticatesRequests::class, $beforeAuth);
        }
    })
    ->withEvents(discover: [
        __DIR__ . '/../app/Extensions',
        __DIR__ . '/../app/Listeners',
    ])
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (Exception $exception) {
            try {
                if (!config('settings.debug', false)) {
                    return;
                }
                DebugLog::create([
                    'type' => 'exception',
                    'context' => [
                        // Masked unless «Full logs» (App\Support\LogText).
                        'message' => LogText::of($exception->getMessage()),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'trace' => LogText::of($exception->getTraceAsString()),
                    ],
                ]);
            } catch (Exception $e) {
                // Do nothing
                throw $e;
            }
        });
    })->create();
