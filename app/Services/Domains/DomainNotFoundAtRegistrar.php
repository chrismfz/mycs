<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * The registrar account does NOT hold this domain (an empty by-name/by-id
 * answer) — distinct from a transport/auth failure so callers can tell
 * «genuinely not ours» (register may proceed / a taken name is a third
 * party's) from «could not read» (abort, never act blind).
 */
class DomainNotFoundAtRegistrar extends RuntimeException {}
