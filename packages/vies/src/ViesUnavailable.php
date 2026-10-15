<?php

namespace MyCS\Vies;

/**
 * VIES gave no answer: unreachable, an HTTP error, or its own error in the body (SERVICE_UNAVAILABLE, MS_UNAVAILABLE,
 * TIMEOUT, MS_MAX_CONCURRENT_REQ…). Never an answer about the number: the caller keeps what it knew and tries later.
 */
final class ViesUnavailable extends \RuntimeException {}
