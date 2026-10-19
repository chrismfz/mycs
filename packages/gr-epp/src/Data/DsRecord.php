<?php

namespace MyCS\GrEpp\Data;

/**
 * A DS record of a domain (secDNS-1.1's DS Data Interface, RFC 5910 and RFC 4034): the only form the .gr registry takes
 * (no Key Data, no maxSigLife). Its digest upper-case hex.
 */
final readonly class DsRecord
{
    public function __construct(
        public int $keyTag,
        public int $algorithm,
        public int $digestType,
        public string $digest,
    ) {}

    /** The same record, whatever the digest's case. */
    public function same(self $other): bool
    {
        return $this->keyTag === $other->keyTag && $this->algorithm === $other->algorithm && $this->digestType === $other->digestType
            && strcasecmp($this->digest, $other->digest) === 0;
    }
}
