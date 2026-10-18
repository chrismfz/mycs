<?php

namespace MyCS\GrEpp\Tests;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use MyCS\GrEpp\Command\ContactCreate;
use MyCS\GrEpp\Command\ContactUpdate;
use MyCS\GrEpp\Command\DomainCreate;
use MyCS\GrEpp\Command\DomainDelete;
use MyCS\GrEpp\Command\DomainRenew;
use MyCS\GrEpp\Command\DomainTransfer;
use MyCS\GrEpp\Command\DomainUpdate;
use MyCS\GrEpp\Command\HostCreate;
use MyCS\GrEpp\Command\HostUpdate;
use MyCS\GrEpp\Command\OwnerChange;
use MyCS\GrEpp\Command\Rules;
use MyCS\GrEpp\Data\ContactChanges;
use MyCS\GrEpp\Data\ContactDraft;
use MyCS\GrEpp\Data\PostalInfo;
use MyCS\GrEpp\OutcomeUnknown;
use MyCS\GrEpp\Response;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;

/**
 * Adversarial review of #186 (G2b, the registry's writes), written without the author's context. Each ADV-n is one
 * finding; the tests named *_regression are hypotheses that held (cheap guards). The others fail until fixed.
 */
final class WritesAdversarialTest extends TestCase
{
    private const AUTH = 'S3cr3t-Auth';

    private static function loc(array $street = ['Οδός 1'], ?string $city = 'Αθήνα', ?string $sp = 'Αττική', ?string $pc = '10000',
        ?string $cc = 'GR', string $name = 'Χρήστος Παναγιωτάκης'): PostalInfo
    {
        return new PostalInfo('loc', $name, null, $street, $city, $sp, $pc, $cc);
    }

    private static function int(string $name = 'Christos Panagiotakis', array $street = ['Odos 1'], ?string $sp = 'Attiki'): PostalInfo
    {
        return new PostalInfo('int', $name, null, $street, 'Athina', $sp, '10000', 'GR');
    }

    private static function draft(?PostalInfo $loc = null, ?PostalInfo $int = null, string $email = 'owner@example.test',
        ?string $extension = null): ContactDraft
    {
        return new ContactDraft('c60_q1pcBkTq', $loc ?? self::loc(), $int, '+30.2100000000', $email, $extension);
    }

    private static function refused(callable $call, string $case): void
    {
        try {
            $xml = $call();
        } catch (InvalidArgumentException) {
            self::assertTrue(true);

            return;
        }
        self::fail("Rules let through, the registry refuses: {$case}\n" . (is_string($xml) ? $xml : ''));
    }

    private static function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        self::assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        foreach (Response::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    /** @return list<string> */
    private static function texts(string $xml, string $query): array
    {
        $texts = [];
        foreach (self::xpath($xml)->query($query) as $node) {
            $texts[] = $node->textContent;
        }

        return $texts;
    }

    // ADV-1: the international (int) postalInfo must be ASCII only (the guide, contact create: «Τα πεδία αυτά θα πρέπει
    // να περιέχουν μόνο χαρακτήρες ASCII»; RFC 5733 too). Rules::text lets Greek and Latin-1 through for it.
    public function test_adv1_the_international_postal_info_is_ascii_only(): void
    {
        self::refused(fn () => ContactCreate::request(self::draft(int: self::int('José Müller')), 'mycs-1'), 'int name in Latin-1');
        self::refused(fn () => ContactCreate::request(self::draft(int: self::int('Χρήστος')), 'mycs-1'), 'int name in Greek');
        self::refused(fn () => ContactCreate::request(self::draft(int: self::int(street: ['Οδός 1'])), 'mycs-1'), 'int street in Greek');
        self::refused(fn () => ContactUpdate::request('c60_q1pcBkTq', new ContactChanges(internationalAddress: [
            'street' => ['Οδός 1'], 'city' => 'Αθήνα', 'country' => 'GR']), 'mycs-1'), 'int address update in Greek');
    }

    // ADV-2: the registry's mandatory loc fields (the guide, contact create: name, street (at least one), city, sp, pc,
    // cc) and the schema's lengths are not checked: a contact without its region, street or city, or with an empty name
    // or a 300-character street, is sent and refused (2306/2001). G5 creates the registrant before the domain: the
    // order stops after payment.
    public function test_adv2_the_registrys_mandatory_contact_fields_are_checked(): void
    {
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(sp: null)), 'mycs-1'), 'loc without sp (region)');
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(pc: null)), 'mycs-1'), 'loc without postcode');
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(street: [])), 'mycs-1'), 'loc without a street');
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(city: null)), 'mycs-1'), 'loc without a city (sent <contact:city/>)');
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(name: '')), 'mycs-1'), 'an empty name');
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(street: [str_repeat('α', 300)])), 'mycs-1'), 'a street over 255');
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(pc: str_repeat('1', 17))), 'mycs-1'), 'a postcode over 16');
        self::refused(fn () => ContactCreate::request(self::draft(int: self::int(sp: null)), 'mycs-1'), 'int name given without its sp');
    }

    // ADV-3: a fourth street line is silently dropped (array_slice 0..3): the registrant's address at the registry is
    // not what the customer gave, with no error.
    public function test_adv3_a_fourth_street_line_is_not_silently_dropped(): void
    {
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(street: ['Οδός 1', 'Κτίριο Β', 'Όροφος 3', 'Γραφείο 12'])), 'mycs-1'),
            'four street lines (the fourth dropped)');
    }

    // ADV-4: the country is not checked: «GRC», «» or null pass Rules and are schema-invalid (2001).
    public function test_adv4_the_country_is_two_letters(): void
    {
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(cc: 'GRC')), 'mycs-1'), 'a three-letter country');
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(cc: null)), 'mycs-1'), 'no country');
        self::refused(fn () => ContactCreate::request(self::draft(self::loc(cc: 'G1')), 'mycs-1'), 'a country with a digit');
    }

    public function test_adv4_country_case_regression(): void
    {
        foreach (['GR', 'gr', 'Gr'] as $cc) {
            $xml = ContactCreate::request(self::draft(self::loc(cc: $cc)), 'mycs-1');
            QueriesTest::assertValid($xml);
            $this->assertSame(['gr'], self::texts($xml, '//contact:cc'));
        }
    }

    // ADV-5: Greek typed on macOS/iOS or pasted from a PDF is often decomposed (NFD: α + U+0301): valid Greek, refused
    // by Rules (and by the registry, U+0301 is outside its blocks). Normalise to NFC before checking.
    public function test_adv5_decomposed_greek_is_normalised_not_refused(): void
    {
        $nfd = \Normalizer::normalize('Παναγιωτάκης Άννα', \Normalizer::FORM_D);
        $this->assertNotSame('Παναγιωτάκης Άννα', $nfd);
        $xml = ContactCreate::request(self::draft(self::loc(name: (string) $nfd)), 'mycs-1');
        $this->assertSame(['Παναγιωτάκης Άννα'], self::texts($xml, '//contact:postalInfo[@type="loc"]/contact:name'));
    }

    public function test_adv5_greek_latin1_and_punctuation_regression(): void
    {
        // tonos, diaeresis, both, final sigma, Greek Extended, Latin-1, apostrophe, ampersand, < and quotes
        foreach (["\u{0386}\u{03BD}\u{03BD}\u{03B1} \u{03AA}\u{03B4}\u{03B7}-\u{03AA}\u{03C9}\u{03AC}\u{03BD}\u{03BD}\u{03BF}\u{03C5} \u{0390} \u{03B0}", 'Ευάγγελος Ταΰγετος', 'Γιώργος', 'ἈΘῆναι', 'José Müller', "O'Neil & Sons <A.E.> \"Δέλτα\""] as $name) {
            $xml = ContactCreate::request(self::draft(self::loc(name: $name)), 'mycs-1');
            QueriesTest::assertValid($xml);
            $this->assertSame([$name], self::texts($xml, '//contact:postalInfo[@type="loc"]/contact:name'));
        }
        // the registry's own refusals, also refused before sending: a no-break space (U+00A0), a typographic apostrophe
        // (U+2019), an en dash (U+2013), the not sign (U+00AC)
        foreach (["Οδός\u{00A0}1", "O\u{2019}Neil", "Οδός 12\u{2013}14", 'a¬b'] as $text) {
            self::refused(fn () => Rules::text($text, 'street'), json_encode($text) ?: '');
        }
    }

    // ADV-6: the guide (External hosts): hosts ending in .xn--qxam are refused outright as syntactically invalid, and an
    // internal host given in punycode («--» anywhere) too. HostCreate::internal() takes .xn--qxam as internal and glue
    // goes with it; a punycode label under .gr is sent as is.
    public function test_adv6_punycode_internal_hosts_are_not_sent_as_punycode(): void
    {
        foreach (['ns1.example.xn--qxam', 'ns1.xn--hxajbheg2az3al.gr'] as $host) {
            try {
                $xml = HostCreate::request($host, ['192.0.2.1'], 'mycs-1');
            } catch (InvalidArgumentException) {
                continue;
            }
            $this->assertStringNotContainsString('xn--', implode(' ', self::texts($xml, '//host:name')),
                "{$host}: the registry refuses a punycode internal host (send it in Unicode, or refuse it)");
        }
    }

    // ADV-7: a trailing dot (as in zone files, or a customer's paste): internal() strips it, the name sent keeps it (the
    // registry: «.» only between labels), and «ns1.myip.gr.» is not deduplicated against «ns1.myip.gr».
    public function test_adv7_a_trailing_dot_is_not_sent(): void
    {
        $this->assertSame(['ns1.myip.gr'], self::texts(HostCreate::request('NS1.MyIP.gr.', ['192.0.2.1'], 'mycs-1'), '//host:name'));
        $this->assertSame(['ns1.myip.gr'],
            self::texts(DomainCreate::request('cdnet.gr', 2, ['ns1.myip.gr', 'NS1.myip.gr.'], 'c60_q1pcBkTq', [], 'mycs-1'), '//domain:hostObj'));
    }

    public function test_adv7_uppercase_internal_hosts_regression(): void
    {
        foreach (['NS1.MYIP.GR', 'ns1.παράδειγμα.ΕΛ', 'ns1.myip.gr.'] as $host) {
            $this->assertTrue(HostCreate::internal($host), $host);
        }
        foreach (['ns1.example.com', 'ns1.evilgr', 'ns1.gr.example.com', 'gr'] as $host) {
            $this->assertFalse(HostCreate::internal($host), $host);
        }
        self::refused(fn () => HostCreate::request('NS1.EXAMPLE.COM', ['192.0.2.1'], 'mycs-1'), 'glue on an uppercase external host');
    }

    // ADV-8: libxml cuts a text at a NUL and drops other control characters silently (only a warning for those): a name
    // «victim.gr\0.mine.gr» is sent as «victim.gr», an auth code «Auth\x01code» as «Authcode». Every value that goes in
    // the XML must be refused if it has a character XML 1.0 (or the guide's Appendix A) does not take.
    public function test_adv8_nul_and_control_characters_are_refused_not_truncated(): void
    {
        self::refused(fn () => DomainDelete::delete("victim.gr\0.mine.gr", self::AUTH, 'mycs-1'), 'a NUL in the domain name (sent as victim.gr)');
        self::refused(fn () => DomainUpdate::request("victim.gr\0.mine.gr", 'mycs-1', ['ns1.myip.gr']), 'a NUL in the domain name of an update');
        self::refused(fn () => HostCreate::request("ns1.example.com\0.gr", ['192.0.2.1'], 'mycs-1'), 'a NUL in a host name (glue check bypassed)');
        self::refused(fn () => @DomainTransfer::request('nac.gr', "Auth\x01code", 'c60_newowner', 'mycs-1'), 'a control character in an auth code');
        self::refused(fn () => @DomainCreate::request('cdnet.gr', 2, ["ns1.myip.gr\x0B"], 'c60_q1pcBkTq', [], 'mycs-1'), 'a control character in a nameserver');
        self::refused(fn () => @ContactCreate::request(self::draft(extension: "12\x02"), 'mycs-1'), 'a control character in the phone extension');
    }

    public function test_adv8_xml_injection_regression(): void
    {
        $evil = '</domain:pw><domain:pw>x</domain:pw>]]>&amp;<!--';
        $xml = DomainTransfer::request('nac.gr', $evil, 'c60_newowner', 'mycs-1');
        QueriesTest::assertValid($xml);
        $this->assertSame([$evil], self::texts($xml, '//domain:pw'));
        // The protocol is the registry's number (ADV-15): only the clTRID carries markup here.
        $xml = DomainDelete::recall('cdnet.gr', '66155', 'c"><x/>');
        $this->assertSame(['66155'], self::texts($xml, '//extdomain:protocol'));
        $this->assertSame(['c"><x/>'], self::texts($xml, '//epp:clTRID'));
        $xml = ContactCreate::request(self::draft(self::loc(street: ['<contact:cc>us</contact:cc>'])), 'mycs-1');
        $this->assertSame(['gr'], self::texts($xml, '//contact:cc'));
    }

    // ADV-9: the same nameserver added and removed in one update (case aside): sent, refused or (worse) applied in an
    // order we do not control. Also the same contact twice.
    public function test_adv9_adding_and_removing_the_same_nameserver_is_refused(): void
    {
        self::refused(fn () => DomainUpdate::request('cdnet.gr', 'mycs-1', ['ns1.myip.gr'], ['NS1.myip.gr']), 'add and rem the same NS');
        self::refused(fn () => DomainUpdate::request('cdnet.gr', 'mycs-1', addContacts: ['tech' => ['c60_a1b2c']], removeContacts: ['tech' => ['c60_a1b2c']]),
            'add and rem the same contact');
        self::refused(fn () => DomainCreate::request('cdnet.gr', 2, [], 'c60_q1pcBkTq', ['tech' => ['c60_a1b2c', 'c60_a1b2c']], 'mycs-1'),
            'the same tech contact twice');
    }

    // ADV-10: contacts under a role that is not admin/tech/billing are dropped silently; with nothing else, the update
    // passes notEmpty and goes out empty (just the name: 2002, which Session also takes once for «session gone»).
    public function test_adv10_an_unknown_contact_role_is_refused_not_dropped(): void
    {
        self::refused(fn () => DomainUpdate::request('cdnet.gr', 'mycs-1', addContacts: ['Tech' => ['c60_a1b2c']]), 'role «Tech»');
        self::refused(fn () => DomainUpdate::request('cdnet.gr', 'mycs-1', addContacts: ['registrant' => ['c60_a1b2c']]), 'role «registrant» (OwnerChange only)');
        self::refused(fn () => DomainCreate::request('cdnet.gr', 2, [], 'c60_q1pcBkTq', ['technical' => ['c60_a1b2c']], 'mycs-1'), 'role «technical»');
    }

    // ADV-11: the guide's limits (domain update: 16 nameservers, 11 contacts) not checked.
    public function test_adv11_the_registrys_nameserver_and_contact_limits(): void
    {
        $hosts = array_map(fn (int $i) => "ns{$i}.myip.gr", range(1, 17));
        self::refused(fn () => DomainCreate::request('cdnet.gr', 2, $hosts, 'c60_q1pcBkTq', [], 'mycs-1'), '17 nameservers on create');
        self::refused(fn () => DomainUpdate::request('cdnet.gr', 'mycs-1', $hosts), '17 nameservers added');
        $contacts = array_map(fn (int $i) => "c60_t{$i}xx", range(1, 12));
        self::refused(fn () => DomainCreate::request('cdnet.gr', 2, [], 'c60_q1pcBkTq', ['tech' => $contacts], 'mycs-1'), '12 contacts');
    }

    // ADV-12: Rules::contactId knows no prefix: a registrant, a transfer's or renew's registrantid, an owner change's new
    // registrant may be another registrar's contact (FAQ: only our contacts; after a transfer theirs are replaced). A
    // transfer into «ics_old01» would leave the name's registrant a contact we cannot manage. Removing theirs must stay
    // possible.
    public function test_adv12_registrants_are_ours(): void
    {
        self::refused(fn () => call_user_func([Rules::class, 'contactId'], 'ics_old01', 'c60'), 'another registrar\'s prefix where ours is required');
    }

    public function test_adv12_removing_their_contacts_regression(): void
    {
        $xml = DomainUpdate::request('nac.gr', 'mycs-1', addContacts: ['tech' => ['c60_new01']], removeContacts: ['tech' => ['ics_old01'], 'admin' => ['ics_old02']]);
        QueriesTest::assertValid($xml);
        $this->assertSame(['ics_old02', 'ics_old01'], self::texts($xml, '//domain:rem/domain:contact'));
    }

    // ADV-13: a renew of another registrar's name with an empty registrant id/auth code passes the null check; a renew's
    // curExpDate that is no day («2026-02-30») is sent.
    public function test_adv13_renew_inputs(): void
    {
        self::refused(fn () => DomainRenew::request('a.gr', '2026-11-01', 2, 'mycs-1', 'c60_q1pcBkTq', ''), 'an empty auth code');
        self::refused(fn () => DomainRenew::request('a.gr', '2026-02-30', 2, 'mycs-1'), 'a curExpDate that is no day');
    }

    public function test_adv13_renew_amount_regression(): void
    {
        foreach ([2, 4, 6, 8, 10] as $years) {
            $xml = DomainRenew::request('cdnet.gr', '2026-11-01', $years, 'mycs-1');
            $this->assertSame([(string) $years], self::texts($xml, '//domain:period'));
            $this->assertSame(['2026-11-01'], self::texts($xml, '//domain:curExpDate'));
        }
        foreach ([0, -2, 1, 3, 11, 12, 20] as $years) {
            self::refused(fn () => DomainRenew::request('cdnet.gr', '2026-11-01', $years, 'mycs-1'), "{$years} years");
        }
        self::refused(fn () => DomainRenew::request('cdnet.gr', '2026-11-01', 2, 'mycs-1', null, self::AUTH), 'an auth code without registrant');
    }

    // ADV-14: an email the registry refuses (no «@») and an extension that is not digits go out.
    public function test_adv14_email_and_extension(): void
    {
        self::refused(fn () => ContactCreate::request(self::draft(email: 'not-an-email'), 'mycs-1'), 'an email without @');
        self::refused(fn () => ContactCreate::request(self::draft(email: ''), 'mycs-1'), 'an empty email');
        self::refused(fn () => ContactCreate::request(self::draft(extension: 'ext. 12'), 'mycs-1'), 'an extension that is not digits');
    }

    // ADV-15: a recall's protocol is the registry's number: sent untrimmed, or anything at all.
    public function test_adv15_a_recall_protocol_is_the_registrys_number(): void
    {
        self::refused(fn () => DomainDelete::recall('cdnet.gr', 'abc', 'mycs-1'), 'a protocol that is no number');
        $xml = DomainDelete::recall('cdnet.gr', " 66155\n", 'mycs-1');
        $this->assertSame(['66155'], self::texts($xml, '//extdomain:protocol'));
    }

    // Hypotheses that held: auth codes never in exception messages, nor in the trace's arguments.
    public function test_auth_codes_never_leak_regression(): void
    {
        foreach ([
            fn () => DomainTransfer::request('a.gr', self::AUTH, 'bad id', 'mycs-1'),
            fn () => DomainRenew::request('a.gr', '2026-11-01', 3, 'mycs-1', 'c60_q1pcBkTq', self::AUTH),
            fn () => DomainRenew::request('a.gr', '2026-11-01', 2, 'mycs-1', 'bad id', self::AUTH),
        ] as $call) {
            try {
                $call();
                $this->fail('refused');
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString(self::AUTH, $e->getMessage());
                $this->assertStringNotContainsString(self::AUTH, print_r(array_map(fn ($f) => $f['args'] ?? [], $e->getTrace()), true));
                $this->assertStringNotContainsString(self::AUTH, (string) $e);
            }
        }
        // the frames that hold it hold a SensitiveParameterValue
        try {
            DomainTransfer::request('a.gr', self::AUTH, 'bad id', 'mycs-1');
        } catch (InvalidArgumentException $e) {
            foreach ($e->getTrace() as $frame) {
                if (($frame['class'] ?? '') === DomainTransfer::class && isset($frame['args'][1])) {
                    $this->assertInstanceOf(SensitiveParameterValue::class, $frame['args'][1]);
                }
            }
        }
    }

    // Hypotheses that held: Results reads the data element's own name (a trnData's name, never reID/acID), comments of
    // every registry extension in document order, and no data element is OutcomeUnknown.
    public function test_results_read_the_right_name_regression(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>
<resData><domain:trnData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:reID>other.gr</domain:reID>
<domain:name>forth.gr</domain:name><domain:trStatus>serverApproved</domain:trStatus><domain:reDate>2011-05-13T13:01:42.5Z</domain:reDate>
<domain:acID>registry</domain:acID><domain:acDate>2011-05-13T13:01:42.5Z</domain:acDate></domain:trnData></resData>
<extension><extdomain:resData xmlns:extdomain="urn:ics-forth:params:xml:ns:extdomain-1.3"><extdomain:comment>one</extdomain:comment></extdomain:resData>
<extcommon:resData xmlns:extcommon="urn:ics-forth:params:xml:ns:extcommon-1.0"><extcommon:comment>two</extcommon:comment></extcommon:resData></extension>
<trID><clTRID>c</clTRID><svTRID>s</svTRID></trID></response></epp>
XML;
        $result = DomainTransfer::parse(Response::parse($xml));
        $this->assertSame(['forth.gr', 'serverApproved', ['one', 'two'], null], [$result->name, $result->transferStatus, $result->comments, $result->expiresOn]);

        $this->expectException(OutcomeUnknown::class);
        DomainRenew::parse(Response::parse(str_replace(['domain:trnData', 'trnData'], ['domain:infData', 'infData'], $xml)));
    }

    // Hypotheses that held: an owner change is only one of the two ops, case-sensitive, and each goes out as itself.
    public function test_owner_change_ops_regression(): void
    {
        foreach (['ownerchange', 'OwnerChange', 'recallApplication', 'deleteDomain', ''] as $op) {
            self::refused(fn () => OwnerChange::request('cdnet.gr', 'c60_q1pcBkTq', $op, 'mycs-1'), "op «{$op}»");
        }
        $this->assertSame(['ownerNameChange'], self::texts(OwnerChange::request('cdnet.gr', 'c60_q1pcBkTq', OwnerChange::NAME_CHANGE, 'mycs-1'), '//extdomain:op'));
        $this->assertSame(['ownerChange'], self::texts(OwnerChange::request('cdnet.gr', 'c60_q1pcBkTq', OwnerChange::OWNER_CHANGE, 'mycs-1'), '//extdomain:op'));
    }

    // Hypotheses that held: phones as the guide asks (E.164 «+CCC.N», at most 15 digits); a phone with spaces is refused
    // (the caller normalises).
    public function test_phones_regression(): void
    {
        foreach (['+30.2100000000', '+1.23456789012345', '+357.123456789012'] as $phone) {
            $this->assertSame($phone, Rules::phone($phone));
        }
        foreach (['+30 210 000 0000', '+30.210 0000000', '+1.234567890123456', '+357.1234567890123', '+30.', '30.2100000000'] as $phone) {
            self::refused(fn () => Rules::phone($phone), json_encode($phone) ?: '');
        }
    }

    // Hypothesis that held: glue on a host update is checked against the host's own name.
    public function test_host_update_glue_regression(): void
    {
        self::refused(fn () => HostUpdate::request('ns1.example.com', ['192.0.2.1'], [], 'mycs-1'), 'glue added to an external host');
        QueriesTest::assertValid(HostUpdate::request('NS1.MYIP.GR', ['2001:db8::1'], [], 'mycs-1'));
    }

    // ADV-16: the patterns end in «$» without D: a trailing newline passes (a contact id, a phone, a renew's day).
    public function test_adv16_a_trailing_newline_is_refused(): void
    {
        self::refused(fn () => Rules::contactId("c60_abcde\n"), 'a contact id with a newline');
        self::refused(fn () => Rules::phone("+30.2100000000\n"), 'a phone with a newline');
        self::refused(fn () => DomainRenew::request('a.gr', "2026-11-01\n", 2, 'mycs-1'), 'a curExpDate with a newline');
    }
}
