<?php

return [
    // Moving a service or domain to another client (App\Services\Clients\MoveToClient).
    'move' => [
        'same' => 'It already belongs to this client.',
        'subscription' => 'It is paid by a subscription of its client (card or PayPal): cancel the subscription first.',
        'paid_in_part' => 'Its invoices :invoices have a payment: settle them first (the money is its client\'s).',
        'note_from_service' => 'Service :item moved to :client.',
        'note_to_service' => 'Service :item moved here from :client.',
        'note_from_domain' => 'Domain :item moved to :client.',
        'note_to_domain' => 'Domain :item moved here from :client.',
    ],
];
