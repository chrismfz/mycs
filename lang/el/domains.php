<?php

return [
    'domains' => 'Domains',
    'domain' => 'Domain',
    'status' => [
        'active' => 'Ενεργό',
        'pending_register' => 'Εκκρεμεί καταχώριση',
        'pending_transfer' => 'Εκκρεμεί μεταφορά',
        'expired' => 'Έληξε',
        'grace' => 'Περίοδος χάριτος (grace)',
        'redemption' => 'Περίοδος ανάκτησης (redemption)',
        'transferred_away' => 'Μεταφέρθηκε αλλού',
        'cancelled' => 'Ακυρώθηκε',
        'deleted' => 'Διαγράφηκε',
    ],
    'role' => [
        'registrant' => 'Δικαιούχος',
        'admin' => 'Διαχειριστική',
        'tech' => 'Τεχνική',
        'billing' => 'Χρέωσης',
    ],
    'contact_in_use' => 'Αυτή η επαφή είναι η επαφή «:role» του :domain: ορίστε πρώτα άλλη επαφή εκεί.',
    'tld_in_use' => 'Το TLD .:tld έχει domains: κρατιέται μαζί τους.',
    'connection_in_use' => 'Αυτή η σύνδεση registrar χρησιμοποιείται από TLDs ή domains: μεταφέρετέ τα πρώτα σε άλλη.',
    'client_has_domains' => 'Αυτός ο λογαριασμός έχει domains: κρατιούνται με το ιστορικό τους.',
];
