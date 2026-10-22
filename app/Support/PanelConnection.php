<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * How MyCS reaches a hosting panel that holds a server's root key (cPanel/WHM, DirectAdmin): over HTTPS only (security
 * audit PROV-3), never a certificate pin (owner's decision 2026-10-23: certificates change often and on their own).
 *
 * - The server's IP, when set, is where the connection goes, whatever DNS says (CURLOPT_RESOLVE): a changed or
 *   poisoned record never sends the key elsewhere.
 * - A self-signed certificate is accepted only with that IP: the host and its address are then what is checked.
 */
final class PanelConnection
{
    /**
     * The server settings each panel adds next to its address.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        return [
            [
                'name' => 'ip',
                'type' => 'text',
                'label' => 'Server IP',
                'placeholder' => '203.0.113.10',
                'validation' => 'nullable|ip',
                'required' => false,
                'description' => 'The connection goes to this address whatever DNS says. Needed for a self-signed certificate.',
            ],
            [
                'name' => 'self_signed',
                'type' => 'checkbox',
                'label' => 'Self-signed certificate',
                'database_type' => 'boolean',
                'default' => false,
                'required' => false,
                'description' => 'The panel\'s certificate is not checked against a certificate authority: the connection goes only to the server IP above (required). Prefer a valid certificate where the panel can have one.',
            ],
        ];
    }

    /** A request to the panel at $base («https://host:port»), as its server's settings say. */
    public static function http(string $base, ?string $ip, bool $selfSigned): PendingRequest
    {
        $parts = parse_url(trim($base));
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new RuntimeException('The panel\'s address must start with https:// (its key is sent with every request).');
        }
        $host = trim($parts['host'], '[]');
        // As curl sends it: a host in another script by its punycode, or the IP would not match it.
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $host = (string) (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host);
        }
        $port = (int) ($parts['port'] ?? 443);
        $ip = trim((string) $ip);
        if ($ip === '' && filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ip = $host; // an address given as an IP is that IP
        }
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("The server IP «{$ip}» is not an IP address.");
        }
        if ($selfSigned && $ip === '') {
            throw new RuntimeException('A self-signed certificate needs the server IP: set it in the server\'s settings.');
        }

        // Never followed: a 307/308 would send the body (an account's password) again, to http:// or to another host
        // where neither the IP nor the certificate is checked (review of #356). A panel's API answers, it never moves.
        $options = ['allow_redirects' => false];
        if ($ip !== '' && $ip !== $host) {
            $options['curl'] = [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]];
        }
        if ($selfSigned) {
            $options['verify'] = false;
        }

        return Http::withOptions($options);
    }
}
