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
    'manage' => [
        'nameserver_count' => 'Ένα domain θέλει 2 έως 13 nameservers.',
        'nameserver_invalid' => 'Το «:host» δεν είναι όνομα host.',
        'contact_not_the_accounts' => 'Μια επαφή του domain πρέπει να είναι επαφή του λογαριασμού του.',
        'registrant_required' => 'Ένα domain θέλει επαφή registrant.',
        'no_epp_code' => 'Ο registrar δεν έδωσε κωδικό EPP.',
        'not_manageable' => 'Το :domain δεν είναι σε registrar με API, ή έχει τελειώσει.',
        'owner_change_not_here' => 'Ο registrar δεν επιτρέπει αλλαγή κατόχου από εδώ (μπορεί να είναι trade με χρέωση): δεν έγινε.',
        'registrant_not_linked' => 'Ο registrant εδώ δεν είναι συνδεδεμένος με του registrar (το «Sync now» λέει γιατί): συνδέστε τον πριν αλλάξετε τις άλλες επαφές.',
        'nothing_to_link' => 'Ο ρόλος δεν έχει handle στον registrar για σύνδεση, ή είναι ήδη συνδεδεμένος.',
        'not_at_the_registrar' => 'Ο registrar δεν έχει επαφή :handle.',
        'handle_changed' => 'Ο registrar δεν έχει πια το :handle σε αυτόν τον ρόλο: «Sync now» και μετά σύνδεση.',
        'role_not_linked' => 'Ο ρόλος :role εδώ δεν είναι συνδεδεμένος με του registrar: συνδέστε τον πρώτα («Link here»).',
        'busy' => 'Γίνεται ήδη άλλη αλλαγή στο :domain: δοκιμάστε ξανά σε λίγο.',
    ],
    // Invoice lines (D5b): what the customer reads.
    'invoice' => [
        'renewal' => 'Ανανέωση domain :domain, :years έτος (:start - :end)|Ανανέωση domain :domain, :years έτη (:start - :end)',
        'grace_fee' => 'Τέλος καθυστερημένης ανανέωσης :domain (περίοδος grace)',
        'redemption_fee' => 'Τέλος επαναφοράς :domain (περίοδος redemption)',
    ],
    'renew' => [
        'not_renewable' => 'Το :domain δεν ανανεώνεται τώρα: δεν έχει λήξη ή έχει τελειώσει.',
        'no_price' => 'Το :domain δεν έχει τιμή ανανέωσης για :years έτη: ορίστε μία στο TLD ή στο domain.',
        'not_active_at_registrar' => 'Ο registrar έχει το :domain ως :status: ανανεώστε το εκεί με το χέρι.',
    ],
];
