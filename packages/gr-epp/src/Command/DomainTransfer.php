<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;
use SensitiveParameter;

/**
 * domain:transfer op=request (the guide's examples 61, 62; its FAQ «Διαδικασία αλλαγής καταχωρητή»): the name's
 * authorisation code and a registrant id: best a contact id of ours that does not exist yet, into which the registry
 * copies the registrant as it holds it (a contact of ours instead must carry the very same name). Approved at once
 * (serverApproved); the other registrar's contacts stay, for us to replace.
 */
final class DomainTransfer
{
    public static function request(string $name, #[SensitiveParameter] string $authCode, string $registrantId, string $clTRID): string
    {
        if (trim($authCode) === '') {
            throw new InvalidArgumentException('A transfer needs the name\'s authorisation code.');
        }
        $command = Builder::start('transfer', 'domain');
        $command->document->getElementsByTagNameNS('urn:ietf:params:xml:ns:epp-1.0', 'transfer')->item(0)?->setAttribute('op', 'request');
        $command->add($command->object, 'domain', 'name', Rules::name($name));
        $command->add($command->add($command->object, 'domain', 'authInfo'), 'domain', 'pw', $authCode);
        $command->add($command->extension('extdomain', 'transfer'), 'extdomain', 'registrantid', Rules::contactId($registrantId));

        return $command->finish($clTRID);
    }

    /**
     * Its status (serverApproved). Never its expiry: the registry's exDate here is the moment of the transfer (the guide's
     * example 62), so it is left out; read the expiry with domain:info (review 1 of #186: else a domain looks expired).
     */
    public static function parse(Response $response): Result
    {
        $result = Results::of($response, 'domain:trnData');

        return new Result($result->code, $result->name, $result->protocol, $result->created, null, null, $result->transferStatus,
            $result->comments, $result->svTRID);
    }
}
