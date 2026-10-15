<?php

namespace App\Services\Mcp;

/** An authorization request error the client hears about at its (validated) redirect URI (RFC 6749 §4.1.2.1). */
final class RedirectedOAuthError extends \RuntimeException
{
    public function __construct(public readonly AuthorizeRequest $request, public readonly string $error, string $description)
    {
        parent::__construct($description);
    }

    public function redirect(): string
    {
        return $this->request->redirect(['error' => $this->error, 'error_description' => $this->getMessage()]);
    }
}
