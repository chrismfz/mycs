<?php

namespace App\Services\Ekdosi;

use InvalidArgumentException;

/** A field of a bridge request that is not what the contract says: a 400 naming the field, nothing else (review of #459). */
final class BadBridgeArgument extends InvalidArgumentException {}
