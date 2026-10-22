<?php

use App\Http\Controllers\McpOauthController;
use App\Http\Middleware\LockSession;
use App\Http\Middleware\McpAuth;
use App\Http\Middleware\UseStaffGuard;
use App\Mcp\Servers\MycsServer;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Mcp\Facades\Mcp;

/*
| MyCS's MCP server (docs/mcp.md), loaded by laravel/mcp. Staff's only: an admin API key with «Use the MCP Server», or
| an OAuth access token staff approved (McpAuth). There is no customer OAuth server (Passport removed, AUTHN-10).
*/
Mcp::web('/mcp', MycsServer::class)->middleware(McpAuth::class);

// OAuth for the MCP server (McpOauth): metadata, registration and tokens need no session; the consent is a page.
Route::get('/.well-known/oauth-protected-resource', [McpOauthController::class, 'protectedResource']);
// Named as laravel/mcp looks for it: its 401 then names this metadata (resource_metadata) for the client to discover.
Route::get('/.well-known/oauth-protected-resource/{path}', [McpOauthController::class, 'protectedResource'])
    ->where('path', '.*')->name('mcp.oauth.protected-resource.nested');
Route::get('/.well-known/oauth-authorization-server', [McpOauthController::class, 'authorizationServer']);
Route::post('/mcp/oauth/register', [McpOauthController::class, 'register']);
Route::post('/mcp/oauth/token', [McpOauthController::class, 'token']);
// The consent reads the admin's session: with the admin panel's own session checks (review MCPO-3: the IP/agent lock,
// a password change), never the client area's.
Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, UseStaffGuard::class, LockSession::class,
    AuthenticateSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, SubstituteBindings::class])->group(function (): void {
        Route::get('/mcp/oauth/authorize', [McpOauthController::class, 'show'])->name('mcp.oauth.authorize');
        Route::post('/mcp/oauth/authorize', [McpOauthController::class, 'approve']);
    });
