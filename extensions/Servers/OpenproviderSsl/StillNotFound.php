<?php

namespace MyCS\Extensions\Servers\OpenproviderSsl;

use Exception;

/**
 * Openprovider was read and does not show an order or renewal asked for, for longer than it should: the one failure staff
 * may clear a request after. Any other (no answer, an error) never clears one (code review 1 of #350).
 */
class StillNotFound extends Exception {}
