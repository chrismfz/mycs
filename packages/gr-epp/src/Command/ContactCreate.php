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
        if ($contact->local->type !== 'loc' || ($contact->international !== null && $contact->international->type !== 'int')) {
            throw new \InvalidArgumentException('The local postal info is «loc» (Greek), the international «int» (Latin).');
        }
        foreach (array_filter([$contact->local, $contact->international]) as $postal) {
            self::postal($command, $command->object, $postal, withName: true);
        }
        $voice = $command->add($command->object, 'contact', 'voice', Rules::phone($contact->voice));
        if ($contact->voiceExtension !== null && $contact->voiceExtension !== '') {
            $voice->setAttribute('x', Rules::extension($contact->voiceExtension));
        }
        if ($contact->fax !== null && $contact->fax !== '') {
            $command->add($command->object, 'contact', 'fax', Rules::phone($contact->fax));
        }
        $command->add($command->object, 'contact', 'email', Rules::email($contact->email));
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

    /**
     * A postalInfo (name and org only on create: never changed by an update, grepp.md §0). The Latin one ASCII only; the
     * Greek and the Latin one with street, city, region and postcode; 1 to 3 street lines, never cut (review 2, 3 of #186).
     */
    public static function postal(Builder $command, DOMElement $parent, PostalInfo $postal, bool $withName): void
    {
        if (!in_array($postal->type, ['loc', 'int'], true)) {
            throw new \InvalidArgumentException("A postal info is «loc» or «int», not {$postal->type}.");
        }
        $check = fn (string $text, string $field): string => $postal->type === 'int' ? Rules::ascii($text, $field) : Rules::text($text, $field);
        $required = fn (?string $value, string $field): string => trim((string) $value) !== '' ? $check(trim((string) $value), $field)
            : throw new \InvalidArgumentException("The {$postal->type} address needs its {$field}.");
        $streets = array_values(array_filter(array_map('trim', $postal->street), fn ($line) => $line !== ''));
        if ($streets === [] || count($streets) > 3) {
            throw new \InvalidArgumentException('An address has 1 to 3 street lines.');
        }
        if (preg_match('/^[A-Za-z]{2}$/', (string) $postal->country) !== 1) {
            throw new \InvalidArgumentException('The country is a two-letter code.');
        }
        $info = $command->add($parent, 'contact', 'postalInfo');
        $info->setAttribute('type', $postal->type);
        if ($withName) {
            $command->add($info, 'contact', 'name', $required($postal->name, 'name'));
            if ($postal->org !== null && trim($postal->org) !== '') {
                $command->add($info, 'contact', 'org', $check(trim($postal->org), 'organisation'));
            }
        }
        $addr = $command->add($info, 'contact', 'addr');
        foreach ($streets as $line) {
            $command->add($addr, 'contact', 'street', $check($line, 'street'));
        }
        $command->add($addr, 'contact', 'city', $required($postal->city, 'city'));
        // Region and postcode in both scripts (the guide for «loc»; the registry refuses an «int» without them too:
        // adversarial ADV-2 of #186).
        $command->add($addr, 'contact', 'sp', $required($postal->province, 'region'));
        $postcode = $required($postal->postcode, 'postcode');
        if (mb_strlen($postcode) > 16) {
            throw new \InvalidArgumentException('A postcode is at most 16 characters.');
        }
        $command->add($addr, 'contact', 'pc', $postcode);
        $command->add($addr, 'contact', 'cc', strtolower((string) $postal->country));
    }
}
