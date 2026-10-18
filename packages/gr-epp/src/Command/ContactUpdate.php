<?php

namespace MyCS\GrEpp\Command;

use MyCS\GrEpp\Data\ContactChanges;
use MyCS\GrEpp\Data\PostalInfo;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/**
 * contact:update (the guide's examples 15, 16): address, phone, fax, email of one of ours; never its name or company
 * (owner 2026-10-18), never an authInfo (2308).
 */
final class ContactUpdate
{
    public static function request(string $id, ContactChanges $changes, string $clTRID): string
    {
        $command = Builder::start('update', 'contact');
        $command->add($command->object, 'contact', 'id', Rules::contactId($id));
        $chg = $command->add($command->object, 'contact', 'chg');
        $any = false;
        foreach (['loc' => $changes->localAddress, 'int' => $changes->internationalAddress] as $type => $address) {
            if ($address !== null) {
                ContactCreate::postal($command, $chg, new PostalInfo($type, '', null, $address['street'], $address['city'],
                    $address['province'] ?? null, $address['postcode'] ?? null, $address['country']), withName: false);
                $any = true;
            }
        }
        if ($changes->voice !== null) {
            $voice = $command->add($chg, 'contact', 'voice', Rules::phone($changes->voice));
            if ($changes->voiceExtension !== null && $changes->voiceExtension !== '') {
                $voice->setAttribute('x', Rules::extension($changes->voiceExtension));
            }
            $any = true;
        }
        if ($changes->fax !== null) {
            $command->add($chg, 'contact', 'fax', $changes->fax === '' ? '' : Rules::phone($changes->fax));
            $any = true;
        }
        if ($changes->email !== null) {
            $command->add($chg, 'contact', 'email', Rules::email($changes->email));
            $any = true;
        }
        Rules::notEmpty($any);

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }
}
