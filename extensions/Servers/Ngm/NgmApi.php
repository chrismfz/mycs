<?php

namespace MyCS\Extensions\Servers\Ngm;

use App\Exceptions\ServerRefused;
use Exception;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * ngm's account API (`/api/v1`, as our WHMCS module's lib/Api.php calls it): a bearer token, JSON both ways, every error
 * `{"error": "…"}` with its HTTP status. It listens on its own port (ngm's `api.listen`, 9601 by default), not on 443,
 * where nginx serves the customers' sites: a host given without a port gets 9601, and https unless it says http.
 */
final class NgmApi
{
    public const DEFAULT_PORT = 9601;

    /** ngm answered and refused, with its reason (a bad body, a weak password, a name taken, a domain refused). */
    private const REFUSALS = [400, 409, 422];

    public function __construct(
        private readonly string $host,
        private readonly string $token,
        private readonly int $timeout = 30,
    ) {}

    /**
     * The host's origin, `scheme://host[:port]`: https unless it says http, its own port kept.
     *
     * @return array{0: string, 1: string, 2: ?int} scheme, host (an IPv6 one in brackets), port
     */
    private function parts(): array
    {
        $host = trim($this->host);
        if ($host === '') {
            throw new Exception('ngm: the server has no hostname');
        }
        $parts = parse_url(preg_match('#^https?://#i', $host) ? $host : 'https://' . $host);
        if ($parts === false || empty($parts['host'])) {
            throw new Exception('ngm: the server\'s hostname cannot be read');
        }
        $name = trim($parts['host'], '[]');

        return [strtolower($parts['scheme'] ?? 'https'), str_contains($name, ':') ? "[{$name}]" : $name, $parts['port'] ?? null];
    }

    /** The API's address, without a trailing slash. */
    public function baseUrl(): string
    {
        [$scheme, $host, $port] = $this->parts();

        return "{$scheme}://{$host}:" . ($port ?? self::DEFAULT_PORT) . '/api/v1';
    }

    /** The panel's sign-in page: the host itself (its web port), as the WHMCS module links it. */
    public function panelUrl(): string
    {
        [$scheme, $host] = $this->parts();

        return "{$scheme}://{$host}/ui/";
    }

    /**
     * One call: its status and its JSON (an answer that is not JSON, a proxy's page, becomes its `error`). No answer is an
     * exception, never with the token in it.
     *
     * @param  array<string, mixed>|null  $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if (trim($this->token) === '') {
            throw new Exception('ngm: the server has no API token');
        }
        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        try {
            $request = Http::withToken(trim($this->token))->acceptJson()->connectTimeout(10)->timeout($this->timeout)->withoutRedirecting();
            if ($body !== null) {
                // An empty body is `{}`: ngm reads an object, never `[]`.
                $request = $request->withBody((string) json_encode($body === [] ? new stdClass : $body), 'application/json');
            }
            $response = $request->send($method, $url);
        } catch (Exception $e) {
            throw new Exception("ngm {$method} /{$path}: no answer (" . class_basename($e) . ')');
        }
        $raw = trim($response->body());
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['error' => "an answer that is not JSON (HTTP {$response->status()})"];
        }

        return [$response->status(), $data];
    }

    /**
     * A call that must answer one of these statuses: its JSON. ngm's refusal (400, 409, 422 with its reason) is a
     * ServerRefused the customer may read; anything else (a bad token, a gate off, an error of its own) is not.
     *
     * @param  array<string, mixed>|null  $body
     * @param  list<int>  $ok
     * @return array<string, mixed>
     */
    public function expect(string $method, string $path, ?array $body, array $ok): array
    {
        [$status, $data] = $this->request($method, $path, $body);
        if (in_array($status, $ok, true)) {
            return $data;
        }

        throw self::failure($method, $path, $status, $data);
    }

    /** @param  array<string, mixed>  $data */
    public static function failure(string $method, string $path, int $status, array $data): Exception
    {
        $error = is_string($data['error'] ?? null) && trim($data['error']) !== '' ? trim($data['error']) : null;
        if ($error !== null && in_array($status, self::REFUSALS, true)) {
            return new ServerRefused("ngm: {$error}");
        }
        $hint = match ($status) {
            401 => ': the API token was rejected, check it',
            403 => ': refused (the token needs the scopes read and accounts, and the host api.account_writes on)',
            default => '',
        };

        return new Exception("ngm {$method} /{$path}: HTTP {$status}{$hint}" . ($error !== null ? " ({$error})" : ''));
    }
}
