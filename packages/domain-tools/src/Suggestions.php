<?php

namespace Nixpal\DomainTools;

/**
 * Narrows a registrar's name suggestions to what we can sell: valid names in a TLD we
 * sell, not one of the names already shown, without duplicates, at most $limit. The
 * TLD of a suggestion is everything after its first dot (suggestions have no dots in
 * the name part).
 */
final class Suggestions
{
    /**
     * @param  string[]  $names  suggested domain names
     * @param  string[]  $tlds  the TLDs we sell (with or without the dot)
     * @param  string[]  $exclude  names already in the main results
     * @return string[]
     */
    public static function filter(array $names, array $tlds, array $exclude, $limit)
    {
        $sold = [];
        foreach ($tlds as $tld) {
            $sold[strtolower(trim((string) $tld, ' .'))] = true;
        }
        $seen = array_fill_keys(array_map('strtolower', $exclude), true);

        $kept = [];
        foreach ($names as $name) {
            $name = DomainName::toAscii(strtolower(trim((string) $name, ' .')));
            if ($name === null || isset($seen[$name]) || !DomainName::validDomain($name)) {
                continue;
            }
            $tld = substr($name, strpos($name, '.') + 1);
            if (!isset($sold[$tld])) {
                continue;
            }
            $seen[$name] = true;
            $kept[] = $name;
            if (count($kept) >= $limit) {
                break;
            }
        }

        return $kept;
    }
}
