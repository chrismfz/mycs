<?php

namespace MyCS\SslTools;

/** One line of SslCheck's result: what was checked (a stable code), how it went, and its plain values to show. */
final class CheckResult
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const INFO = 'info';

    /** @param array<string, string> $values */
    public function __construct(
        public readonly string $code,
        public readonly string $status,
        public readonly array $values = [],
    ) {}

    /** @return array{code: string, status: string, values: array<string, string>} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'status' => $this->status, 'values' => $this->values];
    }
}
