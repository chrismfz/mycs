<?php

namespace MyCS\Proxmox\Tests\Console;

use InvalidArgumentException;
use MyCS\Proxmox\Console\ConsoleTicket;
use PHPUnit\Framework\TestCase;

/** The console ticket MyCS signs and the relay verifies (docs/proxmox.md, «Σχέδιο: το relay στο cluster»). */
class ConsoleTicketTest extends TestCase
{
    private const KEY = 'a-random-key-of-at-least-32-chars-0123456789';

    private const CONSOLE = ['origin' => 'https://my.example.com', 'server_id' => 7, 'service_id' => 42, 'user_id' => 3, 'node' => 'vega', 'type' => 'qemu', 'vmid' => 101];

    public function test_a_signed_ticket_verifies_with_its_key_for_its_time_only(): void
    {
        $token = ConsoleTicket::sign(self::CONSOLE, self::KEY, 1000);

        $ticket = ConsoleTicket::verify($token, ['another-key-of-at-least-32-characters!!', self::KEY], 1010);
        $this->assertSame(self::CONSOLE + ['iat' => 1000, 'exp' => 1000 + ConsoleTicket::TTL], array_diff_key($ticket, ['jti' => 0]));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $ticket['jti']);
        $this->assertSame(['origin' => 'https://my.example.com', 'server_id' => 7], ConsoleTicket::peek($token));
        $this->assertNotNull(ConsoleTicket::verify($token, [self::KEY], 1000 + ConsoleTicket::TTL + ConsoleTicket::SKEW), 'within the skew');
        $this->assertNull(ConsoleTicket::verify($token, [self::KEY], 1000 + ConsoleTicket::TTL + ConsoleTicket::SKEW + 1), 'too late');
        $this->assertNull(ConsoleTicket::verify($token, [self::KEY], 1000 - ConsoleTicket::SKEW - 1), 'from the future');
        $this->assertNull(ConsoleTicket::verify($token, ['another-key-of-at-least-32-characters!!'], 1010), 'another key');
        $this->assertNull(ConsoleTicket::verify($token, [], 1010));
        $this->assertNotSame($ticket['jti'], ConsoleTicket::verify(ConsoleTicket::sign(self::CONSOLE, self::KEY, 1000), [self::KEY], 1010)['jti']);
    }

    public function test_a_changed_payload_or_signature_is_refused(): void
    {
        $token = ConsoleTicket::sign(self::CONSOLE, self::KEY, 1000);
        [$v, $payload, $signature] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['v'] = 102; // another VM
        $forged = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        $this->assertNull(ConsoleTicket::verify("{$v}.{$forged}.{$signature}", [self::KEY], 1010));
        $this->assertNull(ConsoleTicket::verify("{$v}.{$payload}." . strrev($signature), [self::KEY], 1010));
        $this->assertNull(ConsoleTicket::verify("v2.{$payload}.{$signature}", [self::KEY], 1010));
        $this->assertNull(ConsoleTicket::verify($token . 'x', [self::KEY], 1010));
        $this->assertNull(ConsoleTicket::verify(str_repeat('a', 5000), [self::KEY], 1010));
    }

    /** A ticket signed right, of fields not sound (a key leaked to a careless signer), is still refused. */
    public function test_fields_not_sound_are_refused_even_signed(): void
    {
        foreach ([['node' => '../../access'], ['type' => 'openvz'], ['vmid' => 7], ['origin' => 'javascript:alert(1)'], ['server_id' => -1]] as $bad) {
            $this->assertNull(ConsoleTicket::verify(ConsoleTicket::sign($bad + self::CONSOLE, self::KEY, 1000), [self::KEY], 1010), json_encode($bad));
        }
    }

    public function test_the_checks_signature_is_not_a_tickets(): void
    {
        $body = '{"ts":1000,"consoles":[]}';
        $signature = ConsoleTicket::checkSignature($body, self::KEY);

        $this->assertMatchesRegularExpression('/^sha256=[0-9a-f]{64}$/', $signature);
        $this->assertTrue(ConsoleTicket::checkSigned($body, $signature, ['short', self::KEY]));
        $this->assertFalse(ConsoleTicket::checkSigned($body . ' ', $signature, [self::KEY]));
        $this->assertFalse(ConsoleTicket::checkSigned($body, $signature, ['another-key-of-at-least-32-characters!!']));
        $this->assertNotSame(substr($signature, 7), hash_hmac('sha256', 'console-ticket|' . $body, self::KEY), 'the prefixes differ');
    }

    public function test_a_short_key_never_signs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConsoleTicket::sign(self::CONSOLE, 'short');
    }
}
