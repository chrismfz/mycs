<?php

return [
    'domains' => 'Domains',
    'domain' => 'Domain',
    'status' => [
        'active' => 'Active',
        'pending_register' => 'Pending registration',
        'pending_transfer' => 'Pending transfer',
        'expired' => 'Expired',
        'grace' => 'Grace period',
        'redemption' => 'Redemption period',
        'transferred_away' => 'Transferred away',
        'cancelled' => 'Cancelled',
        'deleted' => 'Deleted',
    ],
    'role' => [
        'registrant' => 'Registrant',
        'admin' => 'Administrative',
        'tech' => 'Technical',
        'billing' => 'Billing',
    ],
    'contact_in_use' => 'This contact is the :role contact of :domain: choose another contact for it first.',
    'connection_in_use' => 'This registrar connection is used by TLDs or domains: move them to another first.',
    'client_has_domains' => 'This account has domains: they are kept with their history.',
];
