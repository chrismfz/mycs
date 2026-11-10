<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * A money-writing POST of the Admin API sent again after a timeout is made once (REV-782-3): with an `Idempotency-Key`
 * header, the first successful answer for (API key, path, header) is kept for 24 hours and given back to a repeat; the
 * same header with another body is refused. Without the header nothing changes. After `api.admin` (it reads the key).
 */
class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Idempotency-Key');
        if ($header === null) {
            return $next($request);
        }
        if (!is_string($header) || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $header)) {
            abort(422, 'The Idempotency-Key header is 8 to 100 letters, digits, dots, colons, dashes or underscores (a UUID).');
        }
        $slot = 'api-idempotency:' . hash('sha256', $request->attributes->get('api_key')?->getKey() . '|' . $request->path() . '|' . $header);
        $body = hash('sha256', $request->getContent());
        // One at a time: a repeat that arrives while the first is still being made is told so, and made by neither.
        if (!Cache::add($slot . ':running', true, 60)) {
            abort(409, 'A request with this Idempotency-Key is still being handled: ask again in a moment.');
        }
        try {
            if (($first = Cache::get($slot)) !== null) {
                abort_if($first['body'] !== $body, 422, 'This Idempotency-Key was used for another request: send a new key for a new request.');

                return response($first['content'], $first['status'], ['Content-Type' => 'application/json', 'Idempotency-Replayed' => 'true']);
            }
            $response = $next($request);
            // Only what was done is kept: a refusal changed nothing, and is asked again as it is.
            if ($response->isSuccessful()) {
                Cache::put($slot, ['body' => $body, 'status' => $response->getStatusCode(), 'content' => (string) $response->getContent()], now()->addDay());
            }

            return $response;
        } finally {
            Cache::forget($slot . ':running');
        }
    }
}
