<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * A renewal for this domain is already running (the per-domain lock refused).
 * Distinct type so callers can advise correctly: the right reaction is «wait
 * and check the API history», NEVER «try renewing again» — the concurrent
 * renewal is (probably) charging the registrar right now.
 */
class DomainRenewalInProgress extends RuntimeException {}
