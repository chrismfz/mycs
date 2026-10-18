<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * A request sent whose answer never came or could not be read: it may have been done (D-17). Never undone here, never
 * sent again blindly: the registrar is read again instead (review 1 of #195).
 */
final class RegistrarOutcomeUnknown extends RuntimeException {}
