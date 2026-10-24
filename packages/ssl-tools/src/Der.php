<?php

namespace MyCS\SslTools;

/**
 * Just enough DER to read a CSR's requested names: PHP's openssl reads a CSR's subject and key, never its extensions.
 * Every read is bounds-checked; anything malformed is a SslToolsException.
 *
 * @internal
 */
final class Der
{
    public const SEQUENCE = 0x30;

    public const SET = 0x31;

    public const OID = 0x06;

    public const OCTET_STRING = 0x04;

    public const BOOLEAN = 0x01;

    /**
     * The element at $offset: its tag, where its content starts, and where it ends.
     *
     * @return array{tag: int, start: int, end: int}
     */
    public static function read(string $der, int $offset, ?int $limit = null): array
    {
        $limit ??= strlen($der);
        if ($offset + 2 > $limit) {
            throw SslToolsException::because('The request is malformed.');
        }
        $tag = ord($der[$offset]);
        $length = ord($der[$offset + 1]);
        $start = $offset + 2;
        if ($length & 0x80) {
            $bytes = $length & 0x7F;
            if ($bytes === 0 || $bytes > 4 || $start + $bytes > $limit) {
                throw SslToolsException::because('The request is malformed.');
            }
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | ord($der[$start + $i]);
            }
            $start += $bytes;
        }
        if ($start + $length > $limit) {
            throw SslToolsException::because('The request is malformed.');
        }

        return ['tag' => $tag, 'start' => $start, 'end' => $start + $length];
    }

    /**
     * The elements inside a constructed element, in order.
     *
     * @param  array{tag: int, start: int, end: int}  $parent
     * @return list<array{tag: int, start: int, end: int}>
     */
    public static function children(string $der, array $parent): array
    {
        $children = [];
        for ($offset = $parent['start']; $offset < $parent['end'];) {
            $child = self::read($der, $offset, $parent['end']);
            $children[] = $child;
            $offset = $child['end'];
            if (count($children) > 1000) {
                throw SslToolsException::because('The request is malformed.');
            }
        }

        return $children;
    }

    /** @param array{tag: int, start: int, end: int} $element */
    public static function content(string $der, array $element): string
    {
        return substr($der, $element['start'], $element['end'] - $element['start']);
    }

    /** An OID's content (DER) as dotted text. */
    public static function oid(string $content): string
    {
        if ($content === '') {
            throw SslToolsException::because('The request is malformed.');
        }
        $first = ord($content[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $value = 0;
        for ($i = 1, $n = strlen($content); $i < $n; $i++) {
            $byte = ord($content[$i]);
            $value = ($value << 7) | ($byte & 0x7F);
            if (!($byte & 0x80)) {
                $parts[] = $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }
}
