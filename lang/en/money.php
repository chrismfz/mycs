<?php

// Money trail words for staff (MT-1d): journal causes and invoice statuses.
return [
    'causes' => [
        'opening_balance' => 'opening balance',
        'overpayment' => 'overpayment',
        'credit_payment' => 'credit payment',
        'purchase' => 'credit purchase',
        'purchase_refunded' => 'credit purchase refunded',
        'transaction_deleted' => 'transaction deleted',
        'cancellation' => 'cancellation',
        'staff_adjustment' => 'staff adjustment',
        'downgrade' => 'downgrade',
        'affiliate_reward' => 'affiliate reward',
        'invoice_reduced' => 'invoice reduced',
        'refund_to_credit' => 'refund to credit',
        'chargeback' => 'chargeback',
        'chargeback_won' => 'chargeback reversed (dispute won)',
    ],
    'statuses' => [
        'draft' => 'draft',
        'unpaid' => 'unpaid',
        'pending' => 'unpaid', // its name before 2026-10-13: older audit rows
        'paid' => 'paid',
        'cancelled' => 'cancelled',
        'refunded' => 'refunded',
    ],
    'outcomes' => [
        'received' => 'received',
        'recorded' => 'recorded',
        'ignored' => 'ignored',
        'rejected' => 'rejected',
        'error' => 'error',
    ],
];
