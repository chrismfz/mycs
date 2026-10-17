<?php

namespace MyCS\GrEpp\Command;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMNode;
use DOMXPath;
use Exception;
use MyCS\GrEpp\OutcomeUnknown;
use MyCS\GrEpp\Response;

/** Reading a response's data: texts, lists, dates (UTC), the registry's comments by reference. */
final class Read
{
    public static function text(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
    {
        $node = $xpath->query($query, $context)->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /** @return list<string> */
    public static function texts(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $texts = [];
        foreach ($xpath->query($query, $context) as $node) {
            $texts[] = trim($node->textContent);
        }

        return $texts;
    }

    /** @return list<string> the «s» attribute of each status element */
    public static function statuses(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $statuses = [];
        foreach ($xpath->query($query, $context) as $node) {
            if ($node instanceof DOMElement && $node->getAttribute('s') !== '') {
                $statuses[] = $node->getAttribute('s');
            }
        }

        return $statuses;
    }

    /** An EPP dateTime as UTC (the registry's expiry is 23:59:59.9Z of its day); null when absent. */
    public static function date(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DateTimeImmutable
    {
        $text = self::text($xpath, $query, $context);
        if ($text === null || $text === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new OutcomeUnknown("The registry gave a date that is not one: {$text}");
        }
    }

    /** @return array<string, string> the registry's comments by their «ref» (a name or id) */
    public static function comments(DOMXPath $xpath, string $query): array
    {
        $comments = [];
        foreach ($xpath->query($query) as $node) {
            if ($node instanceof DOMElement) {
                $comments[$node->getAttribute('ref')] = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            }
        }

        return $comments;
    }

    /** The response's data element, or OutcomeUnknown: a success without the data asked for is not an answer to it. */
    public static function required(Response $response, string $query): DOMElement
    {
        $node = $response->xpath()->query($query)->item(0);
        if (!$node instanceof DOMElement) {
            throw new OutcomeUnknown("The registry's answer has no {$query}.", $response->code);
        }

        return $node;
    }
}
