<?php

namespace App\Exceptions;

use Exception;

/**
 * The service has no server action to run (Σ1): its product has no server module, or the module has no such action
 * (DirectAdmin, Plesk, Virtfusion without an upgrade, audit SVC-17). Not a failure: nothing is recorded or retried.
 */
class NothingToDoOnServer extends Exception {}
