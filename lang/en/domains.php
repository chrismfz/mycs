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
    'contact_in_use' => 'This contact is the «:role» contact of :domain: choose another contact for it first.',
    'tld_in_use' => 'The TLD .:tld has domains: it is kept with them.',
    'connection_in_use' => 'This registrar connection is used by TLDs or domains: move them to another first.',
    'client_has_domains' => 'This account has domains: they are kept with their history.',
    'manage' => [
        'nameserver_count' => 'A domain needs 2 to 13 nameservers.',
        'nameserver_invalid' => '«:host» is not a host name.',
        'contact_not_the_accounts' => 'A contact of this domain must be one of its account\'s contacts.',
        'registrant_required' => 'A domain needs a registrant contact.',
        'no_epp_code' => 'The registrar gave no EPP code.',
        'not_manageable' => ':domain is not at a registrar with an API, or it has ended.',
        'busy' => 'Another change to :domain is under way: try again in a moment.',
    ],
];
