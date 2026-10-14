<?php

namespace App\Exceptions;

use Exception;

/** A create that must not run: another one of the same service is running, or the service is no longer pending. */
class CreateSkipped extends Exception {}
