<?php

namespace App\Services\Mcp;

use App\Models\McpOauthClient;

/** A validated authorization request (docs/mcp.md): the client, its registered redirect URI, PKCE S256, the state. */
final class AuthorizeRequest
{
    public function __construct(
        public readonly McpOauthClient $client,
        public readonly string $redirectUri,
        public readonly string $codeChallenge,
        public readonly ?string $state,
    ) {}

    /** @return array<string, string> the request again, for the consent form */
    public function fields(): array
    {
        return array_filter(['response_type' => 'code', 'client_id' => $this->client->client_id, 'redirect_uri' => $this->redirectUri,
            'code_challenge' => $this->codeChallenge, 'code_challenge_method' => 'S256', 'state' => $this->state], fn ($value) => $value !== null);
    }

    /** @param array<string, string> $query */
    public function redirect(array $query): string
    {
        $query += $this->state !== null ? ['state' => $this->state] : [];

        return $this->redirectUri . (str_contains($this->redirectUri, '?') ? '&' : '?') . http_build_query($query);
    }
}
