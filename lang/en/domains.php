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
        'owner_change_not_here' => 'The registrar does not allow the owner to be changed here (it may be a paid trade): not done.',
        'registrant_not_linked' => 'The registrant here is not linked to the registrar\'s (Sync now says why): link it before changing the other contacts.',
        'nothing_to_link' => 'This role has no handle at the registrar to link, or it is linked already.',
        'not_at_the_registrar' => 'The registrar has no contact :handle.',
        'handle_changed' => 'The registrar no longer has :handle for this role: Sync now, then link.',
        'role_not_linked' => 'The :role here is not linked to the registrar\'s: link it first (Link here).',
        'busy' => 'Another change to :domain is under way: try again in a moment.',
    ],
    // Invoice lines (D5b): what the customer reads.
    'invoice' => [
        'renewal' => ':domain: domain renewal, :years year (:start - :end)|:domain: domain renewal, :years years (:start - :end)',
        'grace_fee' => ':domain: late renewal fee (grace period)',
        'redemption_fee' => ':domain: restore fee (redemption period)',
    ],
    'renew' => [
        'not_renewable' => ':domain cannot be renewed now: it has no expiry or has ended.',
        'no_price' => ':domain has no renew price for :years year(s): set one on its TLD or the domain.',
        'not_active_at_registrar' => 'The registrar has :domain as :status: renew it there by hand.',
    ],
];
