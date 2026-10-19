<?php

namespace MyCS\GrEpp\Command;

use DOMElement;
use MyCS\GrEpp\Data\BundleName;
use MyCS\GrEpp\Data\DomainInfo as Info;
use MyCS\GrEpp\Data\DsRecord;
use MyCS\GrEpp\Data\DsValidation;
use MyCS\GrEpp\OutcomeUnknown;
use MyCS\GrEpp\Response;
use SensitiveParameter;

/**
 * domain:info (grepp.md §5.3, the guide's examples 35, 36, 39): the domain, its contacts, nameservers and dates, and the
 * registry's extensions: the regulator's statuses, the protocol (needed to recall a registration), the punycode, the
 * bundle, the last DS check. Another registrar's domain needs its authorisation code.
 */
final class DomainInfo
{
    public static function request(string $name, string $clTRID, #[SensitiveParameter] ?string $authCode = null): string
    {
        $command = Builder::start('info', 'domain');
        $command->add($command->object, 'domain', 'name', $name)->setAttribute('hosts', 'all');
        if ($authCode !== null) {
            $command->add($command->add($command->object, 'domain', 'authInfo'), 'domain', 'pw', $authCode);
        }

        return $command->finish($clTRID);
    }

    /**
     * @param  string|null  $expected  the name asked: an answer about another (a bundle's other form, a variant) is not
     *                                 this domain's (review 6 of #185)
     */
    public static function parse(Response $response, ?string $expected = null): Info
    {
        $xpath = $response->xpath();
        $data = Read::required($response, '//domain:infData');
        $name = (string) Read::text($xpath, 'domain:name', $data);
        $punycode = Read::text($xpath, '//extdomain:resData/extdomain:punycode');
        if ($expected !== null && !in_array(mb_strtolower($expected), array_filter([mb_strtolower($name), $punycode !== null ? mb_strtolower($punycode) : null]), true)) {
            throw new OutcomeUnknown("The registry answered about {$name}, not {$expected}.", $response->code);
        }
        $reasons = [];
        foreach ($xpath->query('domain:status | //extdomain:resData/extdomain:status', $data) as $status) {
            if ($status instanceof DOMElement && trim($status->textContent) !== '') {
                $reasons[$status->getAttribute('s')] = trim((string) preg_replace('/\s+/u', ' ', $status->textContent));
            }
        }
        $contacts = [];
        foreach ($xpath->query('domain:contact', $data) as $contact) {
            if ($contact instanceof DOMElement) {
                $contacts[$contact->getAttribute('type')][] = trim($contact->textContent);
            }
        }
        $bundle = [];
        foreach ($xpath->query('//extdomain:resData/extdomain:bundle/extdomain:bundleName') as $form) {
            if ($form instanceof DOMElement) {
                $bundle[] = new BundleName(trim($form->textContent), in_array($form->getAttribute('chargeable'), ['1', 'true'], true), $form->getAttribute('recordType'));
            }
        }
        $ds = $xpath->query('//extSecDNS:lastValidationResult')->item(0);
        $dsRecords = [];
        foreach ($xpath->query('//secDNS:infData/secDNS:dsData') as $record) {
            if ($record instanceof DOMElement) {
                $dsRecords[] = new DsRecord((int) Read::text($xpath, 'secDNS:keyTag', $record), (int) Read::text($xpath, 'secDNS:alg', $record),
                    (int) Read::text($xpath, 'secDNS:digestType', $record), strtoupper(trim((string) Read::text($xpath, 'secDNS:digest', $record))));
            }
        }

        return new Info(
            name: $name,
            roid: (string) Read::text($xpath, 'domain:roid', $data),
            statuses: Read::statuses($xpath, 'domain:status', $data),
            regulatorStatuses: Read::statuses($xpath, '//extdomain:resData/extdomain:status'),
            registrant: Read::text($xpath, 'domain:registrant', $data),
            contacts: $contacts,
            nameservers: array_map('mb_strtolower', Read::texts($xpath, 'domain:ns/domain:hostObj | domain:ns/domain:hostAttr/domain:hostName', $data)),
            hosts: array_map('mb_strtolower', Read::texts($xpath, 'domain:host', $data)),
            clientId: Read::text($xpath, 'domain:clID', $data),
            created: Read::date($xpath, 'domain:crDate', $data),
            updated: Read::date($xpath, 'domain:upDate', $data),
            expires: Read::date($xpath, 'domain:exDate', $data),
            transferred: Read::date($xpath, 'domain:trDate', $data),
            protocol: Read::text($xpath, '//extdomain:resData/extdomain:protocol'),
            punycode: $punycode,
            bundle: $bundle,
            dsValidation: $ds === null ? null : new DsValidation(
                Read::bool(Read::text($xpath, 'extSecDNS:wasSuccessful', $ds)),
                ($outcome = Read::text($xpath, 'extSecDNS:validationOutcome', $ds)) !== null ? trim((string) preg_replace('/\s+/u', ' ', $outcome)) : null,
                Read::date($xpath, 'extSecDNS:validationDate', $ds),
            ),
            expiresOn: Read::day($xpath, 'domain:exDate', $data),
            createdOn: Read::day($xpath, 'domain:crDate', $data),
            statusReasons: $reasons,
            comments: array_map(fn (string $c) => trim((string) preg_replace('/\s+/u', ' ', $c)), Read::texts($xpath, '//extdomain:resData/extdomain:comment')),
            dsRecords: $dsRecords,
        );
    }
}
