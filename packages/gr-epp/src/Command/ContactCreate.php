<?php

namespace MyCS\GrEpp\Command;

use DOMElement;
use MyCS\GrEpp\Data\ContactDraft;
use MyCS\GrEpp\Data\PostalInfo;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/**
 * contact:create (the guide's examples 13, 14): one of our contacts, «prefix_» id, Greek and Latin postal info, an empty
 * <pw/> (anything else: 2308), hidden from WHOIS unless asked.
 */
final class ContactCreate
{
    public static function request(ContactDraft $contact, string $clTRID): string
    {
        $command = Builder::start('create', 'contact');
        $command->add($command->object, 'contact', 'id', Rules::contactId($contact->id));
        foreach (array_filter([$contact->local, $contact->international]) as $postal) {
            self::postal($command, $command->object, $postal, withName: true);
        }
        $voice = $command->add($command->object, 'contact', 'voice', Rules::phone($contact->voice));
        if ($contact->voiceExtension !== null && $contact->voiceExtension !== '') {
            $voice->setAttribute('x', $contact->voiceExtension);
        }
        if ($contact->fax !== null && $contact->fax !== '') {
            $command->add($command->object, 'contact', 'fax', Rules::phone($contact->fax));
        }
        $command->add($command->object, 'contact', 'email', Rules::text($contact->email, 'email'));
        $command->add($command->add($command->object, 'contact', 'authInfo'), 'contact', 'pw');
        $disclose = $command->add($command->object, 'contact', 'disclose');
        $disclose->setAttribute('flag', $contact->hidden ? '0' : '1');
        foreach (['name', 'org', 'addr'] as $field) {
            foreach (array_filter(['int' => $contact->international, 'loc' => $contact->local]) as $type => $_) {
                $command->add($disclose, 'contact', $field)->setAttribute('type', $type);
            }
        }
        foreach (['voice', 'fax', 'email'] as $field) {
            $command->add($disclose, 'contact', $field);
        }

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response, 'contact:creData');
    }

    /** A postalInfo (name and org only on create: never changed by an update, grepp.md §0). */
    public static function postal(Builder $command, DOMElement $parent, PostalInfo $postal, bool $withName): void
    {
        $info = $command->add($parent, 'contact', 'postalInfo');
        $info->setAttribute('type', $postal->type);
        if ($withName) {
            $command->add($info, 'contact', 'name', Rules::text($postal->name, 'name'));
            if ($postal->org !== null && $postal->org !== '') {
                $command->add($info, 'contact', 'org', Rules::text($postal->org, 'organisation'));
            }
        }
        $addr = $command->add($info, 'contact', 'addr');
        foreach (array_slice($postal->street, 0, 3) as $line) {
            $command->add($addr, 'contact', 'street', Rules::text($line, 'street'));
        }
        $command->add($addr, 'contact', 'city', Rules::text((string) $postal->city, 'city'));
        if ($postal->province !== null && $postal->province !== '') {
            $command->add($addr, 'contact', 'sp', Rules::text($postal->province, 'region'));
        }
        if ($postal->postcode !== null && $postal->postcode !== '') {
            $command->add($addr, 'contact', 'pc', Rules::text($postal->postcode, 'postcode'));
        }
        $command->add($addr, 'contact', 'cc', strtolower((string) $postal->country));
    }
}
