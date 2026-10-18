<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;
use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;
use SensitiveParameter;

/**
 * domain:create (the guide's examples 37, 38, 40, 41): a registration (even years 2–10; nameservers and contacts that
 * exist at the registry already; an empty <pw/>), answered 1001 «pending» with its protocol (keep it: its recall within
 * 5 days needs it); or the activation of a reserved form of a name we hold (.ελ, homographs: record «dname» with the
 * held name's authorisation code; chargeable).
 */
final class DomainCreate
{
    /**
     * @param  list<string>  $nameservers
     * @param  array<string, list<string>>  $contacts  admin, tech, billing => contact ids
     */
    public static function request(string $name, int $years, array $nameservers, string $registrant, array $contacts, string $clTRID): string
    {
        $command = Builder::start('create', 'domain');
        $command->add($command->object, 'domain', 'name', $name);
        $command->add($command->object, 'domain', 'period', (string) Rules::years($years))->setAttribute('unit', 'y');
        if ($nameservers !== []) {
            $ns = $command->add($command->object, 'domain', 'ns');
            foreach (array_values(array_unique(array_map('mb_strtolower', $nameservers))) as $host) {
                $command->add($ns, 'domain', 'hostObj', $host);
            }
        }
        $command->add($command->object, 'domain', 'registrant', Rules::contactId($registrant));
        foreach (['admin', 'tech', 'billing'] as $role) {
            foreach ($contacts[$role] ?? [] as $id) {
                $command->add($command->object, 'domain', 'contact', Rules::contactId($id))->setAttribute('type', $role);
            }
        }
        $command->add($command->add($command->object, 'domain', 'authInfo'), 'domain', 'pw');

        return $command->finish($clTRID);
    }

    /** A reserved form of a name we hold (the guide's example 40): «dname», with the held name's authorisation code. */
    public static function activateForm(string $form, #[SensitiveParameter] string $heldAuthCode, string $clTRID): string
    {
        if (trim($heldAuthCode) === '') {
            throw new InvalidArgumentException('Activating a reserved form needs the authorisation code of the name held.');
        }
        $command = Builder::start('create', 'domain');
        $command->add($command->object, 'domain', 'name', $form);
        $command->add($command->add($command->object, 'domain', 'authInfo'), 'domain', 'pw', $heldAuthCode);
        $command->add($command->extension('extdomain', 'create'), 'extdomain', 'record', 'dname');

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response, 'domain:creData');
    }
}
