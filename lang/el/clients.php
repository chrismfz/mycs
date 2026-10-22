<?php

return [
    // Moving a service or domain to another client (App\Services\Clients\MoveToClient).
    'move' => [
        'same' => 'Ανήκει ήδη σε αυτόν τον πελάτη.',
        'subscription' => 'Πληρώνεται με συνδρομή του πελάτη του (κάρτα ή PayPal): ακυρώστε πρώτα τη συνδρομή.',
        'paid_in_part' => 'Τα τιμολόγιά του :invoices έχουν πληρωμή: τακτοποιήστε τα πρώτα (τα χρήματα είναι του πελάτη του).',
        'note_from_service' => 'Η υπηρεσία :item μεταφέρθηκε στον :client.',
        'note_to_service' => 'Η υπηρεσία :item μεταφέρθηκε εδώ από τον :client.',
        'note_from_domain' => 'Το domain :item μεταφέρθηκε στον :client.',
        'note_to_domain' => 'Το domain :item μεταφέρθηκε εδώ από τον :client.',
    ],
];
