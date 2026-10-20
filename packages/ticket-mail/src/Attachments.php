<?php

namespace MyCS\TicketMail;

use finfo;

/**
 * What a ticket keeps as a file (docs/tickets.md §3 ΣΤ, the limits of Ekdosi): documents, images and archives, never
 * active content (HTML, SVG, scripts, executables). The type is judged by the name AND by the bytes: a file whose
 * content is not what its extension says is refused. Stored under a name of ours, always served as a download.
 */
final class Attachments
{
    public const MAX_BYTES = 20 * 1024 * 1024; // a file

    public const MAX_COUNT = 5; // a message

    public const MAX_TOTAL_BYTES = 25 * 1024 * 1024; // an email

    /** Extension => the types its content may have (as libmagic tells). */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'txt' => ['text/plain'],
        'log' => ['text/plain'],
        'zip' => ['application/zip'],
    ];

    /** Images a page may show inline (never SVG). */
    public const PREVIEWABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** The type of the bytes, as libmagic tells (never what the sender declared). */
    public static function sniff(string $content): string
    {
        return strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($content)) ?: 'application/octet-stream';
    }

    /**
     * Why a file is refused, or null when it is kept: its type by name and bytes, its size.
     */
    public static function refusal(string $filename, string $content): ?string
    {
        $extension = self::extension($filename);
        if (!isset(self::TYPES[$extension])) {
            return 'type';
        }
        $size = strlen($content);
        if ($size === 0) {
            return 'empty';
        }
        if ($size > self::MAX_BYTES) {
            return 'size';
        }

        return in_array(self::sniff($content), self::TYPES[$extension], true) ? null : 'content';
    }

    /** The extension, lower case, of a name made safe. */
    public static function extension(string $filename): string
    {
        return strtolower(pathinfo(self::safeName($filename), PATHINFO_EXTENSION));
    }

    /** A name to show and to download as: no directories, no control characters, at most 200 characters (its extension kept). */
    public static function safeName(?string $name): string
    {
        $name = str_replace('\\', '/', trim((string) $name));
        $name = basename($name);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            return 'file';
        }
        if (mb_strlen($name) <= 200) {
            return $name;
        }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if ($extension === '' || mb_strlen($extension) > 10) {
            return mb_substr($name, 0, 200);
        }

        return mb_substr(mb_substr($name, 0, mb_strlen($name) - mb_strlen($extension) - 1), 0, 199 - mb_strlen($extension)) . '.' . $extension;
    }
}
