<?php

namespace App\Services\Ops;

use App\Support\Redact;

/**
 * Text that leaves the server through the ops tools (docs/ops.md): Redact's rules (stored secrets, cPanel sessions,
 * webhook tokens, secret query keys of any URL in it), plus Authorization headers and the secrets of the app's own
 * config (APP_KEY, database, mail, AWS). A license key is shown by its prefix and last 4.
 */
final class Scrub
{
    /**
     * $headers false: a Bearer token and an «Authorization: Basic …» header still masked, but not a bare «Basic …», for
     * text that is kept (an MCP note), where «Basic troubleshooting» is English, not a header (review of #860, ADV-860-2,
     * its re-review and SEC-860-2).
     */
    public static function text(?string $text, bool $headers = true): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $text = (string) preg_replace('/\b(' . ($headers ? 'Bearer|Basic' : 'Bearer') . ')\s+[A-Za-z0-9._~+\/=\-]{8,}/i', '$1 ' . Redact::MASK, $text);
        $text = $headers ? $text : (string) preg_replace('/\b(Authorization["\']?\s*(?::|=>?)\s*["\']?Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i', '$1 ' . Redact::MASK, $text);
        $text = (string) preg_replace_callback('#https?://[^\s"\'<>]+#', fn (array $match) => Redact::url($match[0]), $text);
        $text = Redact::text($text);
        $values = self::configSecrets();

        return Redact::values(Redact::values($text, $values), array_map(fn ($value) => trim((string) json_encode($value), '"'), $values));
    }

    public static function licenseKey(?string $key): ?string
    {
        if ($key === null || mb_strlen($key) <= 8) {
            return $key;
        }
        $prefix = str_contains($key, '-') ? substr($key, 0, strpos($key, '-') + 1) : '';

        return $prefix . '…' . substr($key, -4);
    }

    /** @return list<string> */
    private static function configSecrets(): array
    {
        $values = [config('app.key'),
            config('services.ses.secret'), config('filesystems.disks.s3.secret')];
        foreach ((array) config('database.connections') as $connection) {
            $values[] = $connection['password'] ?? null;
        }
        foreach ((array) config('mail.mailers') as $mailer) {
            $values[] = $mailer['password'] ?? null;
        }
        $values[] = config('settings.mail_password');

        return array_values(array_filter(array_map(fn ($value) => is_string($value) ? $value : null, $values), fn ($value) => $value !== null && mb_strlen($value) >= 6));
    }
}
