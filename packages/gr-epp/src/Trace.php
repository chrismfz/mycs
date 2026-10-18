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
     * A frame with every secret element's text replaced, whatever its namespace or form (text, CDATA): any element whose
     * name ends in «pw» (pw, newPW, currentPW, extdomain:pw: ADV-1 of #193). With $alsoHide (the secrets of the request
     * it answers), those values are hidden wherever they appear (a schema error quoting the code sent: ADV-2). A frame
     * that does not parse is never shown: it might hold a secret where no parser can find it.
     *
     * @param  list<string>  $alsoHide
     */
    public static function redact(#[\SensitiveParameter] string $xml, #[\SensitiveParameter] array $alsoHide = []): string
    {
        if (trim($xml) === '') {
            return '';
        }
        $document = self::document($xml);
        if ($document === null) {
            return '(not XML: ' . strlen($xml) . ' bytes, not shown)';
        }
        foreach (self::secretElements($document) as $secret) {
            if ($secret->textContent !== '') {
                $secret->textContent = '***';
            }
        }
        $shown = (string) $document->saveXML($document->documentElement);
        foreach ($alsoHide as $value) {
            // Short values would hide ordinary words: a code is 6 to 16 characters, a password more.
            if (mb_strlen($value) >= 4) {
                $shown = str_replace([$value, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES), htmlspecialchars($value, ENT_XML1 | ENT_NOQUOTES)], '***', $shown);
            }
        }

        return $shown;
    }

    /**
     * The secret values a frame carries (its pw elements' texts), to hide them in its answer too.
     *
     * @return list<string>
     */
    public static function secrets(#[\SensitiveParameter] string $xml): array
    {
        $document = self::document($xml);
        if ($document === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(fn (DOMElement $e) => $e->textContent, self::secretElements($document)), fn (string $v) => $v !== '')));
    }

    /** @return list<DOMElement> */
    private static function secretElements(DOMDocument $document): array
    {
        $found = [];
        foreach ((new DOMXPath($document))->query('//*') ?: [] as $element) {
            if ($element instanceof DOMElement && preg_match('/pw\z/i', $element->localName) === 1) {
                $found[] = $element;
            }
        }

        return $found;
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
