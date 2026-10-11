<?php

namespace MyCS\MailTemplate;

/**
 * Markup the application already made safe (e.g. a ticket message run through
 * a stripping Markdown converter). The renderer outputs it as is, never escaped.
 */
final class Html implements \Stringable
{
    public function __construct(private readonly string $html) {}

    public function __toString(): string
    {
        return $this->html;
    }
}
