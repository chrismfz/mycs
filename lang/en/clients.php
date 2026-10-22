<?php

return [
    // Moving a service or domain to another client (App\Services\Clients\MoveToClient).
    'move' => [
        'same' => 'It already belongs to this client.',
        'subscription' => 'It is paid by a subscription of its client (card or PayPal): cancel the subscription first.',
        'changed' => 'It was moved meanwhile: reload the page.',
        'cancellation' => 'Its client asked to cancel it: withdraw the cancellation first.',
        'draft_split' => 'Draft invoice :invoices bills other items too: finish or split it first.',
        'nothing' => 'Invoice :invoices would be left billing less than nothing, or move nothing: keep a discount with the lines it discounts.',
        'paid_in_part' => 'Its invoices :invoices have a payment: settle them first (the money is its client\'s).',
        'note_from_service' => 'Service :item moved to :client.',
        'note_to_service' => 'Service :item moved here from :client.',
        'note_from_domain' => 'Domain :item moved to :client.',
        'note_to_domain' => 'Domain :item moved here from :client.',
    ],
];
