<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * The registrar answered a request with a refusal (4xx): nothing was done, nothing charged. Any other failure after a
 * charge was asked (a 5xx, a gateway timeout, no answer) has an unknown outcome and is never asked again blindly
 * (D5b-2 code review 1, as the Blesta module's RenewalPlan).
 */
final class RegistrarRefused extends RuntimeException {}
