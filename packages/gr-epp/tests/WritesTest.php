<?php

namespace MyCS\GrEpp\Tests;

use InvalidArgumentException;
use MyCS\GrEpp\Command\ContactCreate;
use MyCS\GrEpp\Command\ContactUpdate;
use MyCS\GrEpp\Command\DacorIssueToken;
use MyCS\GrEpp\Command\DomainCreate;
use MyCS\GrEpp\Command\DomainDelete;
use MyCS\GrEpp\Command\DomainRenew;
use MyCS\GrEpp\Command\DomainTransfer;
use MyCS\GrEpp\Command\DomainUpdate;
use MyCS\GrEpp\Command\HostCreate;
use MyCS\GrEpp\Command\HostDelete;
use MyCS\GrEpp\Command\HostUpdate;
use MyCS\GrEpp\Command\OwnerChange;
use MyCS\GrEpp\Command\Rules;
use MyCS\GrEpp\Config;
use MyCS\GrEpp\Data\ContactChanges;
use MyCS\GrEpp\Data\ContactDraft;
use MyCS\GrEpp\Data\PostalInfo;
use MyCS\GrEpp\Refused;
use MyCS\GrEpp\Response;
use MyCS\GrEpp\Session;
use PHPUnit\Framework\TestCase;

/**
 * G2b: the registry's writes (contacts, hosts, domain create/update/renew/transfer/delete/recall, owner changes,
 * DACoR). Each request valid to the registry's schemas and its rules checked before sending (contact ids, E.164 phones,
 * Greek and Latin text, even years 2–10, no empty update, glue only under .gr); each of the guide's answers read.
 */
final class WritesTest extends TestCase
{
    private static function contact(): ContactDraft
    {
        return new ContactDraft('c60_q1pcBkTq',
            new PostalInfo('loc', 'Χρήστος Παναγιωτάκης', 'MyIP', ['Οδός 1', 'Ισόγειο'], 'Αθήνα', 'Αττική', '10000', 'GR'),
            new PostalInfo('int', 'Christos Panagiotakis', 'MyIP', ['Odos 1'], 'Athina', 'Attiki', '10000', 'GR'),
            '+30.2100000000', 'owner@example.test', '12', '+30.2100000001');
    }

    private static function fixture(string $name): Response
    {
        return Response::parse((string) file_get_contents(__DIR__ . "/fixtures/v4.3/{$name}.xml"));
    }

    public function test_every_write_is_valid_to_the_registrys_schemas(): void
    {
        foreach ([
            ContactCreate::request(self::contact(), 'mycs-1'),
            ContactUpdate::request('c60_q1pcBkTq', new ContactChanges(localAddress: ['street' => ['Νέα οδός 2'], 'city' => 'Πάτρα', 'province' => 'Αχαΐα', 'postcode' => '26221', 'country' => 'gr'],
                voice: '+30.6900000000', voiceExtension: '5', email: 'new@example.test'), 'mycs-1'),
            ContactUpdate::request('c60_q1pcBkTq', new ContactChanges(fax: ''), 'mycs-1'),
            HostCreate::request('ns1.myip.gr', ['192.0.2.1', '2001:db8::1'], 'mycs-1'),
            HostCreate::request('ns1.example.com', [], 'mycs-1'),
            HostUpdate::request('ns1.myip.gr', ['192.0.2.2'], ['192.0.2.1'], 'mycs-1'),
            HostUpdate::request('ns1.myip.gr', [], [], 'mycs-1', 'ns3.myip.gr'),
            HostDelete::request('ns1.myip.gr', 'mycs-1'),
            DomainCreate::request('cdnet.gr', 2, ['ns1.myip.gr', 'NS2.myip.gr'], 'c60_q1pcBkTq', ['admin' => ['c60_a1b2c'], 'tech' => ['c60_a1b2c']], 'mycs-1'),
            DomainCreate::request('ελληνικό.gr', 10, [], 'c60_q1pcBkTq', [], 'mycs-1'),
            DomainCreate::activateForm('ιτέ.gr', 'Auth-code1', 'mycs-1'),
            DomainUpdate::request('cdnet.gr', 'mycs-1', ['ns3.myip.gr'], ['ns1.myip.gr'], ['tech' => ['c60_new01']], ['tech' => ['ics_old01']]),
            OwnerChange::request('cdnet.gr', 'c60_q1pcBkTq', OwnerChange::NAME_CHANGE, 'mycs-1'),
            OwnerChange::request('cdnet.gr', 'c60_other1', OwnerChange::OWNER_CHANGE, 'mycs-1'),
            DomainRenew::request('cdnet.gr', '2026-11-01', 2, 'mycs-1'),
            DomainRenew::request('cdnet.gr', '2026-10-22', 4, 'mycs-1', 'c60_q1pcBkTq', 'Auth-code1'),
            DomainTransfer::request('nac.gr', 'Auth-code1', 'c60_newowner', 'mycs-1'),
            DomainDelete::delete('cdnet.gr', 'Auth-code1', 'mycs-1'),
            DomainDelete::delete('ιτέ.gr', 'Auth-code1', 'mycs-1', homograph: true),
            DomainDelete::recall('cdnet.gr', '66155', 'mycs-1'),
            DacorIssueToken::request('cdnet.gr', 'mycs-1'),
        ] as $xml) {
            QueriesTest::assertValid($xml);
        }
    }

    public function test_the_registrys_write_examples_pass_the_same_check(): void
    {
        foreach (['13-contact-create-request', '15-contact-update-request', '23-host-create-request', '25-host-update-request',
            '27-host-delete-request', '37-domain-create-request', '40-domain-create-homograph-request', '42-domain-update-request',
            '44-domain-update-owner-name-change-request', '46-domain-update-owner-change-request', '51-domain-renew-request',
            '53-domain-renew-expired-by-different-registrar-request', '55-domain-delete-request', '57-domain-delete-homogragh-request',
            '59-domain-delete-recall-domain-creation-request', '61-domain-transfer-request', 'dacor-issue-token'] as $name) {
            QueriesTest::assertValid((string) file_get_contents(__DIR__ . "/fixtures/v4.3/{$name}.xml"));
        }
        $this->addToAssertionCount(1);
    }

    public function test_requests_are_built_as_the_registry_asks(): void
    {
        $contact = ContactCreate::request(self::contact(), 'mycs-1');
        $this->assertStringContainsString('<contact:pw/>', $contact, 'an empty pw (else 2308)');
        $this->assertStringContainsString('<contact:disclose flag="0">', $contact);
        $this->assertStringContainsString('<contact:voice x="12">+30.2100000000</contact:voice>', $contact);
        $this->assertStringContainsString('<contact:cc>gr</contact:cc>', $contact);

        $update = ContactUpdate::request('c60_q1pcBkTq', new ContactChanges(email: 'new@example.test'), 'mycs-1');
        $this->assertStringNotContainsString('contact:name', $update, 'never the name (owner 2026-10-18)');
        $this->assertStringNotContainsString('authInfo', $update, 'never an authInfo (2308)');

        $create = DomainCreate::request('cdnet.gr', 2, ['NS1.myip.gr', 'ns1.myip.gr'], 'c60_q1pcBkTq', [], 'mycs-1');
        $this->assertSame(1, substr_count($create, '<domain:hostObj>ns1.myip.gr</domain:hostObj>'));
        $this->assertStringContainsString('<domain:period unit="y">2</domain:period>', $create);
        $this->assertStringContainsString('<domain:pw/>', $create);

        $this->assertStringContainsString('<transfer op="request">', DomainTransfer::request('nac.gr', 'Auth-code1', 'c60_newowner', 'mycs-1'));
        $this->assertStringContainsString('<extdomain:op>recallApplication</extdomain:op>', DomainDelete::recall('cdnet.gr', '66155', 'mycs-1'));
        $this->assertStringContainsString('<dacor:issueToken', DacorIssueToken::request('cdnet.gr', 'mycs-1'));
    }

    public function test_the_registrys_answers_are_read(): void
    {
        $create = DomainCreate::parse(self::fixture('38-domain-create-response'));
        $this->assertSame([1001, true, 'forth.gr', '66155', '2013-03-19'], [$create->code, $create->pending(), $create->name, $create->protocol, $create->expiresOn]);
        $this->assertSame('Αριθμός Πρωτοκόλλου Αίτησης 66155: Προς Έγκριση. Όνομα Χώρου Δημιουργήθηκε.', $create->comments[0]);

        $renew = DomainRenew::parse(self::fixture('52-domain-renew-response'));
        $this->assertSame(['forth.gr', '2018-03-19', false], [$renew->name, $renew->expiresOn, $renew->pending()]);

        $transfer = DomainTransfer::parse(self::fixture('62-domain-transfer-response'));
        $this->assertSame(['forth.gr', 'serverApproved'], [$transfer->name, $transfer->transferStatus]);

        $contact = ContactCreate::parse(self::fixture('14-contact-create-response'));
        $this->assertSame(1000, $contact->code);

        $this->assertTrue(OwnerChange::parse(self::fixture('48-domain-update-owner-change-action-pending-response'))->pending(), 'awaiting EETT');

        $dacor = DacorIssueToken::parse(self::fixture('dacor-issue-token-response'));
        $this->assertStringContainsString('a***@b***.tld', $dacor->comments[0]);

        foreach (['16-contact-update-response', '24-host-create-response', '26-host-update-response', '28-host-delete-response',
            '43-domain-update-response', '45-domain-update-owner-name-change-response', '47-domain-update-owner-change-response',
            '56-domain-delete-response', '58-domain-delete-homograph-response', '60-domain-delete-recall-domain-creation-response',
            '41-domain-create-homograph-response'] as $answer) {
            $this->assertTrue(self::fixture($answer)->isSuccess(), $answer);
            $this->assertNotNull(DomainUpdate::parse(self::fixture($answer))->code);
        }
    }

    public function test_review_fixes(): void
    {
        // 1: a transfer's exDate is the moment of the transfer, never taken as the expiry.
        $transfer = DomainTransfer::parse(self::fixture('62-domain-transfer-response'));
        $this->assertSame(['serverApproved', null, null], [$transfer->transferStatus, $transfer->expires, $transfer->expiresOn]);

        // 6: a refusal keeps the registry's reasons.
        $transport = (new FakeTransport)->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml')
            ->fixture('38.1-domain-create-response-when-domain-is-already-registered.xml');
        try {
            (new Session(new Config('myip1', 'Secret-pw1'), $transport))
                ->command(DomainCreate::request('forth.gr', 2, [], 'c60_q1pcBkTq', [], 'mycs-1'));
            $this->fail('refused');
        } catch (Refused $e) {
            $this->assertSame(2302, $e->resultCode);
            $this->assertNotSame([], $e->comments);
        }

        // 7: decomposed Greek is the same text, composed; names lower case, NFC, no trailing dot.
        $this->assertSame('Ελληνικά', Rules::text(\Normalizer::normalize('Ελληνικά', \Normalizer::FORM_D), 'name'));
        $this->assertStringContainsString('<domain:name>ελληνικό.gr</domain:name>',
            DomainCreate::request('ΕΛΛΗΝΙΚΌ.gr.', 2, ['NS1.MYIP.GR.'], 'c60_q1pcBkTq', [], 'mycs-1'));

        // 9: removing old glue from an external host is allowed.
        QueriesTest::assertValid(HostUpdate::request('ns1.example.com', [], ['192.0.2.9'], 'mycs-1'));
        $this->assertStringContainsString('<domain:contact type="tech">ics_old01</domain:contact>',
            DomainUpdate::request('a.gr', 'mycs-1', removeContacts: ['tech' => ['ics_old01']]));
    }

    public function test_review_refusals(): void
    {
        $int = fn (string $street) => new ContactDraft('c60_abcde', self::contact()->local,
            new PostalInfo('int', 'Christos', null, [$street], 'Athina', 'Attiki', '10000', 'GR'), '+30.2100000000', 'a@example.test');
        $refused = [
            // 2: Latin postal info ASCII only; the slots' types.
            'Greek in int' => fn () => ContactCreate::request($int('Οδός 1'), 'mycs-1'),
            'an accent in int' => fn () => ContactCreate::request($int('Café 1'), 'mycs-1'),
            'swapped types' => fn () => ContactCreate::request(new ContactDraft('c60_abcde', self::contact()->international, null, '+30.2100000000', 'a@example.test'), 'mycs-1'),
            // 3: the Greek address's required fields; 1 to 3 streets, never cut.
            'no postcode in loc' => fn () => ContactCreate::request(new ContactDraft('c60_abcde', new PostalInfo('loc', 'Χ', null, ['Οδός'], 'Αθήνα', 'Αττική', null, 'GR'), null, '+30.2100000000', 'a@example.test'), 'mycs-1'),
            'four streets' => fn () => ContactCreate::request(new ContactDraft('c60_abcde', new PostalInfo('loc', 'Χ', null, ['1', '2', '3', '4'], 'Αθήνα', 'Αττική', '10000', 'GR'), null, '+30.2100000000', 'a@example.test'), 'mycs-1'),
            'no street' => fn () => ContactCreate::request(new ContactDraft('c60_abcde', new PostalInfo('loc', 'Χ', null, [], 'Αθήνα', 'Αττική', '10000', 'GR'), null, '+30.2100000000', 'a@example.test'), 'mycs-1'),
            // 4: unknown roles.
            'an unknown role' => fn () => DomainUpdate::request('a.gr', 'mycs-1', addContacts: ['Tech' => ['c60_abcde']]),
            'a registrant as a role' => fn () => DomainCreate::request('a.gr', 2, [], 'c60_q1pcBkTq', ['registrant' => ['c60_abcde']], 'mycs-1'),
            // 9: a real day; an extension of digits; an email; one line.
            'no such day' => fn () => DomainRenew::request('a.gr', '2026-02-30', 2, 'mycs-1'),
            'an extension with letters' => fn () => ContactUpdate::request('c60_abcde', new ContactChanges(voice: '+30.2100000000', voiceExtension: '12a'), 'mycs-1'),
            'not an email' => fn () => ContactUpdate::request('c60_abcde', new ContactChanges(email: 'not-an-email'), 'mycs-1'),
            'a newline in a name' => fn () => Rules::text("Χρήστος\nΠ", 'name'),
            'an empty code on a renew' => fn () => DomainRenew::request('a.gr', '2026-11-01', 2, 'mycs-1', 'c60_q1pcBkTq', ' '),
        ];
        foreach ($refused as $case => $call) {
            try {
                $call();
                $this->fail("refused: {$case}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_registrys_rules_are_checked_before_sending(): void
    {
        $refused = [
            'a contact id without our form' => fn () => Rules::contactId('c60-abc'),
            'a contact id too long' => fn () => Rules::contactId('c60_abcdefghijklm'),
            'a contact id with «--»' => fn () => Rules::contactId('c60_ab--cd'),
            'a phone without the dot' => fn () => Rules::phone('+302100000000'),
            'a phone too long' => fn () => Rules::phone('+30.12345678901234'),
            'an emoji' => fn () => Rules::text('Χρήστος 🙂', 'name'),
            'Cyrillic' => fn () => Rules::text('Иван', 'name'),
            'a tab' => fn () => Rules::text("a\tb", 'street'),
            'a not sign (U+00AC)' => fn () => Rules::text('a¬b', 'street'),
            'odd years' => fn () => Rules::years(3),
            'one year' => fn () => DomainCreate::request('a.gr', 1, [], 'c60_q1pcBkTq', [], 'mycs-1'),
            'twelve years' => fn () => DomainRenew::request('a.gr', '2026-11-01', 12, 'mycs-1'),
            'an empty update' => fn () => DomainUpdate::request('a.gr', 'mycs-1'),
            'an empty contact update' => fn () => ContactUpdate::request('c60_q1pcBkTq', new ContactChanges, 'mycs-1'),
            'glue on an external host' => fn () => HostCreate::request('ns1.example.com', ['192.0.2.1'], 'mycs-1'),
            'not an IP' => fn () => HostCreate::request('ns1.myip.gr', ['999.1.1.1'], 'mycs-1'),
            'a renew with a datetime' => fn () => DomainRenew::request('a.gr', '2026-11-01T23:59:59Z', 2, 'mycs-1'),
            'another registrar\'s renew without its code' => fn () => DomainRenew::request('a.gr', '2026-11-01', 2, 'mycs-1', 'c60_q1pcBkTq'),
            'a recall without its protocol' => fn () => DomainDelete::recall('a.gr', ' ', 'mycs-1'),
            'a transfer without its code' => fn () => DomainTransfer::request('a.gr', '', 'c60_q1pcBkTq', 'mycs-1'),
            'not an owner change' => fn () => OwnerChange::request('a.gr', 'c60_q1pcBkTq', 'deleteDomain', 'mycs-1'),
        ];
        foreach ($refused as $case => $call) {
            try {
                $call();
                $this->fail("refused: {$case}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('Auth-code1', $e->getMessage());
            }
        }
        // What the registry takes.
        $this->assertSame(['c60_q1pcBkTq', 'c60_a-b', '+30.2100000000', 'Ελληνικά ἄλφα é ü', 4],
            [Rules::contactId('c60_q1pcBkTq'), Rules::contactId('c60_a-b'), Rules::phone('+30.2100000000'), Rules::text('Ελληνικά ἄλφα é ü', 'name'), Rules::years(4)]);
    }
}
