<?php

namespace App\Http\Middleware;

use App\Support\CurrentClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client-area page of the current account needs the login's grant to allow its area (docs/accounts.md, PR 2b;
 * WHMCS permission names: `invoices`, `tickets`, `products`…). The owner, and a grant without a list, have them all.
 */
class EnsureClientPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(CurrentClient::allows($permission), 403, __('You do not have access to this area of the account.'));

        return $next($request);
    }
}
