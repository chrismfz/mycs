<?php

namespace App\Exceptions;

use Exception;

/**
 * A server action done as far as the module goes, its last step left to staff on purpose (Proxmox's «Terminate: stop and
 * leave to staff», owner 2026-10-24): not a failure. The service follows as for a done action, and the message is a task
 * in Pending Module Actions, as an offline machine's.
 */
class LeftToStaff extends Exception {}
