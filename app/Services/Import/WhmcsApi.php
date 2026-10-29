<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WHMCS's own API, for what its database keeps encrypted with a key that is not in it (cc_encryption_hash): the saved
 * payment methods' remote tokens (GetPayMethods). Only the reads a step needs (READS), over https only; its identifier
 * and secret from the environment (WHMCS_API_IDENTIFIER, WHMCS_API_SECRET), never an argument or a log line.
 */
final class WhmcsApi
{
    /** The only actions asked: reads. */
    private const READS = ['GetPayMethods', 'WhmcsDetails']; // what the importer reads; another is added with its step

    public function __construct(
        private readonly string $url,
        private readonly string $identifier,
        #[\SensitiveParameter] private readonly string $secret,
    ) {}

    /** From the environment and the given URL; null when either is missing (the step then says so and skips). */
    public static function fromEnvironment(?string $url): ?self
    {
        $identifier = (string) getenv('WHMCS_API_IDENTIFIER');
        $secret = (string) getenv('WHMCS_API_SECRET');
        if ($url === null || trim($url) === '' || $identifier === '' || $secret === '') {
            return null;
        }
        // Its secret, and every customer's references in the answers: https only (ADV-538-2).
        if (!str_starts_with(strtolower(trim($url)), 'https://')) {
            throw new RuntimeException('WHMCS API: --whmcs-api-url must be https.');
        }

        return new self(rtrim(trim($url), '/'), $identifier, $secret);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed> its JSON answer (result success)
     */
    public function call(string $action, array $params = []): array
    {
        if (!in_array($action, self::READS, true)) {
            throw new RuntimeException("WHMCS API: «{$action}» is not a read the importer asks.");
        }
        if (!str_starts_with(strtolower($this->url), 'https://')) {
            throw new RuntimeException('WHMCS API: https only.');
        }
        $response = Http::asForm()->timeout(30)->retry(2, 500, throw: false)
            ->post($this->url . '/includes/api.php', ['identifier' => $this->identifier, 'secret' => $this->secret, 'action' => $action,
                'responsetype' => 'json'] + $params);
        $body = $response->json();
        if (!$response->successful() || !is_array($body) || ($body['result'] ?? null) !== 'success') {
            // Its message only (never the request: it carries the secret).
            throw new RuntimeException("WHMCS API «{$action}» failed (HTTP {$response->status()}): " . mb_substr((string) ($body['message'] ?? 'no answer'), 0, 200));
        }

        return $body;
    }
}
