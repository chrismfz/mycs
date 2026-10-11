<?php

use App\Services\Domains\Registrars\OpenproviderRegistrar;

/*
| Domains (ported from Ekdosi). Credentials are NOT here: they live encrypted in
| domain_registrar_connections.config (admin UI in Phase 2).
*/
return [
    'registrars' => [
        'openprovider' => OpenproviderRegistrar::class,
        // 'cnic' => CNIC (CentralNic Reseller) — Phase 2
        // 'grepp' => .gr EPP — Phase 4
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
