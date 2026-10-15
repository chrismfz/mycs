<?php

namespace App\Exceptions;

use RuntimeException;

/** A new account has no server to go to: its product's servers are all full or disabled (sweep §7). */
class NoServerWithRoom extends RuntimeException {}
