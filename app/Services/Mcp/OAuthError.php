<?php

namespace App\Services\Mcp;

use RuntimeException;

/** An OAuth error (RFC 6749 §5.2): its code, its description, the HTTP status. */
final class OAuthError extends RuntimeException
{
    public function __construct(public readonly string $error, string $description, public readonly int $status = 400)
    {
        parent::__construct($description);
    }

    /** @return array{error: string, error_description: string} */
    public function body(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
