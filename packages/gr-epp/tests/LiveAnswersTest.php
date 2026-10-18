<?php

namespace MyCS\GrEpp\Tests;

use MyCS\GrEpp\Command\AccountInfo;
use MyCS\GrEpp\Command\ContactCreate;
use MyCS\GrEpp\Command\ContactInfo;
use MyCS\GrEpp\Command\DomainCheck;
use MyCS\GrEpp\Command\DomainCreate;
use MyCS\GrEpp\Command\DomainDelete;
use MyCS\GrEpp\Command\DomainInfo;
use MyCS\GrEpp\Response;
use PHPUnit\Framework\TestCase;

/**
 * The registry's real answers (live, 2026-10-18, our own domains; anonymised: names, ids, hosts, dates, registrars,
 * transaction ids and amounts replaced: tests/fixtures/live/): what the guide's
 * examples did not show. clID is the registrar («myip»), not the EPP user; contacts have only their Greek postal info
 * and no disclose; «available under restrictions» for a geographic term; other registrars' (533_) contacts on domains
 * transferred in.
 */
final class LiveAnswersTest extends TestCase
{
    private static function live(string $name): Response
    {
        return Response::parse((string) file_get_contents(__DIR__ . "/fixtures/live/{$name}.xml"));
    }

    public function test_the_account(): void
    {
        $account = AccountInfo::parse(self::live('account-info-response'));
        $this->assertSame(['100.00', 'EUR', true, null, null], [$account->balance, $account->currency, $account->chargeableActionsAllowed,
            $account->toBeSuspendedOn, $account->suspendedOn]);
    }

    public function test_the_check(): void
    {
        $check = DomainCheck::parse(self::live('domain-check-response'));
        $this->assertFalse($check['example1.gr']->available);
        $this->assertFalse($check['example1.gr']->restricted);
        $this->assertSame('Σε χρήση', $check['example1.gr']->reason);
        $this->assertTrue($check['free-example.gr']->available);
        $this->assertSame([false, true, 'Διαθέσιμο υπό περιορισμούς', 'Γεωγραφικός όρος - Άρθρο 8 του Κανονισμού.'],
            [$check['athina.gr']->available, $check['athina.gr']->restricted, $check['athina.gr']->reason, $check['athina.gr']->comment]);
    }

    public function test_a_domain(): void
    {
        $info = DomainInfo::parse(self::live('domain-info-response'), 'example1.gr');
        $this->assertSame(['myip', ['ok'], [], '2029-03-01', '2005-03-01', 'c60_Example001', []],
            [$info->clientId, $info->statuses, $info->regulatorStatuses, $info->expiresOn, $info->createdOn, $info->registrant, $info->contacts]);
        $this->assertSame(['ns1.myip.gr', 'ns2.myip.gr', 'ns3.myip.gr'], $info->nameservers);
        $this->assertContains('ns1.example1.gr', $info->hosts);
        $this->assertSame('2027-03-01', $info->transferred?->format('Y-m-d'), 'transferred to us');

        // Transferred in from another registrar: its contacts (533_) on the roles, the registrant ours.
        $other = DomainInfo::parse(self::live('domain-info-other-registrar-contacts-response'), 'example2.gr');
        $this->assertStringStartsWith('c60_', (string) $other->registrant);
        $this->assertStringStartsWith('533_', $other->contacts['admin'][0]);
    }

    public function test_a_contact_has_only_its_greek_postal_info(): void
    {
        $contact = ContactInfo::parse(self::live('contact-info-response'));
        $this->assertSame([['loc'], 'GR', null, []], [array_keys($contact->postalInfo), $contact->postalInfo['loc']->country, $contact->disclosed, $contact->disclosedFields]);
    }

    public function test_a_registration_is_pending_with_its_protocol_and_its_recall_is_done(): void
    {
        // G7 (2026-10-18): even a name without a geographic term is «Προς Έγκριση» (1001) with a protocol; its expiry
        // the day before, two years on, at 23:59:59 UTC; the answers in extdomain-1.3. The recall: 1000 with a comment.
        $created = DomainCreate::parse(self::live('domain-create-pending-response'));
        $this->assertTrue($created->pending());
        $this->assertSame([1001, 'example-new.gr', '1234567', '2028-10-17'], [$created->code, $created->name, $created->protocol, $created->expiresOn]);
        $this->assertStringContainsString('Προς Έγκριση', implode(' ', $created->comments));

        $recalled = DomainDelete::parse(self::live('domain-delete-recall-response'));
        $this->assertSame(1000, $recalled->code);
        $this->assertSame(['Η ανάκληση της δήλωσης έχει ολοκληρωθεί επιτυχώς.'], $recalled->comments);

        $this->assertSame('c60_mcs1', ContactCreate::parse(self::live('contact-create-response'))->name);
    }
}
