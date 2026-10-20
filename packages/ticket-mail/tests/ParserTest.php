<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\Attachments;
use MyCS\TicketMail\Automation;
use MyCS\TicketMail\HtmlToText;
use MyCS\TicketMail\MessageId;
use MyCS\TicketMail\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function test_a_plain_mail(): void
    {
        $email = Parser::parse(Eml::make(['In-Reply-To' => '<tk.0123456789abcdef0123456789abcdef@myip.test>',
            'References' => '<a@x.test> <tk.0123456789abcdef0123456789abcdef@myip.test>', 'Cc' => 'Other <other@example.org>'], "Hi\r\nthere"));

        $this->assertSame(['m1@example.com', 'maria@example.com', 'Maria', 1, 'Help'], [$email->messageId, $email->fromEmail, $email->fromName, $email->fromCount, $email->subject]);
        $this->assertSame("Hi\nthere", $email->body);
        $this->assertSame(['tk.0123456789abcdef0123456789abcdef@myip.test', 'a@x.test'], $email->references);
        $this->assertSame(['other@example.org'], $email->cc);
    }

    public function test_two_froms_are_counted(): void
    {
        $this->assertSame(2, Parser::parse(Eml::make(['From' => 'victim@example.com, attacker@evil.test']))->fromCount);
        $this->assertSame(2, Parser::parse(Eml::make(['From' => 'victim@example.com', 'From: attacker@evil.test']))->fromCount);
    }

    public function test_html_only_mail_is_text_without_scripts_or_images(): void
    {
        $email = Parser::parse(Eml::make([], '', [], '<html><head><style>p{}</style></head><body><p>Hello <b>you</b></p><img src="https://tracker.test/p.gif"><script>alert(1)</script></body></html>'));

        $this->assertSame('Hello you', $email->body);
        $this->assertSame('Line', HtmlToText::convert('<!-- secret -->Line'));
    }

    public function test_files_are_kept_only_within_the_rules(): void
    {
        $email = Parser::parse(Eml::make([], 'See files', [
            ['name' => 'invoice.pdf', 'type' => 'application/pdf', 'content' => Eml::pdf()],
            ['name' => '../../etc/shot.png', 'content' => Eml::png()],
            ['name' => 'run.exe', 'content' => "MZ\x90\x00"],
            ['name' => 'page.html', 'type' => 'text/html', 'content' => '<script>x</script>'],
            ['name' => 'logo.svg', 'type' => 'image/svg+xml', 'content' => '<svg onload="x"/>'],
            ['name' => 'fake.pdf', 'type' => 'application/pdf', 'content' => '<html><script>x</script></html>'],
            ['name' => 'notes.txt', 'content' => '<html><body onload=x>'],
            ['name' => 'signature.png', 'content' => Eml::png(), 'inline' => 'logo@x'],
        ]));

        $this->assertSame(['invoice.pdf', 'shot.png'], array_map(fn ($file) => $file->filename, $email->attachments));
        $this->assertSame(['application/pdf', 'image/png'], array_map(fn ($file) => $file->mimeType, $email->attachments));
        $this->assertSame(['run.exe' => 'type', 'page.html' => 'type', 'logo.svg' => 'type', 'fake.pdf' => 'content', 'notes.txt' => 'content'],
            array_column($email->refused, 'reason', 'name'));
    }

    public function test_at_most_five_files(): void
    {
        $files = array_map(fn (int $i) => ['name' => "f{$i}.pdf", 'content' => Eml::pdf()], range(1, 7));
        $email = Parser::parse(Eml::make([], 'x', $files));

        $this->assertCount(Attachments::MAX_COUNT, $email->attachments);
        $this->assertSame(['count', 'count'], array_column($email->refused, 'reason'));
    }

    public function test_a_long_body_is_cut_and_control_characters_go(): void
    {
        $email = Parser::parse(Eml::make([], str_repeat('a', Parser::MAX_BODY_CHARS + 10) . "\x00"));

        $this->assertTrue($email->truncated);
        $this->assertSame(Parser::MAX_BODY_CHARS, mb_strlen($email->body));
        $this->assertSame("ab\tc", Parser::parse(Eml::make([], "a\x00b\tc\x07"))->body);
    }

    public function test_headers_only_for_a_giant(): void
    {
        $email = Parser::headersOnly("From: maria@example.com\r\nSubject: Big\r\nMessage-ID: <big@x>", 50_000_000);

        $this->assertSame([true, 'maria@example.com', 'big@x', '', 50_000_000], [$email->tooLarge, $email->fromEmail, $email->messageId, $email->body, $email->size]);
    }

    public function test_message_ids(): void
    {
        $id = MessageId::generate('MyIP.test');
        $this->assertMatchesRegularExpression('/^tk\.[0-9a-f]{32}@myip\.test$/', $id);
        $this->assertTrue(MessageId::isOurs($id));
        $this->assertNotSame($id, MessageId::generate('myip.test'));
        foreach (['42@myip.test', 'tk.123@myip.test', 'tk.0123456789abcdef0123456789abcdeg@x.test', '<' . $id . '>'] as $notOurs) {
            $this->assertFalse(MessageId::isOurs($notOurs), $notOurs);
        }
        $this->assertSame(['a@b', 'c@d'], MessageId::listFrom(" <a@b>\r\n\t<c@d> <a@b>"));
        $this->assertSame(['a@b', 'c@d'], MessageId::listFrom('a@b, c@d'));
        $this->assertNull(MessageId::normalise('<>'));
    }

    /** @return iterable<string, array{0: array<int|string, string>, 1: ?string}> */
    public static function automated(): iterable
    {
        yield 'a person' => [[], null];
        yield 'Auto-Submitted: no' => [['Auto-Submitted' => 'no'], null];
        yield 'auto-replied' => [['Auto-Submitted' => 'auto-replied'], 'auto_submitted'];
        yield 'auto-generated' => [['Auto-Submitted' => 'auto-generated'], 'auto_submitted'];
        yield 'bulk' => [['Precedence' => 'bulk'], 'precedence'];
        yield 'list precedence' => [['Precedence' => 'list'], 'precedence'];
        yield 'X-Autoreply' => [['X-Autoreply' => 'yes'], 'auto_reply'];
        yield 'Exchange OOF' => [['X-Auto-Response-Suppress' => 'All'], 'auto_reply'];
        yield 'a list' => [['List-Id' => '<news.example.com>'], 'list'];
        yield 'a newsletter' => [['List-Unsubscribe' => '<mailto:u@example.com>'], 'list'];
        yield 'a bounce' => [['Return-Path' => '<>'], 'null_sender'];
        yield 'our own, come back' => [['X-MyCS-Ticket' => '123456'], 'loop'];
        yield 'mailer-daemon' => [['From' => 'MAILER-DAEMON@mx.example.com'], 'system_sender'];
        yield 'no-reply' => [['From' => 'no-reply@shop.example'], 'system_sender'];
        yield 'noreply+tag' => [['From' => 'noreply+x@shop.example'], 'system_sender'];
    }

    /** @param  array<int|string, string>  $headers */
    #[DataProvider('automated')]
    public function test_automated_mail_is_told(array $headers, ?string $reason): void
    {
        $this->assertSame($reason, Automation::reason(Parser::parse(Eml::make($headers))));
    }

    public function test_a_delivery_report_is_automated(): void
    {
        $raw = "From: x@example.com\r\nMessage-ID: <r@x>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"z\"\r\n\r\n--z\r\nContent-Type: text/plain\r\n\r\nfailed\r\n--z--\r\n";

        $this->assertSame('report', Automation::reason(Parser::parse($raw)));
    }
}
