<?php

namespace MyCS\Extensions\Servers\CPanel;

use Exception;

/**
 * WHM (or cPanel through it) answered and refused, with its reason: the one failure whose reason the customer may read.
 * A failure with no answer (a timeout, an HTTP error) is not one: its outcome is not known (adversarial ADV-3, 4 of #318).
 */
class WhmRefused extends Exception {}
