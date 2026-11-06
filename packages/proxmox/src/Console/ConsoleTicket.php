<?php

namespace MyCS\Proxmox\Console;

/**
 * A console ticket (docs/proxmox.md, «Σχέδιο: το relay στο cluster»): what MyCS signs for the browser to hand the relay,
 * and the relay's check of it. It names one guest on one node for one login of one site, for a few seconds; it holds
 * no secret, so a leaked one opens that console once, nothing else.
 *
 * `v1.<payload>.<signature>`, both base64url: the payload JSON, the signature HMAC-SHA256 with the site's key over
 * «console-ticket|<payload>». The relay's calls back to MyCS are signed over «console-check|<body>»: the prefixes keep
 * one direction's signature from ever passing as the other's.
 */
final class ConsoleTicket
{
    /** A ticket's life: the browser opens the websocket at once. */
    public const TTL = 30;

    /** Clocks of MyCS and the relay may differ this much. */
    public const SKEW = 5;

    /** A key shorter than this is refused: it is random, never a password. */
    public const MIN_KEY = 32;

    private const MAX_LENGTH = 1024;

    /**
     * @param  array{origin: string, server_id: int, service_id: int, user_id: int, node: string, type: string, vmid: int}  $console
     */
    public static function sign(array $console, string $key, ?int $now = null): string
    {
        self::assertKey($key);
        $now ??= time();
        $payload = self::encode((string) json_encode([
            'o' => $console['origin'], 'sv' => $console['server_id'], 's' => $console['service_id'], 'u' => $console['user_id'],
            'n' => $console['node'], 't' => $console['type'], 'v' => $console['vmid'], 'iat' => $now, 'exp' => $now + self::TTL,
            'j' => self::encode(random_bytes(16)),
        ], JSON_UNESCAPED_SLASHES));

        return 'v1.' . $payload . '.' . self::encode(hash_hmac('sha256', 'console-ticket|' . $payload, $key, true));
    }

    /**
     * The ticket's console when one of the keys signed it, its fields sound, and its time not over; null otherwise. Its
     * one use is the caller's to keep (the relay remembers its `jti` until it expires).
     *
     * @param  list<string>  $keys
     * @return array{origin: string, server_id: int, service_id: int, user_id: int, node: string, type: string, vmid: int, iat: int, exp: int, jti: string}|null
     */
    public static function verify(string $token, array $keys, ?int $now = null): ?array
    {
        $now ??= time();
        if (strlen($token) > self::MAX_LENGTH || preg_match('/^v1\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return null;
        }
        $signed = false;
        foreach ($keys as $key) {
            if (strlen($key) >= self::MIN_KEY && hash_equals(self::encode(hash_hmac('sha256', 'console-ticket|' . $m[1], $key, true)), $m[2])) {
                $signed = true;
            }
        }
        $claims = $signed ? json_decode((string) self::decode($m[1]), true) : null;
        if (!is_array($claims)) {
            return null;
        }
        foreach (['sv', 's', 'u', 'v', 'iat', 'exp'] as $int) {
            if (!is_int($claims[$int] ?? null) || $claims[$int] < 0) {
                return null;
            }
        }
        if (!is_string($claims['o'] ?? null) || preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?$#', $claims['o']) !== 1
            || !is_string($claims['n'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,62}$/', $claims['n']) !== 1
            || !in_array($claims['t'] ?? null, ['qemu', 'lxc'], true) || $claims['v'] < 100
            || !is_string($claims['j'] ?? null) || preg_match('/^[A-Za-z0-9_-]{22}$/', $claims['j']) !== 1
            || 2 * self::TTL < $claims['exp'] - $claims['iat'] || $claims['exp'] < $claims['iat']
            || $now > $claims['exp'] + self::SKEW || $claims['iat'] > $now + self::SKEW) {
            return null;
        }

        return ['origin' => $claims['o'], 'server_id' => $claims['sv'], 'service_id' => $claims['s'], 'user_id' => $claims['u'],
            'node' => $claims['n'], 'type' => $claims['t'], 'vmid' => $claims['v'], 'iat' => $claims['iat'], 'exp' => $claims['exp'], 'jti' => $claims['j']];
    }

    /**
     * The site and the cluster a ticket says it is of, unchecked: only to pick the keys it must then be verified with
     * (one cluster's key never passes a ticket naming another).
     *
     * @return array{origin: string, server_id: int}|null
     */
    public static function peek(string $token): ?array
    {
        if (strlen($token) > self::MAX_LENGTH || preg_match('/^v1\.([A-Za-z0-9_-]+)\./', $token, $m) !== 1) {
            return null;
        }
        $claims = json_decode((string) self::decode($m[1]), true);

        return is_array($claims) && is_string($claims['o'] ?? null) && is_int($claims['sv'] ?? null)
            ? ['origin' => $claims['o'], 'server_id' => $claims['sv']] : null;
    }

    /** The header of the relay's call to MyCS (`X-Console-Signature`): over its whole body, which carries its time. */
    public static function checkSignature(string $body, string $key): string
    {
        self::assertKey($key);

        return 'sha256=' . hash_hmac('sha256', 'console-check|' . $body, $key);
    }

    /** @param  list<string>  $keys */
    public static function checkSigned(string $body, string $signature, array $keys): bool
    {
        foreach ($keys as $key) {
            if (strlen($key) >= self::MIN_KEY && hash_equals(self::checkSignature($body, $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) < self::MIN_KEY) {
            throw new \InvalidArgumentException('A console key has at least ' . self::MIN_KEY . ' characters.');
        }
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
