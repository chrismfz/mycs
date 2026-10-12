<?php

namespace App\Enums;

/**
 * What a money event did (docs/money-trail.md §1). The amount is always positive; the kind gives the direction.
 */
enum MoneyEventKind: string
{
    /** Money landed on an invoice, from a gateway, staff or the customer's credit (source). */
    case Payment = 'payment';
    /** The customer's account credit went up. */
    case CreditIn = 'credit_in';
    /** The customer's account credit went down. */
    case CreditOut = 'credit_out';
    /** Money went back to the customer from a payment (through its gateway or recorded locally). */
    case Refund = 'refund';
    /** Undoes another event (reverses_id): the same amount in the opposite direction. */
    case Reversal = 'reversal';
}
