<?php

namespace App\Exceptions;

/**
 * The bank asks the customer to confirm a charge of their saved method (3-D Secure, Stripe «authentication_required»;
 * PR H): it is not retried on its own, the customer pays the invoice themselves.
 */
class ChargeNeedsAction extends DisplayException {}
