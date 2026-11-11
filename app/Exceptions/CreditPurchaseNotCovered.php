<?php

namespace App\Exceptions;

/**
 * A credit purchase asked to become paid while its payment does not cover the credit it buys (InvoiceObserver). Its own
 * class so that the one caller that goes on without it (InvoiceTransactionCreatedListener: the payment stays recorded,
 * the invoice open, staff told) catches this refusal and no other.
 */
class CreditPurchaseNotCovered extends DisplayException {}
