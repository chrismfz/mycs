<?php

namespace MyCS\GrEpp\Data;

/**
 * One name (or contact id, host) of a check: free or not, the registry's reason and comment (in the session's language).
 * «restricted»: not free as it is, but registrable with the regulator's approval (geographic terms, a .gov.gr name's
 * second level: «Διαθέσιμο υπό περιορισμούς»), never shown as taken (review 2 of #185; the English wording to confirm live).
 */
final readonly class CheckResult
{
    /** The registry's reason for a name available under restrictions (el; en: to confirm on the first live check). */
    public const RESTRICTED_REASONS = ['/υπό\s+περιορισμ/iu', '/restrict/i'];

    public bool $restricted;

    public function __construct(
        public string $name,
        public bool $available,
        public ?string $reason = null,
        public ?string $comment = null,
    ) {
        $this->restricted = !$available && $reason !== null
            && array_filter(self::RESTRICTED_REASONS, fn (string $pattern) => preg_match($pattern, $reason) === 1) !== [];
    }
}
