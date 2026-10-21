<?php

namespace MyCS\Cnic\Tests;

use MyCS\Cnic\Command;
use MyCS\Cnic\Response;
use PHPUnit\Framework\TestCase;

final class WireTest extends TestCase
{
    public function test_a_command_is_key_value_lines_lists_numbered_empties_left_out(): void
    {
        $form = Command::form('reseller', 'S3cret', ['COMMAND' => 'ModifyDomain', 'domain' => 'example.com',
            'NAMESERVER' => ['ns1.example.net', 'ns2.example.net'], 'TRANSFERLOCK' => false, 'AUTH' => '', 'X' => null]);

        $this->assertSame(['s_login' => 'reseller', 's_pw' => 'S3cret',
            's_command' => "COMMAND=ModifyDomain\nDOMAIN=example.com\nNAMESERVER0=ns1.example.net\nNAMESERVER1=ns2.example.net\nTRANSFERLOCK=0"], $form);
    }

    public function test_a_line_break_in_a_value_never_makes_another_key(): void
    {
        $this->assertSame("COMMAND=StatusContact\nCONTACT=P-ABC123COMMAND=DeleteDomain",
            Command::lines(['COMMAND' => 'StatusContact', 'CONTACT' => "P-ABC123\nCOMMAND=DeleteDomain"]));
    }

    public function test_an_answer_reads_its_code_description_and_properties(): void
    {
        $r = Response::parse("[RESPONSE]\r\ncode = 200\r\ndescription = Command completed successfully\r\n"
            . "property[nameserver][0] = ns1.example.net\r\nproperty[NAMESERVER][1] = ns2.example.net\r\n"
            . "property[REGISTRATION EXPIRATION DATE][0] = 2027-11-01 10:00:00.0\r\nproperty[TRANSFER_LOCK][0] = 1\r\nEOF\r\n");

        $this->assertTrue($r->success());
        $this->assertSame(200, $r->code);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $r->list('nameserver'));
        $this->assertSame('2027-11-01 10:00:00.0', $r->first('REGISTRATION_EXPIRATION_DATE'), 'spaces and underscores are the same name');
        $this->assertSame('1', $r->first('transfer_lock'));
        $this->assertNull($r->first('AUTH'));
    }

    public function test_its_codes_say_done_try_later_refused_not_found_or_unknown(): void
    {
        $this->assertTrue(Response::parse("code = 421\ndescription = Temporary error")->temporary());
        $refused = Response::parse("code = 545\ndescription = Entity reference not found");
        $this->assertTrue($refused->refused());
        $this->assertTrue($refused->notFound());
        $this->assertSame('545 Entity reference not found', $refused->summary());
        $this->assertTrue(Response::parse('<html>Bad gateway</html>')->unknown());
        $this->assertFalse(Response::parse('')->success());
    }

    public function test_a_row_is_read_by_its_index_never_by_its_order(): void
    {
        $r = Response::parse("code = 200\nproperty[DOMAIN][1] = b.com\nproperty[DOMAIN][0] = a.com\nproperty[ROID][1] = ROID-B\n");

        $this->assertSame(['a.com', 'b.com'], $r->list('DOMAIN'));
        $this->assertSame([null, 'ROID-B'], [$r->at('ROID', 0), $r->at('ROID', 1)]);
    }

    public function test_its_live_names_with_spaces_are_its_documented_ones_with_underscores(): void
    {
        // As myip's account answers (2026-10-21): «PAIDUNTIL DATE», «TRANSFER LOCK», «OWNER CONTACT».
        $r = Response::parse("code = 200\nproperty[PAIDUNTIL DATE][0] = 2027-09-16 18:57:28.0\nproperty[TRANSFER LOCK][0] = 1\nproperty[OWNER CONTACT][0] = P-ABC1\n");

        $this->assertSame(['2027-09-16 18:57:28.0', '1', 'P-ABC1'], [$r->first('PAIDUNTIL_DATE'), $r->first('TRANSFER_LOCK'), $r->first('owner_contact')]);
    }

    public function test_a_hyphen_is_kept_so_names_that_differ_by_it_never_merge(): void
    {
        $r = Response::parse("code = 200\nproperty[ROID][0] = R-1\nproperty[X-DOMAIN-ROID][0] = X-1\nproperty[DOMAIN][0] = a.com\nproperty[DOMAIN IDN][0] = a.com\n");

        $this->assertSame(['R-1', 'X-1', ['a.com']], [$r->first('ROID'), $r->first('X-DOMAIN-ROID'), $r->list('DOMAIN')]);
    }
}
