<?php

namespace MyCS\SslTools;

use RuntimeException;

/**
 * A key, CSR or certificate not made or not read. Its message is one of this package's own, fixed sentences: never the
 * input, never OpenSSL's error text (which may quote it), so it is safe in a log or on a page.
 */
final class SslToolsException extends RuntimeException
{
    /** Reads OpenSSL's error queue empty, so a later call never reports an earlier error (nor keeps its text around). */
    public static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // drained
        }
    }

    public static function because(string $message): self
    {
        self::clearOpensslErrors();

        return new self($message);
    }
}
