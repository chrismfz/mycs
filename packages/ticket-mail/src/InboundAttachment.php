<?php

namespace MyCS\TicketMail;

/** A file of an inbound email that passed Attachments: its safe name, the type of its bytes, the bytes. */
final class InboundAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly string $content,
    ) {}
}
