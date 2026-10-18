<?php

use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Domains\Registrars\OpenproviderRegistrar;

/*
| Domains (docs/domains.md). A registrar is one class here; its credentials are never here: they are encrypted Settings
| of a registrar connection (D-21), entered in the admin. `secret` fields are stored encrypted and never shown again.
*/
return [
    'registrars' => [
        'openprovider' => OpenproviderRegistrar::class,
        // 'cnic' => CentralNic Reseller, only if its domains are not moved first (D-10)
        'grepp' => GrEppRegistrar::class, // the .gr registry (EPP over HTTPS, docs/domains/grepp.md)
    ],

    'registrar_fields' => [
        'openprovider' => [
            'username' => ['label' => 'Username', 'secret' => false],
            'password' => ['label' => 'Password', 'secret' => true],
        ],
        // One connection, both of the registry's accounts (grepp.md §0): EPP and the WHOIS API.
        'grepp' => [
            'username' => ['label' => 'EPP username', 'secret' => false],
            'password' => ['label' => 'EPP password', 'secret' => true],
            // The registrar's id at the registry (domain:clID), not the EPP user: «myip» for myip1 (live, 2026-10-18).
            'registrar_id' => ['label' => 'Registrar id (clID; empty: the username without its digits)', 'secret' => false],
            'contact_prefix' => ['label' => 'Contact prefix (e.g. c60)', 'secret' => false],
            'whois_username' => ['label' => 'WHOIS API username', 'secret' => false],
            'whois_password' => ['label' => 'WHOIS API password', 'secret' => true],
        ],
    ],

    'registrar_labels' => [
        'manual' => 'Manual (no API)',
        'openprovider' => 'Openprovider',
        'grepp' => '.gr registry (ICS-FORTH)',
    ],
];
