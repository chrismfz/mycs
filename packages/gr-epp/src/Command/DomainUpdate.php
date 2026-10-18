<?php

namespace MyCS\GrEpp\Command;

use MyCS\GrEpp\Data\Result;
use MyCS\GrEpp\Response;

/**
 * domain:update (the guide's examples 42, 43): nameservers and contacts added and removed (the new ones existing at the
 * registry already). Never empty (2002). The registrant changes only by OwnerChange (chargeable).
 */
final class DomainUpdate
{
    /**
     * @param  list<string>  $addNameservers
     * @param  list<string>  $removeNameservers
     * @param  array<string, list<string>>  $addContacts  role => ids
     * @param  array<string, list<string>>  $removeContacts  role => ids
     */
    public static function request(string $name, string $clTRID, array $addNameservers = [], array $removeNameservers = [],
        array $addContacts = [], array $removeContacts = []): string
    {
        Rules::notEmpty($addNameservers !== [] || $removeNameservers !== [] || array_filter($addContacts) !== [] || array_filter($removeContacts) !== []);
        $command = Builder::start('update', 'domain');
        $command->add($command->object, 'domain', 'name', $name);
        foreach (['add' => [$addNameservers, $addContacts], 'rem' => [$removeNameservers, $removeContacts]] as $op => [$hosts, $contacts]) {
            if ($hosts === [] && array_filter($contacts) === []) {
                continue;
            }
            $element = $command->add($command->object, 'domain', $op);
            if ($hosts !== []) {
                $ns = $command->add($element, 'domain', 'ns');
                foreach (array_values(array_unique(array_map('mb_strtolower', $hosts))) as $host) {
                    $command->add($ns, 'domain', 'hostObj', $host);
                }
            }
            foreach (['admin', 'tech', 'billing'] as $role) {
                foreach ($contacts[$role] ?? [] as $id) {
                    $command->add($element, 'domain', 'contact', Rules::contactId($id))->setAttribute('type', $role);
                }
            }
        }

        return $command->finish($clTRID);
    }

    public static function parse(Response $response): Result
    {
        return Results::of($response);
    }
}
