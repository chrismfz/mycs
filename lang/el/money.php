<?php

// Money trail words for staff (MT-1d): journal causes and invoice statuses.
return [
    'causes' => [
        'opening_balance' => 'αρχικό υπόλοιπο',
        'overpayment' => 'υπερπληρωμή',
        'credit_payment' => 'πληρωμή από πίστωση',
        'purchase' => 'αγορά πίστωσης',
        'purchase_refunded' => 'επιστροφή αγοράς πίστωσης',
        'transaction_deleted' => 'διαγραφή συναλλαγής',
        'cancellation' => 'ακύρωση',
        'staff_adjustment' => 'προσαρμογή από staff',
        'downgrade' => 'υποβάθμιση',
        'cycle_change' => 'αλλαγή κύκλου',
        'affiliate_reward' => 'αμοιβή συνεργάτη',
        'invoice_reduced' => 'μείωση τιμολογίου',
        'refund_to_credit' => 'επιστροφή σε πίστωση',
        'chargeback' => 'chargeback',
        'chargeback_won' => 'αναίρεση chargeback (κερδίσαμε το dispute)',
    ],
    'statuses' => [
        'draft' => 'πρόχειρο',
        'unpaid' => 'ανεξόφλητο',
        'pending' => 'ανεξόφλητο', // το όνομά του πριν τις 2026-10-13: παλιές γραμμές του audit
        'paid' => 'εξοφλημένο',
        'cancelled' => 'ακυρωμένο',
        'refunded' => 'επιστράφηκε',
    ],
    'outcomes' => [
        'foreign' => 'όχι από εδώ (για έλεγχο)',
        'received' => 'ελήφθη',
        'recorded' => 'καταγράφηκε',
        'ignored' => 'αγνοήθηκε',
        'rejected' => 'απορρίφθηκε',
        'error' => 'σφάλμα',
    ],
];
