<?php

// EU VAT number checks (PR G, docs/eu-vat.md).
return [
    'status' => [
        'pending' => 'Not checked yet: VAT is charged',
        'valid' => 'Valid (VIES)',
        'invalid' => 'Not valid',
    ],
    'result' => [
        'valid' => 'VIES finds the VAT number valid.',
        'invalid' => 'VIES does not find the VAT number valid: VAT is charged.',
        'unavailable' => 'VIES did not answer. The status stays as it was and the number is checked again within the hour.',
        'none' => 'There is no VAT number to check.',
    ],
    'saved_pending' => 'Your EU VAT number will be checked with VIES. Until it is found valid, invoices include VAT.',
];
