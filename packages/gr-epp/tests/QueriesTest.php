<?php

namespace MyCS\GrEpp\Tests;

use DOMDocument;
use DOMElement;
use MyCS\GrEpp\Command\AccountInfo;
use MyCS\GrEpp\Command\ContactCheck;
use MyCS\GrEpp\Command\ContactInfo;
use MyCS\GrEpp\Command\DomainCheck;
use MyCS\GrEpp\Command\DomainInfo;
use MyCS\GrEpp\Command\HostCheck;
use MyCS\GrEpp\Command\HostInfo;
use MyCS\GrEpp\Config;
use MyCS\GrEpp\OutcomeUnknown;
use MyCS\GrEpp\Response;
use MyCS\GrEpp\Session;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * G2a: the registry's queries (domain, contact and host check/info, account info). Each request valid to the registry's
 * schemas, part by part (epp-1.0.xsd skips a command's content: each object and extension against its own); each of
 * the guide's example answers read.
 */
final class QueriesTest extends TestCase
{
    private const SCHEMAS = [
        'urn:ietf:params:xml:ns:domain-1.0' => 'domain-1.0.xsd',
        'urn:ietf:params:xml:ns:contact-1.0' => 'contact-1.0.xsd',
        'urn:ietf:params:xml:ns:host-1.0' => 'host-1.0.xsd',
        'urn:ics-forth:params:xml:ns:extdomain-1.3' => 'extdomain-1.3.xsd',
        'urn:ics-forth:params:xml:ns:account-1.1' => 'account-1.1.xsd',
        'urn:ics-forth:params:xml:ns:dacor-1.0' => 'dacor-1.0.xsd',
        'urn:ietf:params:xml:ns:secDNS-1.1' => 'secDNS-1.1.xsd',
    ];

    /**
     * Valid to all the registry's schemas together (a command's object is a strict wildcard: its namespace's schema),
     * and each extension element (skipped by epp-1.0.xsd) to its own.
     */
    public static function assertValid(string $xml): void
    {
        $xsd = dirname(__DIR__) . '/resources/xsd/';
        $document = new DOMDocument;
        self::assertTrue($document->loadXML($xml), 'well-formed');
        $previous = libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(__DIR__ . '/all-schemas.xsd');
        $errors = implode('; ', array_map(fn ($e) => trim($e->message), libxml_get_errors()));
        libxml_clear_errors();
        self::assertTrue($valid, "valid to the registry's schemas: {$errors}");
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('epp', 'urn:ietf:params:xml:ns:epp-1.0');
        foreach ($xpath->query('/epp:epp/epp:command/epp:extension/*') as $part) {
            self::assertInstanceOf(DOMElement::class, $part);
            $schema = self::SCHEMAS[$part->namespaceURI] ?? null;
            self::assertNotNull($schema, "a schema for {$part->namespaceURI}");
            $alone = new DOMDocument;
            $alone->appendChild($alone->importNode($part, true));
            $valid = $alone->schemaValidate($xsd . $schema);
            $errors = implode('; ', array_map(fn ($e) => trim($e->message), libxml_get_errors()));
            libxml_clear_errors();
            self::assertTrue($valid, "{$part->localName} valid to {$schema}: {$errors}");
        }
        libxml_use_internal_errors($previous);
    }

    private static function fixture(string $name): Response
    {
        return Response::parse((string) file_get_contents(__DIR__ . "/fixtures/v4.3/{$name}"));
    }

    public function test_every_query_is_valid_to_the_registrys_schemas(): void
    {
        foreach ([
            DomainCheck::request(['ite.gr', 'ιτε.gr'], 'mycs-1'),
            DomainCheck::request(['forth.gr', 'forth.gov.gr'], 'mycs-1', 'c60_q1pcBkTq'),
            DomainInfo::request('forth.gr', 'mycs-1'),
            DomainInfo::request('forth.gr', 'mycs-1', 'Auth&<code>'),
            ContactCheck::request(['c60_a1', 'c60_a2'], 'mycs-1'),
            ContactInfo::request('c60_q1pcBkTq', 'mycs-1'),
            ContactInfo::request('reg_contact002', 'mycs-1', 'dcaa15928758c4a15afcedd7420542c24-gr', 'domain-code'),
            HostCheck::request(['ns1.myip.gr', 'ns2.myip.gr'], 'mycs-1'),
            HostInfo::request('ns1.myip.gr', 'mycs-1'),
            AccountInfo::request('mycs-1'),
            AccountInfo::request('mycs-1', 'ca03260e966eab45bdad74d8c9039c712a-gr'),
        ] as $xml) {
            self::assertValid($xml);
        }
        $this->assertStringContainsString('hosts="all"', DomainInfo::request('forth.gr', 'mycs-1'));
    }

    public function test_the_check_is_not_vacuous(): void
    {
        // The registry's own query examples pass it; a wrong element does not.
        foreach (['29-domain-check-request', '31-domain-check-request', '35-domain-info-request', '11-contact-info-request',
            '12.1-contact-info-request', '19-host-check-request', '21-host-info-request', '64-account-info-without-roid', '65-account-info-with-roid'] as $name) {
            self::assertValid((string) file_get_contents(__DIR__ . "/fixtures/v4.3/{$name}.xml"));
        }
        $this->expectException(AssertionFailedError::class);
        self::assertValid(str_replace('<domain:name>', '<domain:nom>', str_replace('</domain:name>', '</domain:nom>', DomainCheck::request(['ite.gr'], 'mycs-1'))));
    }

    public function test_domain_check_reads_free_taken_and_the_registrys_words(): void
    {
        $simple = DomainCheck::parse(self::fixture('30-domain-check-response.xml'));
        $this->assertTrue($simple['ite.gr']->available);

        $results = DomainCheck::parse(self::fixture('32-domain-check-response.xml'));
        $this->assertSame([true, 'Είναι διαθέσιμο', 'Το Όνομα Χώρου είναι διαθέσιμο για υλοποίηση.'],
            [$results['forth.gr']->available, $results['forth.gr']->reason, $results['forth.gr']->comment]);
        $this->assertSame([false, 'Σε Χρήση.'], [$results['forth.gov.gr']->available, $results['forth.gov.gr']->reason]);

        // .gov.gr: «available under restrictions» is not free; the comment on one line.
        $gov = DomainCheck::parse(self::fixture('34-domain-check-gov.gr-response.xml'));
        $this->assertFalse($gov['forth.gr']->available);
        $this->assertStringNotContainsString("\n", (string) $gov['forth.gr']->comment);
    }

    public function test_domain_info_reads_the_domain_and_the_registrys_extensions(): void
    {
        $info = DomainInfo::parse(self::fixture('36-domain-info-response.xml'));

        $this->assertSame(['forth.gr', 'dcaa15928758c4a15afcedd7420542c24-gr', 'reg_gsa', 'registry', '63161'],
            [$info->name, $info->roid, $info->registrant, $info->clientId, $info->protocol]);
        $this->assertSame(['admin' => ['reg_gsa', 'reg_izaf'], 'billing' => ['reg_gsa'], 'tech' => ['reg_gsa']], $info->contacts);
        $this->assertSame(['ns14.forth.gr', 'ns15.forth.gr', 'ns.example.com'], $info->nameservers);
        $this->assertSame(['ns1.forth.gr', 'ns14.forth.gr', 'ns15.forth.gr'], $info->hosts);
        $this->assertSame(['regulatorDelegationChangeProhibited', 'regulatorOwnerChangeProhibited', 'regulatorHold'], $info->regulatorStatuses);
        $this->assertSame('2013-03-20 23:59:59', $info->expires?->format('Y-m-d H:i:s'), 'UTC: the expiry is the 20th');
        $this->assertSame('2011-03-21', $info->created?->format('Y-m-d'));
        $this->assertSame([['forth.gr', true, 'domain']], array_map(fn ($b) => [$b->name, $b->chargeable, $b->recordType], $info->bundle));
        $this->assertFalse($info->dsValidation?->successful);
        $this->assertStringStartsWith('Όλες οι εγγραφές DS', (string) $info->dsValidation?->outcome);
        $this->assertSame('2011-05-05 13:21:24', $info->dsValidation?->date?->format('Y-m-d H:i:s'), '+03:00 as UTC');

        // A homograph (.ελ forms): its status, punycode and bundle.
        $homograph = DomainInfo::parse(self::fixture('39-domain-info-homographs-response.xml'));
        $this->assertSame(['ιτε.gr', ['inactive'], 'xn--mxareauy.gr', null], [$homograph->name, $homograph->statuses, $homograph->punycode, $homograph->updated]);
        $this->assertSame([['ίτε.gr', true, 'dname'], ['ιτε.gr', false, 'domain']], array_map(fn ($b) => [$b->name, $b->chargeable, $b->recordType], $homograph->bundle));
    }

    public function test_contacts_hosts_and_the_account_are_read(): void
    {
        $check = ContactCheck::parse(self::fixture('10-contact-check-response.xml'));
        $this->assertSame([false, true], [$check['reg_contact01']->available, $check['reg_techCont01']->available]);
        $this->assertSame('Ο κωδικός προσώπου επαφής είναι διαθέσιμος για υλοποίηση.', $check['reg_techCont01']->comment);

        $contact = ContactInfo::parse(self::fixture('12-contact-info-response.xml'));
        $this->assertSame(['reg_contact001', ['serverDeleteProhibited'], '+30.2810391400', '1450', 'gr-tech@ics.forth.gr', true],
            [$contact->id, $contact->statuses, $contact->voice, $contact->voiceExtension, $contact->email, $contact->disclosed]);
        $this->assertSame(['Μητρώο Ονομάτων Internet με κατάληξη .gr.', ['Ν.Πλαστήρα 100', 'Βασιλικά Βουτών'], 'Ηράκλειο', 'GR'],
            [$contact->postalInfo['loc']->name, $contact->postalInfo['loc']->street, $contact->postalInfo['loc']->city, $contact->postalInfo['loc']->country]);
        $this->assertSame(['Registry of .gr Domain Names', 'Heraklion'], [$contact->postalInfo['int']->name, $contact->postalInfo['int']->city]);
        $this->assertSame('reg_contact002', ContactInfo::parse(self::fixture('12.2-contact-info-response.xml'))->id);

        $hosts = HostCheck::parse(self::fixture('20-host-check-response.xml'));
        $this->assertSame([false, true], [$hosts['nameserver1.ics.forth.gr']->available, $hosts['nameserver2.ics.forth.gr']->available]);
        $this->assertSame('Γονικό όνομα χώρου μη ορισμένο.', $hosts['nameserrver.unknowndomain.g']->reason);

        $host = HostInfo::parse(self::fixture('22-host-info-response.xml'));
        $this->assertSame(['ns1.forth.gr', ['ok'], [['ip' => '11.1.1.1', 'version' => 'v4'], ['ip' => '1080:0:0:0:8:800:200C:417A', 'version' => 'v6']]],
            [$host->name, $host->statuses, $host->addresses]);

        $account = AccountInfo::parse(self::fixture('66-account-info-response.xml'));
        $this->assertSame(['-25', 'EUR', true, '2012-01-16', null], [$account->balance, $account->currency, $account->chargeableActionsAllowed,
            $account->toBeSuspendedOn?->format('Y-m-d'), $account->suspendedOn]);
    }

    public function test_review_fixes(): void
    {
        // 1: the registry's day, not shifted by a zone (Europe/Athens would make 23:59:59.9Z the next day).
        $info = DomainInfo::parse(self::fixture('36-domain-info-response.xml'), 'FORTH.gr');
        $this->assertSame(['2013-03-20', '2011-03-21'], [$info->expiresOn, $info->createdOn]);
        $this->assertSame('2013-03-21', $info->expires?->setTimezone(new \DateTimeZone('Europe/Athens'))->format('Y-m-d'), 'why expiresOn exists');

        // 2: available under restrictions (geographic terms, .gov.gr) is not «taken».
        $gov = DomainCheck::parse(self::fixture('34-domain-check-gov.gr-response.xml'));
        $this->assertSame([false, true], [$gov['forth.gr']->available, $gov['forth.gr']->restricted]);
        $this->assertFalse($gov['forth.gov.gr']->restricted);

        // 3: the registry's limits per check, before sending.
        foreach ([fn () => DomainCheck::request(array_fill(0, 8, 'a.gr'), 'mycs-1'), fn () => ContactCheck::request(['a', 'b', 'c', 'd'], 'mycs-1'), fn () => HostCheck::request([], 'mycs-1')] as $tooMany) {
            try {
                $tooMany();
                $this->fail('limit');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // 4: xs:boolean «1»; 9: xs:decimal forms.
        $account = AccountInfo::parse(Response::parse(str_replace(['<account:caAllowed>true', '>-25<'], ['<account:caAllowed>1', '>+.5<'],
            (string) file_get_contents(__DIR__ . '/fixtures/v4.3/66-account-info-response.xml'))));
        $this->assertSame([true, '0.5'], [$account->chargeableActionsAllowed, $account->balance]);

        // 5: which fields are disclosed.
        $contact = ContactInfo::parse(self::fixture('12-contact-info-response.xml'));
        $this->assertSame(['name:int', 'name:loc', 'org:int', 'org:loc', 'addr:int', 'addr:loc', 'voice', 'fax', 'email'], $contact->disclosedFields);

        // 6: an answer about another name is not this domain's.
        try {
            DomainInfo::parse(self::fixture('36-domain-info-response.xml'), 'other.gr');
            $this->fail('another name');
        } catch (OutcomeUnknown) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('ιτε.gr', DomainInfo::parse(self::fixture('39-domain-info-homographs-response.xml'), 'xn--mxareauy.gr')->name, 'by its punycode too');

        // 8: another registrar's contact needs both.
        $this->expectException(\InvalidArgumentException::class);
        ContactInfo::request('reg_contact002', 'mycs-1', 'roid-only');
    }

    public function test_dates_must_be_dates(): void
    {
        $this->expectException(OutcomeUnknown::class);
        DomainInfo::parse(Response::parse(str_replace('2013-03-20T23:59:59.9Z', 'tomorrow', (string) file_get_contents(__DIR__ . '/fixtures/v4.3/36-domain-info-response.xml'))));
    }

    public function test_a_success_without_the_data_asked_for_is_not_an_answer(): void
    {
        $this->expectException(OutcomeUnknown::class);
        DomainInfo::parse(self::fixture('08-logout-response.xml'));
    }

    public function test_a_query_goes_through_the_session_with_its_cl_tri_d_checked(): void
    {
        $transport = (new FakeTransport)->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml')
            ->fixture('30-domain-check-response.xml');
        $session = new Session(new Config('myip1', 'Secret-pw1'), $transport, transactionId: fn () => 'ABC:ics-forth:1079698712682');
        $clTRID = $session->newTransactionId();

        $results = DomainCheck::parse($session->command(DomainCheck::request(['ite.gr'], $clTRID), $clTRID));

        $this->assertTrue($results['ite.gr']->available);
        $this->assertSame(['hello', 'login', 'check'], $transport->kinds());
    }
}
