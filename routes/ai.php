<?php

use App\Http\Controllers\McpOauthController;
use App\Http\Middleware\McpAuth;
use App\Mcp\Servers\MycsServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
| MyCS's MCP server (docs/mcp.md), loaded by laravel/mcp. Staff's only: an admin API key with «Use the MCP Server», or
| an OAuth access token staff approved (McpAuth); never the customers' Passport tokens (/oauth/*, apart).
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
Route::middleware('web')->group(function (): void {
    Route::get('/mcp/oauth/authorize', [McpOauthController::class, 'show'])->name('mcp.oauth.authorize');
    Route::post('/mcp/oauth/authorize', [McpOauthController::class, 'approve']);
});
