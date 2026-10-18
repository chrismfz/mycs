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
        Rules::roles($addContacts);
        Rules::roles($removeContacts);
        Rules::notEmpty($addNameservers !== [] || $removeNameservers !== [] || array_filter($addContacts) !== [] || array_filter($removeContacts) !== []);
        $addNameservers = Rules::nameservers($addNameservers);
        $removeNameservers = array_values(array_unique(array_map([Rules::class, 'name'], $removeNameservers)));
        Rules::contactCount($addContacts);
        // Never the same nameserver or contact added and removed at once (adversarial ADV-9 of #186).
        if (array_intersect($addNameservers, $removeNameservers) !== []) {
            throw new \InvalidArgumentException('A nameserver both added and removed: ' . implode(', ', array_intersect($addNameservers, $removeNameservers)));
        }
        foreach (['admin', 'tech', 'billing'] as $role) {
            $addContacts[$role] = array_values(array_unique($addContacts[$role] ?? []));
            $removeContacts[$role] = array_values(array_unique($removeContacts[$role] ?? []));
            if (array_intersect($addContacts[$role], $removeContacts[$role]) !== []) {
                throw new \InvalidArgumentException("A {$role} contact both added and removed.");
            }
        }
        $command = Builder::start('update', 'domain');
        $command->add($command->object, 'domain', 'name', Rules::name($name));
        foreach (['add' => [$addNameservers, $addContacts], 'rem' => [$removeNameservers, $removeContacts]] as $op => [$hosts, $contacts]) {
            if ($hosts === [] && array_filter($contacts) === []) {
                continue;
            }
            $element = $command->add($command->object, 'domain', $op);
            if ($hosts !== []) {
                $ns = $command->add($element, 'domain', 'ns');
                foreach ($hosts as $host) {
                    $command->add($ns, 'domain', 'hostObj', $host);
                }
            }
            foreach (['admin', 'tech', 'billing'] as $role) {
                foreach ($contacts[$role] ?? [] as $id) {
                    // Ours added; any removed (another registrar's, after a transfer: review 5 of #186).
                    $command->add($element, 'domain', 'contact', $op === 'add' ? Rules::contactId($id) : Rules::anyContactId($id))->setAttribute('type', $role);
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
