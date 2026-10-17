<?php

namespace MyCS\GrEpp\Data;

/** One name (or contact id, host) of a check: free or not, the registry's reason and comment (in the session's language). */
final readonly class CheckResult
{
    public function __construct(
        public string $name,
        public bool $available,
        public ?string $reason = null,
        public ?string $comment = null,
    ) {}
}
