<?php

namespace MyCS\TicketMail\Tests;

/** Raw RFC 822 for the tests: headers as given, a text body, files as base64 parts. */
final class Eml
{
    /**
     * @param  array<int|string, string>  $headers  name => value, or "Name: value" lines (for repeated headers)
     * @param  list<array{name: string, type?: string, content: string, inline?: string}>  $files
     */
    public static function make(array $headers = [], string $text = 'Hello there', array $files = [], ?string $html = null): string
    {
        $lines = [];
        $headers += ['From' => 'Maria <maria@example.com>', 'To' => 'support@myip.test', 'Subject' => 'Help',
            'Message-ID' => '<m1@example.com>', 'Date' => 'Tue, 20 Oct 2026 10:00:00 +0300'];
        foreach ($headers as $name => $value) {
            $lines[] = is_int($name) ? $value : "{$name}: {$value}";
        }
        $lines[] = 'MIME-Version: 1.0';

        if ($files === [] && $html === null) {
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';

            return implode("\r\n", $lines) . "\r\n\r\n" . $text;
        }

        $boundary = 'b' . bin2hex(random_bytes(6));
        $lines[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        $body = "--{$boundary}\r\nContent-Type: " . ($html !== null ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n\r\n" . ($html ?? $text) . "\r\n";
        foreach ($files as $file) {
            $disposition = isset($file['inline']) ? "inline; filename=\"{$file['name']}\"\r\nContent-ID: <{$file['inline']}>" : "attachment; filename=\"{$file['name']}\"";
            $body .= "--{$boundary}\r\nContent-Type: " . ($file['type'] ?? 'application/octet-stream') . "; name=\"{$file['name']}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\nContent-Disposition: {$disposition}\r\n\r\n"
                . chunk_split(base64_encode($file['content'])) . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }
}
