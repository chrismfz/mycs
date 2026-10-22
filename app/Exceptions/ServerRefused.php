<?php

namespace App\Exceptions;

use Exception;

/**
 * A server answered and refused, with its reason: the one failure whose reason the customer may read (cPanel's
 * WhmRefused, mailcow's errors). No answer (a timeout, an HTTP error) is not one: its outcome is not known.
 */
class ServerRefused extends Exception {}
