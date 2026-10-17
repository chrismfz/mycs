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

    /** xs:dateTime with a zone (Z or ±hh:mm): never a relative string, never read in PHP's default zone (review 9 of #185). */
    private const DATE_TIME = '/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    /** An EPP dateTime as UTC; null when absent. */
    public static function date(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DateTimeImmutable
    {
        $text = self::text($xpath, $query, $context);
        if ($text === null || $text === '') {
            return null;
        }
        if (preg_match(self::DATE_TIME, $text) !== 1) {
            throw new OutcomeUnknown("The registry gave a date that is not one: {$text}");
        }
        try {
            return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new OutcomeUnknown("The registry gave a date that is not one: {$text}");
        }
    }

    /**
     * The calendar date as the registry wrote it (Y-m-d, before any zone conversion: its expiry 2013-03-20T23:59:59.9Z is
     * the 20th; never shifted into the app's Europe/Athens, review 1 of #185).
     */
    public static function day(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
    {
        $text = self::text($xpath, $query, $context);
        if ($text === null || $text === '') {
            return null;
        }

        return preg_match(self::DATE_TIME, $text, $m) === 1 ? $m[1] : throw new OutcomeUnknown("The registry gave a date that is not one: {$text}");
    }

    /** xs:boolean: «true» or «1» (review 4 of #185). */
    public static function bool(?string $value): bool
    {
        return in_array(trim((string) $value), ['1', 'true'], true);
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
