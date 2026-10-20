<?php

namespace MyCS\TicketMail;

use DateTimeImmutable;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Header\Part\AddressPart;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Raw RFC 822 → InboundEmail. One parser for every source (IMAP, a quarantined copy, the tests' .eml), so what is tested
 * is what runs. Bounded: a body is cut at MAX_BODY_CHARS, files are kept only within Attachments; a message over the
 * transport's cap arrives as headers only (headersOnly()) and is never kept as a ticket.
 */
final class Parser
{
    public const MAX_BODY_CHARS = 60_000;

    /** The headers that tell automated mail (§3 Ε), kept as they are. */
    private const KEPT_HEADERS = ['auto-submitted', 'precedence', 'x-autoreply', 'x-autorespond',
        'list-id', 'list-unsubscribe', 'return-path', 'content-type', 'x-loop', 'feedback-id', 'x-mycs-ticket'];

    public static function parse(string $raw): InboundEmail
    {
        $message = Message::from($raw, false);
        [$text, $truncated] = self::body($message);
        [$kept, $refused] = self::attachments($message);

        return self::build($message, $text, $truncated, $kept, $refused, false, strlen($raw));
    }

    /** A message too large to fetch: its headers only, never a ticket (the router quarantines it). */
    public static function headersOnly(string $rawHeaders, int $size): InboundEmail
    {
        $message = Message::from(rtrim($rawHeaders) . "\r\n\r\n", false);

        return self::build($message, '', false, [], [], true, $size);
    }

    /**
     * @param  list<InboundAttachment>  $kept
     * @param  list<array{name: string, reason: string}>  $refused
     */
    private static function build(IMessage $message, string $text, bool $truncated, array $kept, array $refused, bool $tooLarge, int $size): InboundEmail
    {
        $froms = [];
        foreach ($message->getAllHeadersByName('From') as $header) {
            if ($header instanceof AddressHeader) {
                foreach ($header->getAddresses() as $address) {
                    $froms[] = $address;
                }
            }
        }
        $from = $froms[0] ?? null;

        $headers = [];
        foreach (self::KEPT_HEADERS as $name) {
            foreach ($message->getAllHeadersByName($name) as $header) {
                $headers[$name][] = trim($header->getRawValue());
            }
        }

        $references = array_merge(
            MessageId::listFrom($message->getHeader('In-Reply-To')?->getRawValue()),
            MessageId::listFrom($message->getHeader('References')?->getRawValue()),
        );

        return new InboundEmail(
            messageId: MessageId::normalise($message->getHeader('Message-ID')?->getRawValue()),
            fromEmail: $from instanceof AddressPart ? strtolower(trim($from->getEmail())) : '',
            fromName: $from instanceof AddressPart && self::line((string) $from->getName()) !== '' ? mb_substr(self::line((string) $from->getName()), 0, 191) : null,
            fromCount: count($froms),
            subject: mb_substr(self::line((string) $message->getSubject()), 0, 250),
            body: $text,
            references: array_values(array_unique($references)),
            to: self::addresses($message, 'To'),
            cc: self::addresses($message, 'Cc'),
            date: self::date($message),
            authenticationResults: array_map(fn ($header) => trim($header->getRawValue()), $message->getAllHeadersByName('Authentication-Results')),
            headers: $headers,
            attachments: $kept,
            refused: $refused,
            truncated: $truncated,
            tooLarge: $tooLarge,
            size: $size,
        );
    }

    /** @return array{0: string, 1: bool} the text (the plain part, or the HTML one as text) and whether it was cut */
    private static function body(IMessage $message): array
    {
        $text = trim((string) $message->getTextContent());
        if ($text === '') {
            $text = HtmlToText::convert((string) $message->getHtmlContent());
        }
        // A charset mbstring does not know (unknown-8bit, a typo) leaves bytes that are no UTF-8: kept as «?», never lost.
        $text = str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'));
        // Never a NUL or another control character in what staff read (tabs and line breaks stay).
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if (mb_strlen($text) <= self::MAX_BODY_CHARS) {
            return [$text, false];
        }

        return [mb_substr($text, 0, self::MAX_BODY_CHARS), true];
    }

    /** @return array{0: list<InboundAttachment>, 1: list<array{name: string, reason: string}>} */
    private static function attachments(IMessage $message): array
    {
        $kept = [];
        $refused = [];
        $total = 0;
        // Files the sender attached first, then pictures pasted into the text (inline, with a Content-ID): a screenshot
        // of the error is often all there is, so it is kept too, after them (a signature's logo never pushes a file out).
        $parts = $message->getAllAttachmentParts();
        $inline = fn ($part) => strtolower((string) $part->getContentDisposition()) === 'inline' && $part->getContentId() !== null;
        $parts = array_merge(array_filter($parts, fn ($part) => !$inline($part)), array_filter($parts, $inline));
        foreach ($parts as $part) {
            /** @var IMessagePart $part */
            $name = Attachments::safeName($part->getFilename() ?? '');
            // A forwarded mail (message/rfc822) is a file of its own: kept as .eml.
            if (strtolower($part->getContentType()) === 'message/rfc822' && Attachments::extension($name) !== 'eml') {
                $name = Attachments::safeName(($part->getFilename() ? pathinfo($name, PATHINFO_FILENAME) : 'forwarded') . '.eml');
            }
            if (count($kept) >= Attachments::MAX_COUNT) {
                $refused[] = ['name' => $name, 'reason' => 'count'];

                continue;
            }
            // Refused by its name before its bytes are read.
            if (!isset(Attachments::TYPES[Attachments::extension($name)])) {
                $refused[] = ['name' => $name, 'reason' => 'type'];

                continue;
            }
            try {
                $content = (string) $part->getBinaryContentStream()?->getContents();
            } catch (Throwable) {
                $refused[] = ['name' => $name, 'reason' => 'unreadable'];

                continue;
            }
            $reason = Attachments::refusal($name, $content);
            if ($reason === null && $total + strlen($content) > Attachments::MAX_TOTAL_BYTES) {
                $reason = 'total';
            }
            if ($reason !== null) {
                $refused[] = ['name' => $name, 'reason' => $reason];

                continue;
            }
            $total += strlen($content);
            $kept[] = new InboundAttachment($name, Attachments::sniff($content), $content);
        }

        return [$kept, $refused];
    }

    /** @return list<string> */
    private static function addresses(IMessage $message, string $header): array
    {
        $out = [];
        foreach ($message->getAllHeadersByName($header) as $value) {
            if ($value instanceof AddressHeader) {
                foreach ($value->getAddresses() as $address) {
                    $email = strtolower(trim($address->getEmail()));
                    if ($email !== '') {
                        $out[] = $email;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function date(IMessage $message): ?DateTimeImmutable
    {
        $header = $message->getHeader('Date');
        $date = $header instanceof DateHeader ? $header->getDateTimeImmutable() : null;

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    /** A header value as one line: valid UTF-8, no control characters, single spaces. */
    private static function line(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($value, 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
