<?php

namespace App\Exceptions;

use RuntimeException;

/** A server is deleted while accounts still live on it. */
class ServerHasAccounts extends RuntimeException {}
