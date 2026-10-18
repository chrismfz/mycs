<?php

namespace MyCS\GrEpp;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * What a frame may show in a log: its secrets hidden (the login's password and new password, any authInfo or
 * extension pw: an EPP code, in a request or in a domain:info answer), and a one-line summary of an exchange (the
 * command, its clTRID, the registry's svTRID and result code: what the registry's helpdesk asks for).
 */
final class Trace
{
    /**
     * A frame with every pw / newPW element's text replaced, whatever its namespace or form (text, CDATA). A frame that
     * does not parse is never shown: it might hold a secret where no parser can find it.
     */
    public static function redact(#[\SensitiveParameter] string $xml): string
    {
        if (trim($xml) === '') {
            return '';
        }
        $document = self::document($xml);
        if ($document === null) {
            return '(not XML: ' . strlen($xml) . ' bytes, not shown)';
        }
        foreach (iterator_to_array((new DOMXPath($document))->query('//*[local-name()="pw" or local-name()="newPW"]') ?: []) as $secret) {
            if ($secret instanceof DOMElement && $secret->textContent !== '') {
                $secret->textContent = '***';
            }
        }

        return (string) $document->saveXML($document->documentElement);
    }

    /**
     * @return array{command: string, clTRID: ?string, svTRID: ?string, code: ?int, http: int}
     */
    public static function summary(#[\SensitiveParameter] string $request, int $httpStatus, string $response): array
    {
        $command = 'unknown';
        $clTRID = null;
        $sent = self::document($request);
        if ($sent !== null) {
            $first = $sent->documentElement?->firstElementChild;
            if ($first?->localName === 'command') {
                $verb = $first->firstElementChild;
                $object = $verb?->firstElementChild;
                $name = (string) $verb?->localName;
                $command = $object instanceof DOMElement && $object->prefix !== '' ? "{$object->prefix}:{$name}" : $name;
                $clTRID = self::text(new DOMXPath($sent), '//*[local-name()="command"]/*[local-name()="clTRID"]');
            } elseif ($first instanceof DOMElement) {
                $command = $first->localName;
            }
        }
        $answer = $response !== '' ? self::document($response) : null;
        $xpath = $answer !== null ? new DOMXPath($answer) : null;
        $code = $xpath !== null ? self::text($xpath, '//*[local-name()="result"]/@code') : null;

        return [
            'command' => $command,
            'clTRID' => $clTRID,
            'svTRID' => $xpath !== null ? self::text($xpath, '//*[local-name()="svTRID"]') : null,
            'code' => $code !== null && ctype_digit($code) ? (int) $code : null,
            'http' => $httpStatus,
        ];
    }

    private static function document(string $xml): ?DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private static function text(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);
        $text = $node !== null ? trim((string) $node->textContent) : '';

        return $text === '' ? null : $text;
    }
}
