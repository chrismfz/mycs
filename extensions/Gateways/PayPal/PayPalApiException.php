<?php

namespace Paymenter\Extensions\Gateways\PayPal;

use Exception;
use Illuminate\Http\Client\Response;

/**
 * A PayPal REST call that did not succeed (non-2xx). Carries PayPal's error name, first issue and debug_id
 * (what PayPal support asks for), never the request: it may hold tokens.
 */
class PayPalApiException extends Exception
{
    /** @var array<int, array<string, mixed>> */
    public readonly array $details;

    public function __construct(
        public readonly int $status,
        public readonly ?string $name,
        public readonly ?string $issue,
        public readonly ?string $debugId,
        array $details = [],
        ?string $message = null,
    ) {
        $this->details = $details;

        parent::__construct(sprintf(
            'PayPal API error %d%s%s%s',
            $status,
            $name ? ' ' . $name : '',
            $issue ? ' (' . $issue . ')' : '',
            $debugId ? ', debug_id ' . $debugId : '',
        ) . ($message ? ': ' . mb_substr($message, 0, 300) : ''));
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        // OAuth errors use error/error_description, REST errors name/message/details.
        $details = is_array($body['details'] ?? null) ? array_values(array_filter($body['details'], 'is_array')) : [];

        return new self(
            $response->status(),
            is_string($body['name'] ?? null) ? $body['name'] : (is_string($body['error'] ?? null) ? $body['error'] : null),
            is_string($details[0]['issue'] ?? null) ? $details[0]['issue'] : null,
            is_string($body['debug_id'] ?? null) ? $body['debug_id'] : null,
            $details,
            is_string($body['message'] ?? null) ? $body['message'] : (is_string($body['error_description'] ?? null) ? $body['error_description'] : null),
        );
    }

    /**
     * The error as PayPal's JS SDK expects it back from the capture endpoint (name, details, debug_id).
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'name' => $this->name,
            'details' => array_map(fn (array $detail) => array_intersect_key($detail, array_flip(['issue', 'description'])), $this->details),
            'debug_id' => $this->debugId,
        ];
    }
}
