<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * Internal control-flow for the intent-match claim transaction: thrown when a
 * race stole candidate rows mid-claim, so the DB transaction rolls the claims
 * back atomically and the caller falls through to renew()'s sync-backed date
 * check. Never escapes DomainRenewalService.
 */
class InsufficientAdoptCoverage extends RuntimeException {}
