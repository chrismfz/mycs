<?php

namespace MyCS\TicketMail;

use DateTimeImmutable;

/**
 * One inbound email as the router needs it, from its raw RFC 822 (see Parser). Every value came from the sender and
 * proves nothing by itself: the From only with our server's Authentication-Results (§3 Β), a thread only with one of our
 * Message-IDs (§3 Α).
 */
final class InboundEmail
{
    /**
     * @param  list<string>  $references  In-Reply-To first, then References, normalised
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $authenticationResults  top first
     * @param  array<string, list<string>>  $headers  the headers that tell automated mail, lower case names
     * @param  list<InboundAttachment>  $attachments  kept (within the limits of Attachments)
     * @param  list<array{name: string, reason: string}>  $refused  files not kept, and why
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly int $fromCount,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $references = [],
        public readonly array $to = [],
        public readonly array $cc = [],
        public readonly ?DateTimeImmutable $date = null,
        public readonly array $authenticationResults = [],
        public readonly array $headers = [],
        public readonly array $attachments = [],
        public readonly array $refused = [],
        public readonly bool $truncated = false,
        public readonly bool $tooLarge = false,
        public readonly int $size = 0,
    ) {}

    /** The first value of a header kept for automation checks, or null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** The From domain (lower case), or null when there is no usable address. */
    public function fromDomain(): ?string
    {
        return AuthenticationResults::domainOf($this->fromEmail);
    }
}
