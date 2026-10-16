<?php

use App\Services\Domains\Registrars\OpenproviderRegistrar;

/*
| Domains (docs/domains.md). A registrar is one class here; its credentials are never here: they are encrypted Settings
| of a registrar connection (D-21), entered in the admin. `secret` fields are stored encrypted and never shown again.
*/
return [
    'registrars' => [
        'openprovider' => OpenproviderRegistrar::class,
        // 'cnic' => CentralNic Reseller, only if its domains are not moved first (D-10)
        // 'grepp' => the .gr registry (EPP over HTTPS), myip (Phase 4)
    ],

    'registrar_fields' => [
        'openprovider' => [
            'username' => ['label' => 'Username', 'secret' => false],
            'password' => ['label' => 'Password', 'secret' => true],
        ],
    ],

    'registrar_labels' => [
        'manual' => 'Manual (no API)',
        'openprovider' => 'Openprovider',
    ],
];
