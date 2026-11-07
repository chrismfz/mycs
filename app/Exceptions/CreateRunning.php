<?php

namespace App\Exceptions;

/**
 * The create's lock is held: another create of the service runs, or one was killed (the worker's timeout) and its lock
 * outlives it. A job that meets it tries again later rather than ending as done (sweep PROVISIONING-2).
 */
class CreateRunning extends CreateSkipped {}
