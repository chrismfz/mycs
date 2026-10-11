<?php

namespace Nixpal\CardlinkEurobank\Tests;

use Nixpal\CardlinkEurobank\OrderId;
use Nixpal\CardlinkEurobank\ReturnTicket;
use PHPUnit\Framework\TestCase;

final class ReturnTicketTest extends TestCase
{
    const ORDER = '42Inv26100912000007';

    public function test_round_trip()
    {
        $query = ReturnTicket::create(self::ORDER, 7, [['id' => 42, 'amount' => 1], ['id' => 43, 'amount' => '0.5']], 1.5, 'eur', 's');
        $ticket = ReturnTicket::read($query, self::ORDER, 's');

        $this->assertSame([
            'client_id' => 7,
            'invoices' => [['id' => 42, 'amount' => '1.00'], ['id' => 43, 'amount' => '0.50']],
            'amount' => '1.50',
            'currency' => 'EUR',
        ], $ticket);
    }

    public function test_survives_the_url()
    {
        $query = ReturnTicket::create(self::ORDER, 7, [['id' => 42, 'amount' => 1]], 1, 'EUR', 's');
        parse_str(parse_url('https://x/cb/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), PHP_URL_QUERY), $get);

        $this->assertNotNull(ReturnTicket::read($get, self::ORDER, 's'));
    }

    public function test_another_order_secret_or_edited_query_is_refused()
    {
        $query = ReturnTicket::create(self::ORDER, 7, [['id' => 42, 'amount' => 1]], 1, 'EUR', 's');

        $this->assertNull(ReturnTicket::read($query, '43Inv26100912000007', 's'));
        $this->assertNull(ReturnTicket::read($query, self::ORDER, 'other'));
        $this->assertNull(ReturnTicket::read($query, self::ORDER, ''));
        foreach (['c' => '8', 'i' => '42_1.00-44_9.00', 'a' => '100.00', 'u' => 'USD', 's' => 'x'] as $key => $value) {
            $edited = $query;
            $edited[$key] = $value;
            $this->assertNull(ReturnTicket::read($edited, self::ORDER, 's'), $key);
        }
        $missing = $query;
        unset($missing['i']);
        $this->assertNull(ReturnTicket::read($missing, self::ORDER, 's'));
        $array = $query;
        $array['c'] = ['7'];
        $this->assertNull(ReturnTicket::read($array, self::ORDER, 's'));
    }

    public function test_long_invoice_lists_are_cut_at_whole_entries()
    {
        $invoices = [];
        for ($i = 1; $i <= 30; $i++) {
            $invoices[] = ['id' => 100000 + $i, 'amount' => 10];
        }
        $serialized = ReturnTicket::serializeInvoices($invoices);

        $this->assertLessThanOrEqual(ReturnTicket::MAX_INVOICES_LENGTH, strlen($serialized));
        $parsed = ReturnTicket::parseInvoices($serialized);
        $this->assertNotEmpty($parsed);
        $this->assertSame(100001, $parsed[0]['id']);
        $this->assertSame('10.00', end($parsed)['amount']);
    }

    public function test_no_invoices_is_an_empty_list()
    {
        $query = ReturnTicket::create(self::ORDER, 7, [], 5, 'EUR', 's');

        $this->assertSame([], ReturnTicket::read($query, self::ORDER, 's')['invoices']);
    }

    public function test_order_ids()
    {
        $time = gmmktime(12, 0, 0, 10, 9, 2026);

        $this->assertSame('42Inv26100912000007', OrderId::generate(42, 7, $time, 7));
        $this->assertSame('7Cl26100912000099', OrderId::generate(null, 7, $time, 99));
        $this->assertTrue(OrderId::isValid(OrderId::generate(2147483647, 1, $time, 5)));
        $this->assertFalse(OrderId::isValid(str_repeat('1', 51)));
        $this->assertFalse(OrderId::isValid(42));
    }
}
