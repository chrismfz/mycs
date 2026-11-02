<?php

namespace App\Exceptions;

use RuntimeException;

/** A lifecycle job's reconcile was refused by the server: already given up as that action (SerializesLifecycle). */
class ReconcileFailed extends RuntimeException {}
