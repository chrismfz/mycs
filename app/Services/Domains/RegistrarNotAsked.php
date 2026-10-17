<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * A request that failed before it reached the registrar (its nameservers, glue, contacts or arguments): nothing was asked,
 * nothing charged, so it is not an unconfirmed charge (adversarial ADV-6 of #180). Unlike any other failure after the
 * request was sent, safe to send again once fixed.
 */
final class RegistrarNotAsked extends RuntimeException {}
